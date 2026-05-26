<?php
/**
 * Image Kit — Low Resolution apply / photo-match flow.
 *
 * Reads photo-match-results.csv from wp-content/uploads/matched-photos/,
 * applies high-res replacements to existing attachments (with backup), and
 * rewrites referencing Gutenberg image blocks to sizeSlug=full.
 */

defined( 'ABSPATH' ) || exit;

require_once IMAGE_KIT_PLUGIN_DIR . 'includes/core/class-thumbnail-regenerator.php';
require_once IMAGE_KIT_PLUGIN_DIR . 'includes/core/class-file-operations.php';

class Image_Kit_Low_Resolution_Apply {

	const MATCHED_DIR_NAME = 'matched-photos';
	const BACKUP_DIR_NAME  = 'image-kit-backup';
	const RESULTS_CSV      = 'photo-match-results.csv';

	/**
	 * Run all preflight checks before listing matches.
	 *
	 * @return array { ok: bool, errors: string[], matched_dir: string, csv_path: string, backup_dir: string }
	 */
	public function preflight(): array {
		$uploads     = wp_upload_dir();
		$basedir     = $uploads['basedir'];
		$matched_dir = $basedir . '/' . self::MATCHED_DIR_NAME;
		$csv_path    = $matched_dir . '/' . self::RESULTS_CSV;
		$backup_dir  = $basedir . '/' . self::BACKUP_DIR_NAME;

		$errors = array();

		if ( ! is_dir( $matched_dir ) ) {
			$errors[] = sprintf(
				/* translators: %s: relative path */
				__( 'Expected %s — upload the matched-photos folder from your Mac first (rsync command above).', 'media-cleanup-kit' ),
				'wp-content/uploads/' . self::MATCHED_DIR_NAME . '/'
			);
		} elseif ( ! is_readable( $matched_dir ) ) {
			$errors[] = sprintf(
				/* translators: %s: chmod command */
				__( 'wp-content/uploads/matched-photos/ exists but cannot be read by PHP. On the server, run: %s', 'media-cleanup-kit' ),
				'chmod -R 755 wp-content/uploads/matched-photos/'
			);
		} else {
			if ( ! file_exists( $csv_path ) ) {
				$errors[] = __( 'matched-photos/ is present but photo-match-results.csv is missing — re-run photo-match.py to produce it.', 'media-cleanup-kit' );
			} elseif ( ! is_readable( $csv_path ) ) {
				$errors[] = __( 'photo-match-results.csv exists but cannot be read — check file permissions (chmod 644).', 'media-cleanup-kit' );
			}
		}

		if ( ! is_dir( $backup_dir ) ) {
			if ( ! is_writable( $basedir ) ) {
				$errors[] = __( 'Cannot create wp-content/uploads/image-kit-backup/ — the uploads directory is not writable by the web server. Apply would fail to back up originals.', 'media-cleanup-kit' );
			}
		} elseif ( ! is_writable( $backup_dir ) ) {
			$errors[] = __( 'wp-content/uploads/image-kit-backup/ exists but is not writable. Apply would fail to back up originals.', 'media-cleanup-kit' );
		}

		return array(
			'ok'          => empty( $errors ),
			'errors'      => $errors,
			'matched_dir' => $matched_dir,
			'csv_path'    => $csv_path,
			'backup_dir'  => $backup_dir,
		);
	}

	/**
	 * Read photo-match-results.csv and return reviewable rows.
	 *
	 * @return array { ok: bool, errors: string[], items: array }
	 */
	public function scan_matched(): array {
		$pre = $this->preflight();
		if ( ! $pre['ok'] ) {
			return array( 'ok' => false, 'errors' => $pre['errors'], 'items' => array() );
		}

		$rows = $this->read_csv( $pre['csv_path'] );
		if ( false === $rows ) {
			return array(
				'ok'     => false,
				'errors' => array( __( 'photo-match-results.csv could not be parsed.', 'media-cleanup-kit' ) ),
				'items'  => array(),
			);
		}

		$items = array();
		foreach ( $rows as $row ) {
			$attachment_id      = isset( $row['attachment_id'] ) ? (int) $row['attachment_id'] : 0;
			$exported_filename  = isset( $row['exported_filename'] ) ? $row['exported_filename'] : '';
			$wp_filename        = isset( $row['wp_filename'] ) ? $row['wp_filename'] : '';
			$confidence         = isset( $row['match_confidence'] ) ? (float) $row['match_confidence'] : 0.0;

			if ( ! $attachment_id || ! $exported_filename ) {
				continue;
			}

			$replacement_path = $pre['matched_dir'] . '/' . $exported_filename;
			$replacement_ok   = file_exists( $replacement_path );

			$attachment    = get_post( $attachment_id );
			$attachment_ok = $attachment && 'attachment' === $attachment->post_type;
			$post_title    = $attachment_ok ? $attachment->post_title : '';

			$original_path = $attachment_ok ? get_attached_file( $attachment_id ) : '';
			$thumb_url     = $attachment_ok ? wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : '';

			$items[] = array(
				'attachment_id'        => $attachment_id,
				'attachment_exists'    => $attachment_ok,
				'post_title'           => $post_title,
				'original_filename'    => $wp_filename ?: ( $original_path ? wp_basename( $original_path ) : '' ),
				'replacement_filename' => $exported_filename,
				'replacement_exists'   => $replacement_ok,
				'confidence'           => $confidence,
				'original_thumb_url'   => $thumb_url,
				'edit_link'            => $attachment_ok ? get_edit_post_link( $attachment_id, 'raw' ) : '',
			);
		}

		return array( 'ok' => true, 'errors' => array(), 'items' => $items );
	}

	/**
	 * Apply a single match.
	 *
	 * @param int $attachment_id
	 * @return array { success: bool, message: string }
	 */
	public function apply_one( int $attachment_id ): array {
		$pre = $this->preflight();
		if ( ! $pre['ok'] ) {
			return array( 'success' => false, 'message' => implode( ' ', $pre['errors'] ) );
		}

		$rows = $this->read_csv( $pre['csv_path'] );
		if ( false === $rows ) {
			return array( 'success' => false, 'message' => __( 'photo-match-results.csv could not be parsed.', 'media-cleanup-kit' ) );
		}

		$row = null;
		foreach ( $rows as $r ) {
			if ( isset( $r['attachment_id'] ) && (int) $r['attachment_id'] === $attachment_id ) {
				$row = $r;
				break;
			}
		}
		if ( ! $row ) {
			return array( 'success' => false, 'message' => sprintf( __( 'No CSV row found for attachment #%d.', 'media-cleanup-kit' ), $attachment_id ) );
		}

		$replacement_path = $pre['matched_dir'] . '/' . $row['exported_filename'];

		// Per-row checks (plan §4).
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return array( 'success' => false, 'message' => sprintf( __( 'Attachment #%d no longer exists or is not an image.', 'media-cleanup-kit' ), $attachment_id ) );
		}
		if ( strpos( (string) $attachment->post_mime_type, 'image/' ) !== 0 ) {
			return array( 'success' => false, 'message' => sprintf( __( 'Attachment #%d is not an image (mime: %s).', 'media-cleanup-kit' ), $attachment_id, $attachment->post_mime_type ) );
		}

		$original_path = get_attached_file( $attachment_id );
		if ( ! $original_path || ! file_exists( $original_path ) ) {
			return array( 'success' => false, 'message' => sprintf( __( 'Original file not found on disk for attachment #%d — cannot back up safely, skipping.', 'media-cleanup-kit' ), $attachment_id ) );
		}

		$original_dir = dirname( $original_path );
		if ( ! is_writable( $original_dir ) ) {
			return array( 'success' => false, 'message' => sprintf( __( 'Cannot write to %s — replacement aborted to avoid corrupting metadata.', 'media-cleanup-kit' ), $original_dir ) );
		}

		if ( ! file_exists( $replacement_path ) || ! is_readable( $replacement_path ) ) {
			return array( 'success' => false, 'message' => __( 'Replacement file missing or unreadable — did rsync finish?', 'media-cleanup-kit' ) );
		}

		// Backup guard: refuse if a backup already exists.
		$attach_backup_dir  = $pre['backup_dir'] . '/' . $attachment_id;
		$backup_target_file = $attach_backup_dir . '/' . wp_basename( $original_path );
		if ( file_exists( $backup_target_file ) ) {
			return array( 'success' => false, 'message' => sprintf( __( 'A backup already exists for attachment #%d — this row was likely already applied. Skipping to avoid overwriting the original backup.', 'media-cleanup-kit' ), $attachment_id ) );
		}

		if ( ! wp_mkdir_p( $attach_backup_dir ) ) {
			return array( 'success' => false, 'message' => sprintf( __( 'Could not create backup directory at %s.', 'media-cleanup-kit' ), $attach_backup_dir ) );
		}

		if ( ! copy( $original_path, $backup_target_file ) ) {
			return array( 'success' => false, 'message' => sprintf( __( 'Failed to back up original file to %s.', 'media-cleanup-kit' ), $backup_target_file ) );
		}

		// File swap + thumbnail regeneration.
		$regenerator = new Image_Kit_Core_Thumbnail_Regenerator();
		$result      = $regenerator->replace_file_in_place( $attachment_id, $replacement_path, false );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => sprintf( __( 'Replacement failed: %s', 'media-cleanup-kit' ), $result->get_error_message() ),
			);
		}

		// Rewrite Gutenberg blocks across every post that references this attachment.
		$posts_updated = $this->rewrite_referencing_blocks( $attachment_id );

		return array(
			'success'       => true,
			'message'       => __( 'Replaced.', 'media-cleanup-kit' ),
			'posts_updated' => $posts_updated,
		);
	}

	/**
	 * Walk every published post and update wp:image blocks that reference the
	 * given attachment ID: sizeSlug → 'full', src → full URL, href → full URL
	 * if it pointed at a size-suffixed variant, strip size-* CSS classes.
	 *
	 * @param int $attachment_id
	 * @return int Number of posts updated.
	 */
	private function rewrite_referencing_blocks( int $attachment_id ): int {
		$new_url = wp_get_attachment_url( $attachment_id );
		if ( ! $new_url ) {
			return 0;
		}

		global $wpdb;
		$like = '%"id":' . $attachment_id . '%';
		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_content FROM {$wpdb->posts}
			 WHERE post_status IN ('publish','draft','private','future','pending')
			   AND post_type NOT IN ('revision','attachment')
			   AND post_content LIKE %s",
			$like
		) );

		if ( ! $posts ) {
			return 0;
		}

		$updated_count = 0;
		$block_pattern = '#<!-- wp:image\s*(\{(?:[^{}]|\{[^{}]*\})*\})?\s*-->(.*?)<!-- /wp:image -->#s';

		foreach ( $posts as $post ) {
			$content     = $post->post_content;
			$new_content = preg_replace_callback( $block_pattern, function ( $m ) use ( $attachment_id, $new_url ) {
				$attrs_json = isset( $m[1] ) ? $m[1] : '';
				$inner_html = $m[2];
				$attrs      = $attrs_json ? json_decode( $attrs_json, true ) : array();
				if ( ! is_array( $attrs ) ) {
					$attrs = array();
				}

				if ( ! isset( $attrs['id'] ) || (int) $attrs['id'] !== $attachment_id ) {
					return $m[0];
				}

				$attrs['sizeSlug'] = 'full';
				unset( $attrs['width'], $attrs['height'] );

				$new_inner = $this->rewrite_img_html( $inner_html, $attachment_id, $new_url );

				$new_attrs_json = $attrs ? wp_json_encode( $attrs ) : '';
				$head           = $new_attrs_json ? '<!-- wp:image ' . $new_attrs_json . ' -->' : '<!-- wp:image -->';
				return $head . $new_inner . '<!-- /wp:image -->';
			}, $content );

			if ( null !== $new_content && $new_content !== $content ) {
				wp_update_post( array( 'ID' => $post->ID, 'post_content' => $new_content ) );
				$updated_count++;
			}
		}

		return $updated_count;
	}

	/**
	 * Rewrite the inner HTML of a wp:image block:
	 * - <img src> → $new_url
	 * - <a href> → $new_url if href looked like a size-suffixed version of the same attachment
	 * - Drop size-* CSS classes on the <img>
	 */
	private function rewrite_img_html( string $html, int $attachment_id, string $new_url ): string {
		// Update <img> src and strip size-* classes.
		$html = preg_replace_callback( '#<img\s[^>]*>#i', function ( $m ) use ( $new_url ) {
			$tag = $m[0];
			$tag = preg_replace( '#(\ssrc=)"[^"]*"#i', '$1"' . esc_url( $new_url ) . '"', $tag );
			$tag = preg_replace_callback( '#(\sclass=)"([^"]*)"#i', function ( $cm ) {
				$classes = preg_replace( '/\bsize-[A-Za-z0-9_\-]+\b/', '', $cm[2] );
				$classes = trim( preg_replace( '/\s+/', ' ', $classes ) );
				return $cm[1] . '"' . $classes . '"';
			}, $tag );
			// Strip width/height attributes since the new file's dimensions differ.
			$tag = preg_replace( '#\swidth="[^"]*"#i', '', $tag );
			$tag = preg_replace( '#\sheight="[^"]*"#i', '', $tag );
			return $tag;
		}, $html );

		// Update <a href> if it looks like a size-suffixed version of the same attachment.
		$base_url    = wp_get_attachment_url( $attachment_id );
		$base_no_ext = $base_url ? preg_replace( '/\.[A-Za-z0-9]+$/', '', $base_url ) : '';

		if ( $base_no_ext ) {
			$html = preg_replace_callback( '#<a\s[^>]*href="([^"]+)"[^>]*>#i', function ( $m ) use ( $base_no_ext, $new_url ) {
				$href = $m[1];
				// Match base + optional -WxH or -scaled + extension.
				if ( preg_match( '#^' . preg_quote( $base_no_ext, '#' ) . '(?:-\d+x\d+|-scaled)?\.[A-Za-z0-9]+$#', $href ) ) {
					return str_replace( 'href="' . $href . '"', 'href="' . esc_url( $new_url ) . '"', $m[0] );
				}
				return $m[0];
			}, $html );
		}

		return $html;
	}

	/**
	 * Delete the matched-photos directory (after confirming it's inside uploads/).
	 *
	 * @return array { success: bool, message: string, undeletable: string[] }
	 */
	public function cleanup_matched_dir(): array {
		$uploads     = wp_upload_dir();
		$basedir     = $uploads['basedir'];
		$matched_dir = $basedir . '/' . self::MATCHED_DIR_NAME;

		if ( ! is_dir( $matched_dir ) ) {
			return array( 'success' => true, 'message' => __( 'Already removed.', 'media-cleanup-kit' ), 'undeletable' => array() );
		}

		// Path containment check.
		$real_target = realpath( $matched_dir );
		$real_root   = realpath( $basedir );
		if ( ! $real_target || ! $real_root || strpos( $real_target, $real_root . DIRECTORY_SEPARATOR ) !== 0 ) {
			return array(
				'success'     => false,
				'message'     => __( 'Refusing to delete — target is outside wp-content/uploads/.', 'media-cleanup-kit' ),
				'undeletable' => array(),
			);
		}

		$undeletable = array();
		$this->recursive_delete( $real_target, $real_root, $undeletable );

		if ( ! empty( $undeletable ) ) {
			return array(
				'success'     => false,
				'message'     => sprintf( __( 'Some files could not be deleted. Check permissions and retry.', 'media-cleanup-kit' ) ),
				'undeletable' => $undeletable,
			);
		}

		return array(
			'success'     => true,
			'message'     => __( 'Removed matched-photos directory.', 'media-cleanup-kit' ),
			'undeletable' => array(),
		);
	}

	private function recursive_delete( string $path, string $root, array &$undeletable ): void {
		// Defence-in-depth: every recursion verifies containment.
		if ( strpos( $path, $root . DIRECTORY_SEPARATOR ) !== 0 && $path !== $root ) {
			$undeletable[] = $path;
			return;
		}

		if ( is_dir( $path ) && ! is_link( $path ) ) {
			$entries = scandir( $path );
			if ( false === $entries ) {
				$undeletable[] = $path;
				return;
			}
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$this->recursive_delete( $path . '/' . $entry, $root, $undeletable );
			}
			if ( ! @rmdir( $path ) ) {
				$undeletable[] = $path;
			}
			return;
		}

		wp_delete_file( $path );
		if ( file_exists( $path ) ) {
			$undeletable[] = $path;
		}
	}

	/**
	 * Parse a CSV file with a header row.
	 *
	 * @param string $path
	 * @return array|false
	 */
	private function read_csv( string $path ) {
		$fh = @fopen( $path, 'r' );
		if ( ! $fh ) {
			return false;
		}
		$header = fgetcsv( $fh );
		if ( ! is_array( $header ) ) {
			fclose( $fh );
			return false;
		}
		$rows = array();
		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			$rows[] = array_combine( $header, array_pad( $row, count( $header ), '' ) );
		}
		fclose( $fh );
		return $rows;
	}
}
