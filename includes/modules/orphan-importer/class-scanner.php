<?php
/**
 * Image Kit — Orphan Importer scanner.
 *
 * Finds image files in the uploads directory that are not in the media library
 * and imports them as attachments. Scan is split into two phases so the
 * comparison loop is cancellable:
 *
 *   - init_scan()         : one heavy uncancellable AJAX. Walks uploads, builds
 *                           candidate list + known-files lookup, caches in a
 *                           transient keyed by a token.
 *   - scan_orphans_batch(): repeated cancellable AJAX calls. Each processes a
 *                           slice of the candidate list. The final call also
 *                           runs variant grouping and cleans up the transient.
 */

defined( 'ABSPATH' ) || exit;

class Image_Kit_Orphan_Importer_Scanner {

	const TRANSIENT_PREFIX = 'ik_orphan_scan_';
	const TRANSIENT_TTL    = HOUR_IN_SECONDS;

	private $upload_dir;

	private static $excluded_dirs = array( 'ShortpixelBackups' );

	private static $image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif' );

	public function __construct() {
		$this->upload_dir = wp_upload_dir();
	}

	/**
	 * Phase 1: walk the uploads tree, build candidate list + known-files map,
	 * cache them under a fresh token. Returns [ token, total ].
	 *
	 * @return array { token: string, total: int }
	 */
	public function init_scan(): array {
		$basedir  = $this->upload_dir['basedir'];
		$excluded = apply_filters( 'image_kit_excluded_directories', self::$excluded_dirs );

		$known_files = $this->build_known_files();
		$candidates  = $this->collect_candidate_paths( $basedir, $excluded );

		$token = wp_generate_password( 16, false );
		set_transient(
			self::TRANSIENT_PREFIX . $token,
			array(
				'candidates'  => $candidates,
				'known_files' => $known_files,
				'orphans'     => array(),
			),
			self::TRANSIENT_TTL
		);

		return array(
			'token' => $token,
			'total' => count( $candidates ),
		);
	}

	/**
	 * Phase 2: process a slice of candidate files against the known-files map.
	 * Accumulates orphans in the transient. On the final batch, runs variant
	 * grouping and returns the grouped result, then deletes the transient.
	 *
	 * @param string $token
	 * @param int    $offset
	 * @param int    $batch_size
	 * @return array { offset, total, done, items? }
	 */
	public function scan_orphans_batch( string $token, int $offset, int $batch_size ): array {
		$state = get_transient( self::TRANSIENT_PREFIX . $token );
		if ( ! is_array( $state ) || ! isset( $state['candidates'], $state['known_files'], $state['orphans'] ) ) {
			return array(
				'error' => __( 'Scan token expired. Please start a new scan.', 'image-kit' ),
			);
		}

		$basedir     = $this->upload_dir['basedir'];
		$candidates  = $state['candidates'];
		$known_files = $state['known_files'];
		$orphans     = $state['orphans'];
		$total       = count( $candidates );

		$end = min( $offset + $batch_size, $total );
		for ( $i = $offset; $i < $end; $i++ ) {
			$relative_path = $candidates[ $i ];

			$dir      = dirname( $relative_path );
			$basename = wp_basename( $relative_path );
			if ( '.' === $dir ) {
				$dir = '';
			}

			if ( isset( $known_files[ $dir ][ $basename ] ) ) {
				continue;
			}

			$absolute_path = $basedir . '/' . $relative_path;
			$file_size     = file_exists( $absolute_path ) ? filesize( $absolute_path ) : 0;

			$orphans[ $relative_path ] = array(
				'relative_path' => $relative_path,
				'filename'      => $basename,
				'directory'     => $dir,
				'file_size'     => (int) $file_size,
			);
		}

		$state['orphans'] = $orphans;
		$new_offset       = $end;
		$done             = $new_offset >= $total;

		if ( $done ) {
			delete_transient( self::TRANSIENT_PREFIX . $token );
			return array(
				'offset' => $new_offset,
				'total'  => $total,
				'done'   => true,
				'items'  => $this->group_orphan_variants( $orphans ),
			);
		}

		set_transient( self::TRANSIENT_PREFIX . $token, $state, self::TRANSIENT_TTL );

		return array(
			'offset' => $new_offset,
			'total'  => $total,
			'done'   => false,
		);
	}

	/**
	 * Walk the uploads tree and collect every candidate file path that passes
	 * the excluded-dirs + image-extension filter. Sorted alphabetically for
	 * deterministic batching.
	 *
	 * @param string   $basedir
	 * @param string[] $excluded
	 * @return string[] Relative paths.
	 */
	private function collect_candidate_paths( string $basedir, array $excluded ): array {
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $basedir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
		} catch ( \Exception $e ) {
			return array();
		}

		$candidates = array();

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info->isFile() ) {
				continue;
			}

			$absolute_path = $file_info->getPathname();
			$relative_path = substr( $absolute_path, strlen( $basedir ) + 1 );

			$skip = false;
			foreach ( $excluded as $exc ) {
				if ( str_starts_with( $relative_path, $exc . '/' ) || $relative_path === $exc ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}

			$ext = strtolower( pathinfo( $relative_path, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, self::$image_extensions, true ) ) {
				continue;
			}

			$candidates[] = $relative_path;
		}

		sort( $candidates );
		return $candidates;
	}

	/**
	 * Build the known-files lookup from postmeta `_wp_attached_file` and
	 * `_wp_attachment_metadata` entries.
	 *
	 * @return array<string, array<string, true>> Map: directory => filename => true.
	 */
	private function build_known_files(): array {
		global $wpdb;

		$known_files = array();

		$attached_files = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'"
		);
		foreach ( $attached_files as $rel_path ) {
			$dir  = dirname( $rel_path );
			$file = wp_basename( $rel_path );
			if ( '.' === $dir ) {
				$dir = '';
			}
			$known_files[ $dir ][ $file ] = true;
		}

		$meta_rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata'"
		);
		foreach ( $meta_rows as $row ) {
			$meta = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$meta_dir = '';
			if ( ! empty( $meta['file'] ) ) {
				$d = dirname( $meta['file'] );
				if ( '.' !== $d ) {
					$meta_dir = $d;
				}
			}
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size_data ) {
					if ( ! empty( $size_data['file'] ) ) {
						$known_files[ $meta_dir ][ $size_data['file'] ] = true;
					}
				}
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$known_files[ $meta_dir ][ $meta['original_image'] ] = true;
			}
		}

		return $known_files;
	}

	private function group_orphan_variants( array $orphans ): array {
		$by_dir = array();
		foreach ( $orphans as $rel_path => $info ) {
			$by_dir[ $info['directory'] ][ $info['filename'] ] = $rel_path;
		}

		$variants_of = array();
		$is_variant  = array();

		foreach ( $orphans as $rel_path => $info ) {
			$stem = pathinfo( $info['filename'], PATHINFO_FILENAME );
			$ext  = pathinfo( $info['filename'], PATHINFO_EXTENSION );
			$original_stem = null;

			if ( preg_match( '/^(.+)-\d+x\d+$/', $stem, $m ) ) {
				$original_stem = $m[1];
			} elseif ( preg_match( '/^(.+)-(scaled|rotated)$/', $stem, $m ) ) {
				$original_stem = $m[1];
			} elseif ( preg_match( '/^(.+)-e\d{10,}$/', $stem, $m ) ) {
				$original_stem = $m[1];
			}

			if ( null === $original_stem ) {
				continue;
			}

			$original_basename = $original_stem . '.' . $ext;
			$dir               = $info['directory'];

			if ( isset( $by_dir[ $dir ][ $original_basename ] ) ) {
				$original_rel = $by_dir[ $dir ][ $original_basename ];
				$variants_of[ $original_rel ][] = $info['filename'];
				$is_variant[ $rel_path ] = true;
			}
		}

		$results = array();
		foreach ( $orphans as $rel_path => $info ) {
			if ( isset( $is_variant[ $rel_path ] ) ) {
				continue;
			}
			$info['variant_count'] = isset( $variants_of[ $rel_path ] ) ? count( $variants_of[ $rel_path ] ) : 0;
			$info['variant_files'] = isset( $variants_of[ $rel_path ] ) ? $variants_of[ $rel_path ] : array();
			$results[] = $info;
		}

		return $results;
	}

	/**
	 * Import an orphan file into the media library.
	 */
	public function import_orphan( string $relative_path ): array {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$basedir       = $this->upload_dir['basedir'];
		$absolute_path = $basedir . '/' . $relative_path;

		if ( ! Image_Kit_Core_File_Operations::validate_path_within( $absolute_path, $basedir ) ) {
			return array( 'success' => false, 'message' => __( 'Invalid file path.', 'image-kit' ) );
		}

		if ( ! file_exists( $absolute_path ) || ! is_readable( $absolute_path ) ) {
			return array( 'success' => false, 'message' => __( 'File not found or not readable.', 'image-kit' ) );
		}

		$filetype = wp_check_filetype( wp_basename( $relative_path ) );
		if ( empty( $filetype['type'] ) || 0 !== strpos( $filetype['type'], 'image/' ) ) {
			return array( 'success' => false, 'message' => __( 'Not a recognised image type.', 'image-kit' ) );
		}

		$filename      = wp_basename( $relative_path );
		$title         = pathinfo( $filename, PATHINFO_FILENAME );
		$attachment_id = wp_insert_attachment( array(
			'post_title'     => sanitize_text_field( $title ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_mime_type' => $filetype['type'],
		), $absolute_path );

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return array( 'success' => false, 'message' => __( 'Failed to create attachment post.', 'image-kit' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $absolute_path );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return array(
			'success'       => true,
			'message'       => __( 'Imported successfully.', 'image-kit' ),
			'attachment_id' => $attachment_id,
		);
	}
}
