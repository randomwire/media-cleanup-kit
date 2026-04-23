<?php
/**
 * Image Kit — Relocator scanner.
 *
 * Two features:
 * 1. Relocate: Move images from upload subdirectories to uploads root.
 * 2. Import Orphans: Find orphan files not in the media library and import them.
 */

defined( 'ABSPATH' ) || exit;

class Image_Kit_Relocator_Scanner {

	private $upload_dir;

	/**
	 * Directories to exclude from scanning.
	 *
	 * @var string[]
	 */
	private static $excluded_dirs = array( 'ShortpixelBackups' );

	private static $image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif' );

	public function __construct() {
		$this->upload_dir = wp_upload_dir();
	}

	// ── Relocate ──

	/**
	 * Scan for attachments in subdirectories of the uploads root.
	 */
	public function scan_attachments(): array {
		global $wpdb;

		$basedir = $this->upload_dir['basedir'];

		$sql = "SELECT post_id, meta_value FROM {$wpdb->postmeta}
				WHERE meta_key = '_wp_attached_file' AND meta_value LIKE '%/%'";

		$excluded = apply_filters( 'image_kit_excluded_directories', self::$excluded_dirs );
		foreach ( $excluded as $dir ) {
			$sql .= $wpdb->prepare( " AND meta_value NOT LIKE %s", $wpdb->esc_like( $dir ) . '/%' );
		}

		$rows = $wpdb->get_results( $sql );
		if ( ! $rows ) {
			return array();
		}

		$items = array();

		foreach ( $rows as $row ) {
			$attachment_id = (int) $row->post_id;
			$relative_path = $row->meta_value;
			$absolute_path = $basedir . '/' . $relative_path;

			if ( ! file_exists( $absolute_path ) ) {
				continue;
			}

			$mime = get_post_mime_type( $attachment_id );
			if ( ! $mime || 0 !== strpos( $mime, 'image/' ) ) {
				continue;
			}

			$filename     = wp_basename( $relative_path );
			$subdirectory = dirname( $relative_path );

			$target_filename = Image_Kit_Core_File_Operations::unique_filename_on_disk( $basedir, $filename );
			$has_collision   = ( $target_filename !== $filename );

			$meta        = wp_get_attachment_metadata( $attachment_id );
			$thumb_count = 0;
			$has_original_image = false;
			if ( is_array( $meta ) ) {
				if ( ! empty( $meta['sizes'] ) ) {
					$thumb_count = count( $meta['sizes'] );
				}
				if ( ! empty( $meta['original_image'] ) ) {
					$has_original_image = true;
				}
			}

			$search_fragment = $wpdb->esc_like( $subdirectory . '/' . $filename );
			$post_count      = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT ID) FROM {$wpdb->posts}
				 WHERE post_content LIKE %s AND post_type NOT IN ('revision', 'attachment')",
				'%' . $search_fragment . '%'
			) );

			$thumb_url = '';
			$thumb_src = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );
			if ( $thumb_src ) {
				$thumb_url = $thumb_src[0];
			}

			$items[] = array(
				'attachment_id'      => $attachment_id,
				'relative_path'      => $relative_path,
				'subdirectory'       => $subdirectory,
				'filename'           => $filename,
				'target_filename'    => $target_filename,
				'has_collision'      => $has_collision,
				'thumb_count'        => $thumb_count,
				'has_original_image' => $has_original_image,
				'post_count'         => $post_count,
				'thumb_url'          => $thumb_url,
			);
		}

		return $items;
	}

	/**
	 * Relocate a single attachment to the uploads root.
	 */
	public function relocate_attachment( int $attachment_id ): array {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$basedir = $this->upload_dir['basedir'];
		$baseurl = $this->upload_dir['baseurl'];

		$old_file = get_attached_file( $attachment_id );
		if ( ! $old_file || ! file_exists( $old_file ) ) {
			return array( 'success' => false, 'message' => __( 'Attached file not found on disk.', 'image-kit' ) );
		}

		$old_dir      = dirname( $old_file );
		$old_basename = wp_basename( $old_file );
		$old_relative = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$old_subdir   = dirname( $old_relative );

		if ( '.' === $old_subdir || '' === $old_subdir ) {
			return array( 'success' => false, 'message' => __( 'File is already in the uploads root.', 'image-kit' ) );
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		// Enumerate files.
		$old_stem      = pathinfo( $old_basename, PATHINFO_FILENAME );
		$files_to_move = array();
		$files_to_move[ $old_basename ] = $old_file;

		if ( ! empty( $meta['original_image'] ) ) {
			$orig_name = $meta['original_image'];
			$orig_path = $old_dir . '/' . $orig_name;
			if ( file_exists( $orig_path ) ) {
				$files_to_move[ $orig_name ] = $orig_path;
			}
		}

		if ( ! empty( $meta['sizes'] ) ) {
			$seen = array();
			foreach ( $meta['sizes'] as $size_data ) {
				$thumb_name = $size_data['file'];
				if ( isset( $seen[ $thumb_name ] ) ) {
					continue;
				}
				$seen[ $thumb_name ] = true;
				$thumb_path = $old_dir . '/' . $thumb_name;
				if ( file_exists( $thumb_path ) ) {
					$files_to_move[ $thumb_name ] = $thumb_path;
				}
			}
		}

		// Resolve target with collision handling.
		$target_basename = Image_Kit_Core_File_Operations::unique_filename_on_disk( $basedir, $old_basename );
		$new_stem        = pathinfo( $target_basename, PATHINFO_FILENAME );
		$base_stem       = preg_replace( '/-scaled$/', '', $old_stem );

		if ( $new_stem !== $old_stem ) {
			$collision_suffix = substr( $new_stem, strlen( $old_stem ) );
			$new_base_stem    = $base_stem . $collision_suffix;
		} else {
			$new_base_stem = $base_stem;
		}

		// Build filename map.
		$move_map = array();
		foreach ( $files_to_move as $old_name => $old_path ) {
			if ( $old_name === $old_basename ) {
				$move_map[ $old_name ] = $target_basename;
			} else {
				$file_stem = pathinfo( $old_name, PATHINFO_FILENAME );
				if ( str_starts_with( $file_stem, $old_stem ) ) {
					$move_map[ $old_name ] = $new_stem . substr( $old_name, strlen( $old_stem ) );
				} elseif ( str_starts_with( $file_stem, $base_stem ) ) {
					$move_map[ $old_name ] = $new_base_stem . substr( $old_name, strlen( $base_stem ) );
				} else {
					$move_map[ $old_name ] = $old_name;
				}
			}
		}

		// Pre-flight checks.
		if ( ! is_writable( $basedir ) ) {
			return array( 'success' => false, 'message' => __( 'Target directory is not writable.', 'image-kit' ) );
		}

		// Move files with rollback.
		$moved = array();
		foreach ( $files_to_move as $old_name => $old_path ) {
			$new_name = $move_map[ $old_name ];
			$new_path = $basedir . '/' . $new_name;

			if ( file_exists( $new_path ) ) {
				Image_Kit_Core_File_Operations::rollback_moves( array_map(
					function ( $old, $new ) { return array( 'from' => $old, 'to' => $new ); },
					array_values( $moved ),
					array_keys( $moved )
				) );
				return array( 'success' => false, 'message' => sprintf( __( 'Target file already exists: %s', 'image-kit' ), $new_name ) );
			}

			if ( @rename( $old_path, $new_path ) ) {
				$moved[ $new_path ] = $old_path;
			} elseif ( @copy( $old_path, $new_path ) && @unlink( $old_path ) ) {
				$moved[ $new_path ] = $old_path;
			} else {
				foreach ( $moved as $np => $op ) {
					@rename( $np, $op );
				}
				return array( 'success' => false, 'message' => sprintf( __( 'Failed to move file: %s', 'image-kit' ), $old_name ) );
			}
		}

		// Update metadata.
		update_attached_file( $attachment_id, $target_basename );

		if ( isset( $meta['file'] ) ) {
			$meta['file'] = $target_basename;
		}

		if ( ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size_name => &$size_data ) {
				$old_thumb = $size_data['file'];
				if ( isset( $move_map[ $old_thumb ] ) ) {
					$size_data['file'] = $move_map[ $old_thumb ];
				} else {
					$thumb_stem = pathinfo( $old_thumb, PATHINFO_FILENAME );
					if ( str_starts_with( $thumb_stem, $old_stem ) ) {
						$size_data['file'] = $new_stem . substr( $old_thumb, strlen( $old_stem ) );
					} elseif ( str_starts_with( $thumb_stem, $base_stem ) ) {
						$size_data['file'] = $new_base_stem . substr( $old_thumb, strlen( $base_stem ) );
					}
				}
			}
			unset( $size_data );
		}

		if ( ! empty( $meta['original_image'] ) ) {
			$old_orig = $meta['original_image'];
			if ( isset( $move_map[ $old_orig ] ) ) {
				$meta['original_image'] = $move_map[ $old_orig ];
			} else {
				$meta['original_image'] = $new_base_stem . substr( $old_orig, strlen( $base_stem ) );
			}
		}

		wp_update_attachment_metadata( $attachment_id, $meta );

		wp_update_post( array(
			'ID'   => $attachment_id,
			'guid' => $baseurl . '/' . $target_basename,
		) );

		$posts_updated = $this->update_post_references( $attachment_id, $old_subdir, $move_map );

		return array(
			'success' => true,
			'message' => __( 'Relocated successfully.', 'image-kit' ),
			'details' => array(
				'files_moved'   => count( $moved ),
				'posts_updated' => $posts_updated,
				'renamed'       => ( $old_basename !== $target_basename ),
				'new_filename'  => $target_basename,
			),
		);
	}

	private function update_post_references( int $attachment_id, string $old_subdir, array $move_map ): int {
		global $wpdb;

		$replacements = array();
		$old_base     = $old_subdir . '/';
		foreach ( $move_map as $old_name => $new_name ) {
			$replacements[ $old_base . $old_name ] = $new_name;
		}

		$old_main_name = array_key_first( $move_map );
		$old_stem      = pathinfo( $old_main_name, PATHINFO_FILENAME );
		$base_stem     = preg_replace( '/-scaled$/', '', $old_stem );
		$search_term   = $wpdb->esc_like( $old_subdir . '/' . $base_stem );

		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_content FROM {$wpdb->posts}
			 WHERE post_content LIKE %s AND post_type NOT IN ('revision', 'attachment')",
			'%' . $search_term . '%'
		) );

		$updated = 0;
		foreach ( $posts as $post ) {
			$fresh = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post->ID
			) );
			$new_content = str_replace( array_keys( $replacements ), array_values( $replacements ), $fresh );
			if ( $new_content !== $fresh ) {
				wp_update_post( array( 'ID' => $post->ID, 'post_content' => $new_content ) );
				$updated++;
			}
		}

		return $updated;
	}

	// ── Import Orphans ──

	/**
	 * Scan uploads for image files not in the media library.
	 */
	public function scan_orphan_files(): array {
		global $wpdb;

		$basedir = $this->upload_dir['basedir'];

		// Build known-files lookup.
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

		// Walk uploads.
		$orphans  = array();
		$excluded = apply_filters( 'image_kit_excluded_directories', self::$excluded_dirs );

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $basedir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
		} catch ( \Exception $e ) {
			return array();
		}

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

			$dir      = dirname( $relative_path );
			$basename = wp_basename( $relative_path );
			if ( '.' === $dir ) {
				$dir = '';
			}

			if ( isset( $known_files[ $dir ][ $basename ] ) ) {
				continue;
			}

			$orphans[ $relative_path ] = array(
				'relative_path' => $relative_path,
				'filename'      => $basename,
				'directory'     => $dir,
				'file_size'     => $file_info->getSize(),
			);
		}

		return $this->group_orphan_variants( $orphans );
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
