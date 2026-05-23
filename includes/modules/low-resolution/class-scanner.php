<?php
/**
 * Image Kit — Low Resolution scanner.
 *
 * Finds images in post content and featured images below a configurable
 * resolution threshold by reading actual file dimensions from disk via getimagesize().
 */

defined( 'ABSPATH' ) || exit;

class Image_Kit_Low_Resolution_Scanner {

	/**
	 * Cache of getimagesize() results per attachment ID.
	 *
	 * @var array<int, array|false>
	 */
	private $dimension_cache = array();

	/**
	 * Cache of attachment_url_to_postid() results per URL.
	 *
	 * @var array<string, int>
	 */
	private $url_to_id_cache = array();

	/**
	 * Count posts that contain image references.
	 *
	 * @param string[] $post_types Post types to include.
	 * @param string   $date_from  Optional date range start (Y-m-d).
	 * @param string   $date_to    Optional date range end (Y-m-d).
	 * @return int
	 */
	public function get_total_posts( $post_types = array( 'post', 'page' ), $date_from = '', $date_to = '' ) {
		$args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => false,
		);

		if ( $date_from || $date_to ) {
			$date_query = array( 'inclusive' => true );
			if ( $date_from ) {
				$date_query['after'] = $date_from;
			}
			if ( $date_to ) {
				$date_query['before'] = $date_to;
			}
			$args['date_query'] = array( $date_query );
		}

		$query = new WP_Query( $args );
		return $query->found_posts;
	}

	/**
	 * Scan a batch of posts for low-resolution images.
	 *
	 * @param string[] $post_types Post types to scan.
	 * @param int      $offset     Current offset.
	 * @param int      $batch_size Number of posts per batch.
	 * @param int      $threshold  Minimum longest side in pixels. 0 = include all.
	 * @param string   $date_from  Optional date range start.
	 * @param string   $date_to    Optional date range end.
	 * @param int      $known_total Known total to skip COUNT query on subsequent batches.
	 * @return array { items: array, offset: int, total_posts: int, done: bool }
	 */
	public function scan_batch( $post_types, $offset, $batch_size, $threshold = 2048, $date_from = '', $date_to = '', $known_total = 0 ) {
		$need_total = ( 0 === $known_total );

		$args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'offset'         => $offset,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => ! $need_total,
		);

		if ( $date_from || $date_to ) {
			$date_query = array( 'inclusive' => true );
			if ( $date_from ) {
				$date_query['after'] = $date_from;
			}
			if ( $date_to ) {
				$date_query['before'] = $date_to;
			}
			$args['date_query'] = array( $date_query );
		}

		$query       = new WP_Query( $args );
		$post_ids    = $query->posts;
		$total_posts = $need_total ? $query->found_posts : $known_total;

		$items = array();

		foreach ( $post_ids as $post_id ) {
			$found = $this->scan_post( $post_id, $threshold );
			$items = array_merge( $items, $found );
		}

		$new_offset = $offset + count( $post_ids );
		$done       = count( $post_ids ) < $batch_size;

		return array(
			'items'       => $items,
			'offset'      => $new_offset,
			'total_posts' => $total_posts,
			'done'        => $done,
		);
	}

	/**
	 * Scan a single post for images below the resolution threshold.
	 *
	 * Parses wp:image Gutenberg blocks and extracts image src URLs,
	 * resolves attachment IDs, reads dimensions from disk.
	 *
	 * @param int $post_id   Post ID.
	 * @param int $threshold Minimum longest side in pixels. 0 = include all.
	 * @return array Array of found items.
	 */
	public function scan_post( $post_id, $threshold = 2048 ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$block_pattern = '#<!-- wp:image\s*(\{(?:[^{}]|\{[^{}]*\})*\})?\s*-->(.*?)<!-- /wp:image -->#s';
		$img_pattern   = '#<img\s[^>]*src="([^"]+)"#i';

		$items = array();
		$seen_attachments = array();
		$matches = array();

		if ( ! empty( $post->post_content ) ) {
			preg_match_all( $block_pattern, $post->post_content, $matches, PREG_SET_ORDER );
		}

		foreach ( $matches as $match ) {
			$json_str   = isset( $match[1] ) ? $match[1] : '';
			$inner_html = $match[2];
			$attrs      = json_decode( $json_str, true );

			if ( ! is_array( $attrs ) ) {
				$attrs = array();
			}

			if ( ! preg_match( $img_pattern, $inner_html, $img_match ) ) {
				continue;
			}
			$src_url = $img_match[1];

			// Get attachment ID from block attrs or URL lookup.
			$attachment_id = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
			if ( ! $attachment_id ) {
				$attachment_id = $this->get_attachment_id_by_url( $src_url );
			}

			// Deduplicate by attachment ID within this post.
			if ( $attachment_id && isset( $seen_attachments[ $attachment_id ] ) ) {
				continue;
			}

			$width        = 0;
			$height       = 0;
			$longest_side = 0;
			$file_path    = '';
			$thumbnail_url = '';

			if ( $attachment_id ) {
				$dims = $this->get_dimensions( $attachment_id );

				if ( ! isset( $dims['error'] ) ) {
					$width        = $dims['width'];
					$height       = $dims['height'];
					$longest_side = max( $width, $height );
				}

				$file_path     = get_attached_file( $attachment_id ) ?: '';
				$thumbnail_url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) ?: '';
			}

			// Apply threshold filter: 0 = include all, otherwise skip if at/above threshold.
			if ( $threshold > 0 && $longest_side >= $threshold ) {
				continue;
			}

			if ( $attachment_id ) {
				$seen_attachments[ $attachment_id ] = true;
			}

			$items[] = array(
				'post_id'        => (int) $post_id,
				'post_title'     => $post->post_title,
				'edit_link'      => get_edit_post_link( $post_id, 'raw' ) ?: '',
				'attachment_id'  => $attachment_id,
				'src_url'        => $src_url,
				'file_path'      => $file_path,
				'width'          => $width,
				'height'         => $height,
				'longest_side'   => $longest_side,
				'thumbnail_url'  => $thumbnail_url,
				'size_slug'      => isset( $attrs['sizeSlug'] ) ? $attrs['sizeSlug'] : '',
				'source'         => 'content',
			);
		}

		$featured_attachment_id = (int) get_post_thumbnail_id( $post_id );
		if ( $featured_attachment_id > 0 && ! isset( $seen_attachments[ $featured_attachment_id ] ) ) {
			$dims = $this->get_dimensions( $featured_attachment_id );
			$width = 0;
			$height = 0;
			$longest_side = 0;

			if ( ! isset( $dims['error'] ) ) {
				$width        = $dims['width'];
				$height       = $dims['height'];
				$longest_side = max( $width, $height );
			}

			if ( 0 === $threshold || $longest_side < $threshold ) {
				$items[] = array(
					'post_id'        => (int) $post_id,
					'post_title'     => $post->post_title,
					'edit_link'      => get_edit_post_link( $post_id, 'raw' ) ?: '',
					'attachment_id'  => $featured_attachment_id,
					'src_url'        => wp_get_attachment_url( $featured_attachment_id ) ?: '',
					'file_path'      => get_attached_file( $featured_attachment_id ) ?: '',
					'width'          => $width,
					'height'         => $height,
					'longest_side'   => $longest_side,
					'thumbnail_url'  => wp_get_attachment_image_url( $featured_attachment_id, 'thumbnail' ) ?: '',
					'size_slug'      => 'featured-image',
					'source'         => 'featured',
				);
			}
		}

		return $items;
	}

	/**
	 * Look up attachment ID by URL, with instance-level caching.
	 *
	 * @param string $url Image URL.
	 * @return int Attachment ID or 0.
	 */
	private function get_attachment_id_by_url( $url ) {
		if ( isset( $this->url_to_id_cache[ $url ] ) ) {
			return $this->url_to_id_cache[ $url ];
		}
		$id = attachment_url_to_postid( $url );
		$this->url_to_id_cache[ $url ] = $id;
		return $id;
	}

	/**
	 * Get actual image dimensions from the file on disk.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array { width: int, height: int } or { error: string }
	 */
	private function get_dimensions( $attachment_id ) {
		if ( isset( $this->dimension_cache[ $attachment_id ] ) ) {
			return $this->dimension_cache[ $attachment_id ];
		}

		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			$result = array( 'error' => 'File not found on disk.' );
			$this->dimension_cache[ $attachment_id ] = $result;
			return $result;
		}

		$size = @getimagesize( $file );

		if ( false === $size ) {
			$result = array( 'error' => 'Could not read image dimensions.' );
			$this->dimension_cache[ $attachment_id ] = $result;
			return $result;
		}

		$result = array(
			'width'  => (int) $size[0],
			'height' => (int) $size[1],
		);

		$this->dimension_cache[ $attachment_id ] = $result;
		return $result;
	}
}
