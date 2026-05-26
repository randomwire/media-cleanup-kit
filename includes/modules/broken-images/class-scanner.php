<?php
/**
 * Image Kit — Broken Images scanner.
 *
 * Detects internal image references in post content where the
 * file is missing from the filesystem.
 */

defined( 'ABSPATH' ) || exit;

class Image_Kit_Broken_Images_Scanner {

	/**
	 * Count posts likely to contain image references.
	 */
	public function get_candidate_post_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type IN ('post', 'page')
			   AND post_status IN ('publish','draft','private','pending','future')
			   AND (
			       post_content LIKE '%<img%'
			    OR post_content LIKE '%wp:image%'
			    OR post_content LIKE '%wp:gallery%'
			    OR post_content LIKE '%wp:cover%'
			    OR post_content LIKE '%wp:media-text%'
			    OR EXISTS (
			    	SELECT 1 FROM {$wpdb->postmeta} pm
			    	WHERE pm.post_id = {$wpdb->posts}.ID
			    	  AND pm.meta_key = '_thumbnail_id'
			    	  AND CAST(pm.meta_value AS UNSIGNED) > 0
			    )
			   )"
		);
	}

	/**
	 * Fetch a batch of candidate posts.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Batch size.
	 * @return object[]
	 */
	public function get_post_batch( int $offset, int $limit ): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_content
			 FROM {$wpdb->posts}
			 WHERE post_type IN ('post', 'page')
			   AND post_status IN ('publish','draft','private','pending','future')
			   AND (
			       post_content LIKE '%%<img%%'
			    OR post_content LIKE '%%wp:image%%'
			    OR post_content LIKE '%%wp:gallery%%'
			    OR post_content LIKE '%%wp:cover%%'
			    OR post_content LIKE '%%wp:media-text%%'
			    OR EXISTS (
			    	SELECT 1 FROM {$wpdb->postmeta} pm
			    	WHERE pm.post_id = {$wpdb->posts}.ID
			    	  AND pm.meta_key = '_thumbnail_id'
			    	  AND CAST(pm.meta_value AS UNSIGNED) > 0
			    )
			   )
			 ORDER BY ID ASC
			 LIMIT %d OFFSET %d",
			$limit,
			$offset
		) );
	}

	/**
	 * Check a batch of posts for broken image references.
	 *
	 * @param object[] $posts Posts with ID, post_title, post_content.
	 * @return array Broken image entries.
	 */
	public function check_posts( array $posts ): array {
		$upload_dir          = wp_upload_dir();
		$uploads_baseurl     = $upload_dir['baseurl'];
		$uploads_basedir     = $upload_dir['basedir'];
		$base_url_normalized = preg_replace( '#^https?://#', '//', $uploads_baseurl );

		$parser = new Image_Kit_Core_Block_Parser();
		$broken = array();

		foreach ( $posts as $post ) {
			$urls = $parser->extract_image_urls( $post->post_content );

			foreach ( $urls as $url_info ) {
				$url = $url_info['url'];

				// Only check internal (uploads) URLs.
				$url_normalized = preg_replace( '#^https?://#', '//', $url );
				if ( strpos( $url_normalized, $base_url_normalized ) !== 0 ) {
					continue;
				}

				if ( ! $this->check_file_exists( $url, $base_url_normalized, $uploads_basedir ) ) {
					$relative = urldecode( ltrim( substr( $url_normalized, strlen( $base_url_normalized ) ), '/' ) );

					$broken[] = array(
						'post_id'       => (int) $post->ID,
						'post_title'    => $post->post_title,
						'edit_link'     => get_edit_post_link( $post->ID, 'raw' ) ?: '',
						'image_url'     => $url,
						'relative_path' => $relative,
						'block_type'    => $url_info['block_type'],
					);
				}
			}

			$featured_attachment_id = (int) get_post_thumbnail_id( (int) $post->ID );
			if ( $featured_attachment_id > 0 ) {
				$featured_url = wp_get_attachment_url( $featured_attachment_id );
				if ( $featured_url ) {
					$featured_url_normalized = preg_replace( '#^https?://#', '//', $featured_url );
					if ( strpos( $featured_url_normalized, $base_url_normalized ) === 0 ) {
						if ( ! $this->check_file_exists( $featured_url, $base_url_normalized, $uploads_basedir ) ) {
							$relative = urldecode( ltrim( substr( $featured_url_normalized, strlen( $base_url_normalized ) ), '/' ) );
							$broken[] = array(
								'post_id'       => (int) $post->ID,
								'post_title'    => $post->post_title,
								'edit_link'     => get_edit_post_link( $post->ID, 'raw' ) ?: '',
								'image_url'     => $featured_url,
								'relative_path' => $relative,
								'block_type'    => 'Featured Image',
							);
						}
					}
				}
			}
		}

		return $broken;
	}

	/**
	 * Check if an internal image URL's file exists on disk.
	 *
	 * @param string $url                 Image URL.
	 * @param string $base_url_normalized Protocol-relative uploads base URL.
	 * @param string $base_dir            Absolute uploads base directory.
	 * @return bool
	 */
	private function check_file_exists( string $url, string $base_url_normalized, string $base_dir ): bool {
		$url_normalized = preg_replace( '#^https?://#', '//', $url );
		$relative       = substr( $url_normalized, strlen( $base_url_normalized ) );
		$relative       = urldecode( ltrim( $relative, '/' ) );
		$filepath       = rtrim( $base_dir, '/' ) . '/' . $relative;

		return file_exists( $filepath );
	}

	/**
	 * Remove a broken image reference from a post.
	 *
	 * Behaviour by block type:
	 *   - "Featured Image"           → unset the post's `_thumbnail_id` if it
	 *                                  still resolves to the broken URL.
	 *   - "wp:image"                 → remove the entire `<!-- wp:image ... -->
	 *                                  ... <!-- /wp:image -->` block containing
	 *                                  the broken URL.
	 *   - other block types / raw   → strip the offending `<img src="URL">`
	 *     `<img>` tags                 tag (and a wrapping `<a href="URL">` if
	 *                                  any) from post_content.
	 *
	 * Before any mutation, the current `post_content` is snapshotted to
	 * `wp-content/uploads/image-kit-backup/posts/{post_id}-{timestamp}.html`.
	 *
	 * @param int    $post_id    Target post.
	 * @param string $image_url  The broken image URL (must match what was scanned).
	 * @param string $block_type Block-type label as returned by the scanner.
	 * @return array { success: bool, message?: string, backup_file?: string }
	 */
	public function remove_broken_from_post( int $post_id, string $image_url, string $block_type ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'success' => false, 'message' => __( 'Post not found.', 'image-kit' ) );
		}

		// ── Featured image ─────────────────────────────────────────────
		if ( 'Featured Image' === $block_type ) {
			$thumb_id = (int) get_post_thumbnail_id( $post_id );
			if ( ! $thumb_id ) {
				return array( 'success' => false, 'message' => __( 'No featured image set on this post.', 'image-kit' ) );
			}
			$featured_url = wp_get_attachment_url( $thumb_id );
			if ( ! $featured_url || ! $this->urls_match( $featured_url, $image_url ) ) {
				return array( 'success' => false, 'message' => __( 'Featured image URL no longer matches the scanned URL.', 'image-kit' ) );
			}

			$backup = $this->backup_post( $post_id, $post->post_content );
			delete_post_thumbnail( $post_id );

			return array(
				'success'     => true,
				'message'     => __( 'Featured image removed.', 'image-kit' ),
				'backup_file' => $backup,
			);
		}

		// ── Content-based reference ────────────────────────────────────
		$content     = $post->post_content;
		$new_content = $this->strip_image_from_content( $content, $image_url, $block_type );

		if ( $new_content === $content ) {
			return array( 'success' => false, 'message' => __( 'No matching markup found in post content (may have been edited since the scan).', 'image-kit' ) );
		}

		$backup = $this->backup_post( $post_id, $content );

		$update = wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => $new_content,
		), true );

		if ( is_wp_error( $update ) ) {
			return array(
				'success'     => false,
				'message'     => $update->get_error_message(),
				'backup_file' => $backup,
			);
		}

		return array(
			'success'     => true,
			'backup_file' => $backup,
		);
	}

	/**
	 * Remove a broken `<img>` reference from a content string.
	 *
	 * For `wp:image` block_type the whole block is removed (a wp:image block
	 * with a missing image renders as a broken figure). For other block types
	 * we strip just the `<img>` tag (and a wrapping `<a href="URL">` if any).
	 *
	 * @param string $content
	 * @param string $image_url
	 * @param string $block_type
	 * @return string Possibly-unchanged content.
	 */
	private function strip_image_from_content( string $content, string $image_url, string $block_type ): string {
		$escaped = preg_quote( $image_url, '#' );

		if ( 'wp:image' === $block_type ) {
			// Remove the wp:image block that contains this URL.
			$pattern = '#<!-- wp:image\s*(?:\{(?:[^{}]|\{[^{}]*\})*\})?\s*-->'
				. '(?:(?!<!-- /?wp:image).)*?'
				. $escaped
				. '(?:(?!<!-- /?wp:image).)*?'
				. '<!-- /wp:image -->\s*#s';
			$out = preg_replace( $pattern, '', $content, 1 );
			if ( null !== $out && $out !== $content ) {
				return $out;
			}
			// Fallthrough — fall back to img-tag removal if the block-level
			// pattern didn't match (defensive).
		}

		// Remove the surrounding <a href="URL">...<img src="URL">...</a> if the
		// anchor wraps just this image. We collapse the anchor pair into the
		// inner content before stripping the <img>.
		$anchor_wrap = '#<a\s[^>]*href=["\']' . $escaped . '["\'][^>]*>\s*(<img\s[^>]*src=["\']' . $escaped . '["\'][^>]*/?>)\s*</a>\s*#i';
		$content = preg_replace( $anchor_wrap, '$1', $content );

		// Strip the <img> tag (greedy on attrs, non-greedy across the tag).
		$img_pattern = '#<img\s[^>]*src=["\']' . $escaped . '["\'][^>]*/?>\s*#i';
		$out = preg_replace( $img_pattern, '', $content );

		return ( null === $out ) ? $content : $out;
	}

	/**
	 * Save the current post_content to a timestamped HTML file under
	 * wp-content/uploads/image-kit-backup/posts/ so the user has a manual
	 * undo if they want one.
	 *
	 * @return string|false Absolute backup file path on success.
	 */
	private function backup_post( int $post_id, string $content ) {
		$dir = wp_upload_dir()['basedir'] . '/image-kit-backup/posts';
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$filename = $post_id . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.html';
		$path     = $dir . '/' . $filename;
		$bytes    = file_put_contents( $path, $content );
		return ( false === $bytes ) ? false : $path;
	}

	private function urls_match( string $a, string $b ): bool {
		return $this->normalize_url( $a ) === $this->normalize_url( $b );
	}

	private function normalize_url( string $url ): string {
		$base = strtok( $url, '?' );
		return preg_replace( '#^https?://#', '//', $base );
	}
}
