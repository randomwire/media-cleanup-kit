<?php
/**
 * Media Cleanup Kit — Replace Flickr Images apply / drop-directory flow.
 *
 * Reads files from wp-content/uploads/flickr-replacements/ (populated by the
 * user via rsync after running tools/flickr-fetch.py locally), matches each
 * file's Flickr photo_id prefix against existing attachments, and applies
 * the high-res replacement with backup + thumbnail regeneration + block
 * JSON cleanup.
 *
 * Mirrors the shape of Image_Kit_Low_Resolution_Apply but reads files
 * directly from a directory (no manifest CSV needed) and updates intrinsic
 * <img> dimensions because Flickr replacements are genuinely larger.
 */

defined( 'ABSPATH' ) || exit;

require_once IMAGE_KIT_PLUGIN_DIR . 'includes/core/class-thumbnail-regenerator.php';
require_once IMAGE_KIT_PLUGIN_DIR . 'includes/core/class-file-operations.php';

class Image_Kit_Flickr_Upgrader_Apply {

	const DROP_DIR_NAME   = 'flickr-replacements';
	const BACKUP_DIR_NAME = 'image-kit-backup';

	/**
	 * Filename prefix pattern: {photo_id}_…
	 */
	const PHOTO_ID_PATTERN = '/^(\d{5,})_/';

	public function preflight(): array {
		$uploads    = wp_upload_dir();
		$basedir    = $uploads['basedir'];
		$drop_dir   = $basedir . '/' . self::DROP_DIR_NAME;
		$backup_dir = $basedir . '/' . self::BACKUP_DIR_NAME;

		$errors = array();

		if ( ! is_dir( $drop_dir ) ) {
			$errors[] = sprintf(
				/* translators: %s: relative path */
				__( 'Expected %s — run flickr-fetch.py locally and rsync the output into this folder first (commands above).', 'media-cleanup-kit' ),
				'wp-content/uploads/' . self::DROP_DIR_NAME . '/'
			);
		} elseif ( ! is_readable( $drop_dir ) ) {
			$errors[] = sprintf(
				/* translators: %s: example chmod command */
				__( 'wp-content/uploads/flickr-replacements/ exists but cannot be read by PHP. On the server, run: %s', 'media-cleanup-kit' ),
				'chmod -R 755 wp-content/uploads/flickr-replacements/'
			);
		}

		if ( ! is_dir( $backup_dir ) ) {
			if ( ! is_writable( $basedir ) ) {
				$errors[] = __( 'Cannot create wp-content/uploads/image-kit-backup/ — the uploads directory is not writable by the web server. Apply would fail to back up originals.', 'media-cleanup-kit' );
			}
		} elseif ( ! is_writable( $backup_dir ) ) {
			$errors[] = __( 'wp-content/uploads/image-kit-backup/ exists but is not writable. Apply would fail to back up originals.', 'media-cleanup-kit' );
		}

		return array(
			'ok'         => empty( $errors ),
			'errors'     => $errors,
			'drop_dir'   => $drop_dir,
			'backup_dir' => $backup_dir,
		);
	}

	/**
	 * Scan the drop directory for replacement files, resolving each to an
	 * existing attachment by Flickr photo_id prefix.
	 *
	 * @return array { ok, errors, items }
	 */
	public function scan_drop(): array {
		$pre = $this->preflight();
		if ( ! $pre['ok'] ) {
			return array( 'ok' => false, 'errors' => $pre['errors'], 'items' => array() );
		}

		$entries = scandir( $pre['drop_dir'] );
		if ( false === $entries ) {
			return array(
				'ok'     => false,
				'errors' => array( __( 'Could not read flickr-replacements/ directory.', 'media-cleanup-kit' ) ),
				'items'  => array(),
			);
		}

		// Build photo_id → filename map (skip results CSV, dotfiles, and any
		// subdirectories the user may have rsynced in).
		$file_map = array();
		foreach ( $entries as $entry ) {
			if ( '' === $entry || '.' === $entry[0] ) {
				continue;
			}
			if ( 'flickr-fetch-results.csv' === $entry ) {
				continue;
			}
			if ( ! is_file( $pre['drop_dir'] . '/' . $entry ) ) {
				continue;
			}
			if ( preg_match( self::PHOTO_ID_PATTERN, $entry, $m ) ) {
				// First-write-wins on duplicates (rare).
				if ( ! isset( $file_map[ $m[1] ] ) ) {
					$file_map[ $m[1] ] = $entry;
				}
			}
		}

		$items = array();
		$seen  = array();

		foreach ( $file_map as $photo_id => $replacement_filename ) {
			// Find the attachment whose stored filename contains this photo_id.
			$attachment_id = $this->find_attachment_for_photo_id( $photo_id );

			$attachment    = $attachment_id ? get_post( $attachment_id ) : null;
			$attachment_ok = $attachment && 'attachment' === $attachment->post_type;
			$post_title    = $attachment_ok ? $attachment->post_title : '';
			$original_path = $attachment_ok ? get_attached_file( $attachment_id ) : '';
			$thumb_url     = $attachment_ok ? wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : '';

			// Skip duplicates pointing at the same attachment.
			if ( $attachment_id && isset( $seen[ $attachment_id ] ) ) {
				continue;
			}
			if ( $attachment_id ) {
				$seen[ $attachment_id ] = true;
			}

			$items[] = array(
				'attachment_id'        => (int) $attachment_id,
				'attachment_exists'    => (bool) $attachment_ok,
				'post_title'           => $post_title,
				'original_filename'    => $attachment_ok && $original_path ? wp_basename( $original_path ) : '',
				'replacement_filename' => $replacement_filename,
				'replacement_exists'   => true, // Listed because the file was found.
				'flickr_photo_id'      => $photo_id,
				'original_thumb_url'   => $thumb_url,
				'edit_link'            => $attachment_ok ? get_edit_post_link( $attachment_id, 'raw' ) : '',
			);
		}

		return array( 'ok' => true, 'errors' => array(), 'items' => $items );
	}

	/**
	 * Look up the attachment whose stored filename starts with the Flickr
	 * photo_id (covers all size suffixes the user may have had).
	 *
	 * @param string $photo_id
	 * @return int Attachment ID or 0.
	 */
	private function find_attachment_for_photo_id( string $photo_id ): int {
		global $wpdb;

		// Match either "year/month/{photo_id}_..." or "{photo_id}_..." in the root.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- LIKE on _wp_attached_file substring; not cacheable.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wp_attached_file'
				   AND ( meta_value LIKE %s OR meta_value LIKE %s )
				 LIMIT 1",
				'%/' . $wpdb->esc_like( $photo_id ) . '\\_%',
				$wpdb->esc_like( $photo_id ) . '\\_%'
			)
		);

		return $id ? (int) $id : 0;
	}

	/**
	 * Apply a single replacement.
	 *
	 * @param int $attachment_id
	 * @return array { success, message, posts_updated? }
	 */
	public function apply_one( int $attachment_id ): array {
		$pre = $this->preflight();
		if ( ! $pre['ok'] ) {
			return array( 'success' => false, 'message' => implode( ' ', $pre['errors'] ) );
		}

		// Resolve the replacement file via the attachment's stored Flickr photo_id.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return array( 'success' => false, 'message' => sprintf( __( 'Attachment #%d no longer exists.', 'media-cleanup-kit' ), $attachment_id ) );
		}
		if ( strpos( (string) $attachment->post_mime_type, 'image/' ) !== 0 ) {
			return array( 'success' => false, 'message' => sprintf( __( 'Attachment #%d is not an image (mime: %s).', 'media-cleanup-kit' ), $attachment_id, $attachment->post_mime_type ) );
		}

		$original_path = get_attached_file( $attachment_id );
		if ( ! $original_path || ! file_exists( $original_path ) ) {
			return array( 'success' => false, 'message' => sprintf( __( 'Original file not found on disk for attachment #%d — cannot back up safely, skipping.', 'media-cleanup-kit' ), $attachment_id ) );
		}

		$original_filename = wp_basename( $original_path );
		if ( ! preg_match( Image_Kit_Flickr_Upgrader_Scanner::FLICKR_PATTERN, $original_filename, $fm ) ) {
			return array( 'success' => false, 'message' => sprintf( __( 'Attachment #%d does not look like a Flickr image (filename: %s).', 'media-cleanup-kit' ), $attachment_id, $original_filename ) );
		}
		$photo_id = $fm[1];

		// Find a replacement file in the drop dir with the matching photo_id prefix.
		$replacement_filename = $this->find_replacement_file( $pre['drop_dir'], $photo_id );
		if ( ! $replacement_filename ) {
			return array( 'success' => false, 'message' => sprintf( __( 'No replacement file found for photo_id %s in flickr-replacements/.', 'media-cleanup-kit' ), $photo_id ) );
		}
		$replacement_path = $pre['drop_dir'] . '/' . $replacement_filename;
		if ( ! is_readable( $replacement_path ) ) {
			return array( 'success' => false, 'message' => sprintf( __( 'Replacement file %s is not readable.', 'media-cleanup-kit' ), $replacement_filename ) );
		}

		$original_dir = dirname( $original_path );
		if ( ! is_writable( $original_dir ) ) {
			return array( 'success' => false, 'message' => sprintf( __( 'Cannot write to %s — replacement aborted to avoid corrupting metadata.', 'media-cleanup-kit' ), $original_dir ) );
		}

		// Backup guard.
		$attach_backup_dir  = $pre['backup_dir'] . '/' . $attachment_id;
		$backup_target_file = $attach_backup_dir . '/' . $original_filename;
		if ( file_exists( $backup_target_file ) ) {
			return array( 'success' => false, 'message' => sprintf( __( 'A backup already exists for attachment #%d — this row was likely already applied. Skipping to avoid overwriting the original backup.', 'media-cleanup-kit' ), $attachment_id ) );
		}

		if ( ! wp_mkdir_p( $attach_backup_dir ) ) {
			return array( 'success' => false, 'message' => sprintf( __( 'Could not create backup directory at %s.', 'media-cleanup-kit' ), $attach_backup_dir ) );
		}

		if ( ! copy( $original_path, $backup_target_file ) ) {
			return array( 'success' => false, 'message' => sprintf( __( 'Failed to back up original file to %s.', 'media-cleanup-kit' ), $backup_target_file ) );
		}

		// File swap + thumbnail regeneration (shared core helper).
		// reduce_sizes=true: Flickr replacements are genuinely larger (e.g. _b → _o);
		// regenerating every registered size per row in a bulk loop locked up the
		// standalone Flickr Upgrader plugin and is why it kept this reduction on.
		// Sized variants regenerate on demand via wp_filter_content_tags().
		$regenerator = new Image_Kit_Core_Thumbnail_Regenerator();
		$result      = $regenerator->replace_file_in_place( $attachment_id, $replacement_path, true );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => sprintf( __( 'Replacement failed: %s', 'media-cleanup-kit' ), $result->get_error_message() ),
			);
		}

		// Rewrite Gutenberg blocks across every post that references this attachment.
		// Unlike low-res, we update the <img> intrinsic width/height because the
		// Flickr replacement is genuinely a different size.
		$posts_updated = $this->rewrite_referencing_blocks( $attachment_id );

		return array(
			'success'       => true,
			'message'       => __( 'Replaced.', 'media-cleanup-kit' ),
			'posts_updated' => $posts_updated,
		);
	}

	/**
	 * Find a file in the drop dir whose name starts with "{photo_id}_".
	 *
	 * @return string|null Filename (not full path), or null if not found.
	 */
	private function find_replacement_file( string $drop_dir, string $photo_id ): ?string {
		$entries = scandir( $drop_dir );
		if ( false === $entries ) {
			return null;
		}
		foreach ( $entries as $entry ) {
			if ( '' === $entry || '.' === $entry[0] ) {
				continue;
			}
			if ( 0 !== strpos( $entry, $photo_id . '_' ) ) {
				continue;
			}
			// Skip subdirectories — must be a real file we can copy().
			if ( ! is_file( $drop_dir . '/' . $entry ) ) {
				continue;
			}
			return $entry;
		}
		return null;
	}

	/**
	 * Walk every post that references the given attachment and update wp:image
	 * blocks: sizeSlug → 'full', src → new URL, width/height set to the new
	 * intrinsic dimensions, size-* class on <figure> normalised to size-full.
	 *
	 * @return int Number of posts updated.
	 */
	private function rewrite_referencing_blocks( int $attachment_id ): int {
		$new_url = wp_get_attachment_url( $attachment_id );
		if ( ! $new_url ) {
			return 0;
		}

		$meta       = wp_get_attachment_metadata( $attachment_id );
		$new_width  = is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$new_height = is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0;

		global $wpdb;
		$like = '%"id":' . $attachment_id . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- per-attachment content scan; no cache hit possible.
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
		$block_pattern = '#(<!-- wp:image\s*)(\{(?:[^{}]|\{[^{}]*\})*\})(\s*-->)(.*?)(<!-- /wp:image -->)#s';

		foreach ( $posts as $post ) {
			$content     = $post->post_content;
			$new_content = preg_replace_callback( $block_pattern, function ( $m ) use ( $attachment_id, $new_url, $new_width, $new_height ) {
				$attrs_json = $m[2];
				$inner_html = $m[4];
				$attrs      = json_decode( $attrs_json, true );

				if ( ! is_array( $attrs ) || ! isset( $attrs['id'] ) || (int) $attrs['id'] !== $attachment_id ) {
					return $m[0];
				}

				$attrs['sizeSlug'] = 'full';
				unset( $attrs['width'], $attrs['height'] );

				// Rewrite <img src>.
				$inner_html = preg_replace(
					'#(<img\s[^>]*\bsrc=")[^"]+(")#i',
					'$1' . esc_url( $new_url ) . '$2',
					$inner_html
				);

				// Update or insert width/height on the <img>.
				if ( $new_width && $new_height ) {
					$inner_html = $this->set_img_dimension( $inner_html, 'width',  $new_width );
					$inner_html = $this->set_img_dimension( $inner_html, 'height', $new_height );
				}

				// Normalise size-* class on <figure> only.
				$inner_html = preg_replace_callback(
					'#(<figure\b[^>]*\bclass="[^"]*)\bsize-[\w-]+#i',
					function ( $fm ) { return $fm[1] . 'size-full'; },
					$inner_html
				);

				$new_json = wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES );
				return $m[1] . $new_json . $m[3] . $inner_html . $m[5];
			}, $content );

			if ( null !== $new_content && $new_content !== $content ) {
				wp_update_post( array( 'ID' => $post->ID, 'post_content' => $new_content ) );
				$updated_count++;
			}
		}

		return $updated_count;
	}

	/**
	 * Set (or insert) a width/height attribute on the first <img> in $html.
	 */
	private function set_img_dimension( string $html, string $attr, int $value ): string {
		// Try to replace existing attribute.
		$replaced = preg_replace(
			'#(<img\s[^>]*)\b' . preg_quote( $attr, '#' ) . '="[^"]*"#i',
			'$1' . $attr . '="' . $value . '"',
			$html,
			1,
			$count
		);
		if ( $count > 0 ) {
			return $replaced;
		}
		// Otherwise insert just before the closing >.
		return preg_replace(
			'#(<img\s[^>]*?)(\s*/?>)#i',
			'$1 ' . $attr . '="' . $value . '"$2',
			$html,
			1
		);
	}

	/**
	 * Delete the drop directory after path-containment checks.
	 */
	public function cleanup_drop_dir(): array {
		$uploads  = wp_upload_dir();
		$basedir  = $uploads['basedir'];
		$drop_dir = $basedir . '/' . self::DROP_DIR_NAME;

		if ( ! is_dir( $drop_dir ) ) {
			return array( 'success' => true, 'message' => __( 'Already removed.', 'media-cleanup-kit' ), 'undeletable' => array() );
		}

		$real_target = realpath( $drop_dir );
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
				'message'     => __( 'Some files could not be deleted. Check permissions and retry.', 'media-cleanup-kit' ),
				'undeletable' => $undeletable,
			);
		}

		return array(
			'success'     => true,
			'message'     => __( 'Removed flickr-replacements directory.', 'media-cleanup-kit' ),
			'undeletable' => array(),
		);
	}

	private function recursive_delete( string $path, string $root, array &$undeletable ): void {
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
}
