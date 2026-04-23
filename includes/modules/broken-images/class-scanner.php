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
}
