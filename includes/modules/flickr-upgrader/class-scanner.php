<?php
/**
 * Media Cleanup Kit — Replace Flickr Images scanner.
 *
 * Finds Gutenberg wp:image blocks whose <img src> references a Flickr-hosted
 * file (filename pattern {photo_id}_{secret}_{size_suffix}.{ext}) and reports
 * them so the user can hand the list off to tools/flickr-fetch.py, which
 * downloads larger versions via the Flickr API.
 *
 * Ported from the standalone Flickr Upgrader plugin's Flickr_Upgrader_Scanner.
 * The scan-only logic — no DB writes; results are returned to the scan-UI
 * helper and exported as CSV.
 */

defined( 'ABSPATH' ) || exit;

class Image_Kit_Flickr_Upgrader_Scanner {

	/**
	 * Filename pattern: {photo_id}_{secret}_{size_suffix}.{ext}
	 * size suffixes: s/q/t/m/n/w/z/c/b/h/k (skip o — already original).
	 */
	const FLICKR_PATTERN = '/(\d{5,})_([0-9a-f]+)_(s|q|t|m|n|w|z|c|b|h|k)\.(jpe?g|png|gif)/i';

	/**
	 * Scan a batch of posts. Mirrors the low-resolution scanner's contract.
	 *
	 * @param array  $post_types  Post types to include.
	 * @param int    $offset      Starting offset.
	 * @param int    $batch_size  How many posts to scan in this batch.
	 * @param string $date_from   YYYY-MM-DD or empty.
	 * @param string $date_to     YYYY-MM-DD or empty.
	 * @param int    $known_total Total posts (provided after first batch).
	 * @return array { items, offset, total_posts, done }
	 */
	public function scan_batch( array $post_types, int $offset, int $batch_size, string $date_from = '', string $date_to = '', int $known_total = 0 ): array {
		$args = array(
			'post_type'      => $post_types ?: array( 'post', 'page' ),
			'post_status'    => array( 'publish', 'draft', 'private', 'future', 'pending' ),
			'posts_per_page' => $batch_size,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'fields'         => 'ids',
		);

		$date_query = array();
		if ( $date_from ) {
			$date_query['after'] = $date_from;
		}
		if ( $date_to ) {
			$date_query['before'] = $date_to;
		}
		if ( $date_query ) {
			$date_query['inclusive'] = true;
			$args['date_query']      = array( $date_query );
		}

		$query    = new WP_Query( $args );
		$post_ids = $query->posts;

		// Determine the total once, on the first batch.
		$total = $known_total > 0 ? $known_total : $this->count_candidate_posts( $post_types, $date_from, $date_to );

		$items = array();
		foreach ( $post_ids as $post_id ) {
			$post_items = $this->scan_post( (int) $post_id );
			if ( $post_items ) {
				$items = array_merge( $items, $post_items );
			}
		}

		$new_offset = $offset + count( $post_ids );
		$done       = empty( $post_ids ) || $new_offset >= $total;

		return array(
			'items'       => $items,
			'offset'      => $new_offset,
			'total_posts' => $total,
			'done'        => $done,
		);
	}

	private function count_candidate_posts( array $post_types, string $date_from, string $date_to ): int {
		$args = array(
			'post_type'      => $post_types ?: array( 'post', 'page' ),
			'post_status'    => array( 'publish', 'draft', 'private', 'future', 'pending' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		);

		$date_query = array();
		if ( $date_from ) {
			$date_query['after'] = $date_from;
		}
		if ( $date_to ) {
			$date_query['before'] = $date_to;
		}
		if ( $date_query ) {
			$date_query['inclusive'] = true;
			$args['date_query']      = array( $date_query );
		}

		$q = new WP_Query( $args );
		return (int) $q->found_posts;
	}

	/**
	 * Scan a single post. Returns an array of item arrays (one per Flickr
	 * image found).
	 *
	 * @param int $post_id
	 * @return array
	 */
	private function scan_post( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || empty( $post->post_content ) ) {
			return array();
		}

		$found = $this->find_flickr_images( $post->post_content );
		if ( ! $found ) {
			return array();
		}

		$edit_link = get_edit_post_link( $post_id, 'raw' );
		$items     = array();
		foreach ( $found as $finding ) {
			$attachment_id = $finding['attachment_id'];
			$thumb_url     = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : '';

			$items[] = array(
				'post_id'             => $post_id,
				'post_title'          => $post->post_title ?: '(no title)',
				'edit_link'           => $edit_link,
				'flickr_photo_id'     => $finding['photo_id'],
				'secret'              => $finding['secret'],
				'current_size_suffix' => $finding['size_suffix'],
				'current_url'         => $finding['src_url'],
				'current_filename'    => $finding['filename'],
				'attachment_id'       => $attachment_id,
				'thumbnail_url'       => $thumb_url ?: $finding['src_url'],
			);
		}

		return $items;
	}

	/**
	 * Find Flickr images in post content (Gutenberg wp:image blocks only).
	 *
	 * @param string $content
	 * @return array
	 */
	public function find_flickr_images( string $content ): array {
		$findings = array();

		$block_pattern = '#<!-- wp:image\s*(\{(?:[^{}]|\{[^{}]*\})*\})?\s*-->(.*?)<!-- /wp:image -->#s';
		if ( ! preg_match_all( $block_pattern, $content, $matches, PREG_SET_ORDER ) ) {
			return $findings;
		}

		foreach ( $matches as $match ) {
			$inner_html = $match[2];

			if ( ! preg_match( '#<img\s[^>]*src="([^"]+)"#i', $inner_html, $img_match ) ) {
				continue;
			}
			$src_url  = $img_match[1];
			$filename = wp_basename( (string) wp_parse_url( $src_url, PHP_URL_PATH ) );

			// FLICKR_PATTERN intentionally excludes the `_o` (original) suffix —
			// nothing to upgrade if the file is already the original.
			if ( ! preg_match( self::FLICKR_PATTERN, $filename, $fm ) ) {
				continue;
			}

			$attachment_id = attachment_url_to_postid( $src_url );
			if ( ! $attachment_id ) {
				$attachment_id = $this->lookup_attachment_by_filename( $filename );
			}

			$findings[] = array(
				'photo_id'      => $fm[1],
				'secret'        => $fm[2],
				'size_suffix'   => $fm[3],
				'src_url'       => $src_url,
				'filename'      => $filename,
				'attachment_id' => (int) $attachment_id,
			);
		}

		return $findings;
	}

	/**
	 * Fallback attachment lookup: query _wp_attached_file by filename suffix.
	 *
	 * @param string $filename
	 * @return int
	 */
	public function lookup_attachment_by_filename( string $filename ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- attachments are not cached by filename suffix, so a direct LIKE query is required.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s
				 LIMIT 1",
				'%' . $wpdb->esc_like( $filename )
			)
		);

		return $id ? (int) $id : 0;
	}
}
