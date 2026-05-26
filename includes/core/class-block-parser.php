<?php
/**
 * Image Kit — Gutenberg block parser.
 *
 * Unified block parsing extracted from broken-image-checker (most comprehensive),
 * flickr-upgrader, low-scan, and image-upgrader.
 *
 * Supports: wp:image, wp:gallery, wp:cover, wp:media-text.
 * Uses consumed-region tracking to prevent double-counting nested blocks.
 */

defined( 'ABSPATH' ) || exit;

class Image_Kit_Core_Block_Parser {

	/**
	 * Regex for wp:image blocks (JSON attrs + inner HTML).
	 */
	const IMAGE_BLOCK_PATTERN = '#<!-- wp:image\s*(\{(?:[^{}]|\{[^{}]*\})*\})?\s*-->(.*?)<!-- /wp:image -->#s';

	/**
	 * Self-closing wp:image blocks (JSON attrs only, no inner HTML).
	 */
	const IMAGE_BLOCK_SELF_CLOSING = '#<!-- wp:image\s+(\{(?:[^{}]|\{[^{}]*\})*\})\s*/-->#s';

	/**
	 * 5-group pattern for surgical block manipulation (open, JSON, gap, inner, close).
	 */
	const IMAGE_BLOCK_PARTS = '#(<!-- wp:image\s*)(\{(?:[^{}]|\{[^{}]*\})*\})(\s*-->)(.*?)(<!-- /wp:image -->)#s';

	/**
	 * Regex for extracting <img src> from HTML.
	 */
	const IMG_SRC_PATTERN = '#<img\s[^>]*?src=["\']([^"\']+)["\']#i';

	/**
	 * Extract all image URLs from post content with block-type attribution.
	 *
	 * Returns array of [ 'url' => ..., 'block_type' => ..., 'attachment_id' => int|null ].
	 * Uses consumed-region tracking to avoid double-counting images in nested blocks.
	 *
	 * @param string $content Post content.
	 * @return array
	 */
	public function extract_image_urls( string $content ): array {
		$results  = array();
		$seen     = array();
		$consumed = array();

		// 1. wp:gallery blocks — contain nested wp:image blocks.
		$this->parse_gallery_blocks( $content, $results, $seen, $consumed );

		// 2. wp:image blocks (outside galleries).
		$this->parse_image_blocks( $content, $results, $seen, $consumed );

		// 3. wp:cover blocks.
		$this->parse_cover_blocks( $content, $results, $seen, $consumed );

		// 4. wp:media-text blocks.
		$this->parse_media_text_blocks( $content, $results, $seen, $consumed );

		// 5. Raw <img> tags outside consumed regions.
		$this->parse_raw_img_tags( $content, $results, $seen, $consumed );

		/**
		 * Filter the block types that are parsed for images.
		 *
		 * @param array  $results  Parsed image results.
		 * @param string $content  Original post content.
		 */
		return apply_filters( 'image_kit_parsed_images', $results, $content );
	}

	// ── Private parsing methods ──

	private function parse_gallery_blocks( string $content, array &$results, array &$seen, array &$consumed ): void {
		if ( ! preg_match_all(
			'#<!-- wp:gallery\s*(?:\{(?:[^{}]|\{[^{}]*\})*\})?\s*-->(.*?)<!-- /wp:gallery -->#s',
			$content, $gal_matches, PREG_OFFSET_CAPTURE
		) ) {
			return;
		}

		foreach ( $gal_matches[0] as $i => $m ) {
			$consumed[] = array( $m[1], $m[1] + strlen( $m[0] ) );
			$inner      = $gal_matches[1][ $i ][0];

			// Nested wp:image blocks.
			if ( preg_match_all( self::IMAGE_BLOCK_PATTERN, $inner, $img_matches, PREG_SET_ORDER ) ) {
				foreach ( $img_matches as $im ) {
					$this->collect_urls_from_block( $im[1] ?? '', $im[2] ?? '', 'wp:gallery', $results, $seen );
				}
			}

			// Self-closing wp:image blocks.
			if ( preg_match_all( self::IMAGE_BLOCK_SELF_CLOSING, $inner, $sc_matches, PREG_SET_ORDER ) ) {
				foreach ( $sc_matches as $sc ) {
					$this->collect_urls_from_block( $sc[1], '', 'wp:gallery', $results, $seen );
				}
			}

			// Fallback: raw <img> tags not in nested blocks.
			$stripped = preg_replace( '#<!-- wp:image.*?/wp:image -->#s', '', $inner );
			$stripped = preg_replace( self::IMAGE_BLOCK_SELF_CLOSING, '', $stripped );
			$this->collect_img_srcs( $stripped, 'wp:gallery', $results, $seen );
		}
	}

	private function parse_image_blocks( string $content, array &$results, array &$seen, array &$consumed ): void {
		// Regular wp:image blocks.
		if ( preg_match_all( self::IMAGE_BLOCK_PATTERN, $content, $img_matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $img_matches as $im ) {
				$offset = $im[0][1];
				if ( $this->in_consumed( $offset, $consumed ) ) {
					continue;
				}
				$consumed[] = array( $offset, $offset + strlen( $im[0][0] ) );
				$this->collect_urls_from_block( $im[1][0] ?? '', $im[2][0] ?? '', 'wp:image', $results, $seen );
			}
		}

		// Self-closing wp:image blocks.
		if ( preg_match_all( self::IMAGE_BLOCK_SELF_CLOSING, $content, $sc_matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $sc_matches as $sc ) {
				$offset = $sc[0][1];
				if ( $this->in_consumed( $offset, $consumed ) ) {
					continue;
				}
				$consumed[] = array( $offset, $offset + strlen( $sc[0][0] ) );
				$this->collect_urls_from_block( $sc[1][0], '', 'wp:image', $results, $seen );
			}
		}
	}

	private function parse_cover_blocks( string $content, array &$results, array &$seen, array &$consumed ): void {
		if ( ! preg_match_all(
			'#<!-- wp:cover\s*(\{(?:[^{}]|\{[^{}]*\})*\})?\s*-->(.*?)<!-- /wp:cover -->#s',
			$content, $cov_matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
		) ) {
			return;
		}

		foreach ( $cov_matches as $cm ) {
			$offset = $cm[0][1];
			$consumed[] = array( $offset, $offset + strlen( $cm[0][0] ) );
			$json_str   = $cm[1][0] ?? '';
			$inner_html = $cm[2][0] ?? '';

			if ( $json_str && preg_match( '/"url"\s*:\s*"([^"]+)"/i', $json_str, $u ) ) {
				$this->maybe_add_url( $u[1], 'wp:cover', $results, $seen );
			}

			$this->collect_img_srcs( $inner_html, 'wp:cover', $results, $seen );
		}
	}

	private function parse_media_text_blocks( string $content, array &$results, array &$seen, array &$consumed ): void {
		if ( ! preg_match_all(
			'#<!-- wp:media-text\s*(\{(?:[^{}]|\{[^{}]*\})*\})?\s*-->(.*?)<!-- /wp:media-text -->#s',
			$content, $mt_matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
		) ) {
			return;
		}

		foreach ( $mt_matches as $mt ) {
			$offset = $mt[0][1];
			$consumed[] = array( $offset, $offset + strlen( $mt[0][0] ) );
			$json_str   = $mt[1][0] ?? '';
			$inner_html = $mt[2][0] ?? '';

			if ( $json_str && preg_match( '/"mediaLink"\s*:\s*"([^"]+)"/i', $json_str, $u ) ) {
				$this->maybe_add_url( $u[1], 'wp:media-text', $results, $seen );
			}

			$this->collect_img_srcs( $inner_html, 'wp:media-text', $results, $seen );
		}
	}

	private function parse_raw_img_tags( string $content, array &$results, array &$seen, array &$consumed ): void {
		if ( ! preg_match_all( self::IMG_SRC_PATTERN, $content, $raw_matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return;
		}

		foreach ( $raw_matches as $rm ) {
			$offset = $rm[0][1];
			if ( $this->in_consumed( $offset, $consumed ) ) {
				continue;
			}
			$this->maybe_add_url( $rm[1][0], 'img tag', $results, $seen );
		}
	}

	// ── Helpers ──

	private function collect_urls_from_block( string $json_str, string $inner_html, string $block_type, array &$results, array &$seen ): void {
		if ( $json_str ) {
			$attrs = json_decode( $json_str, true );
			if ( is_array( $attrs ) && ! empty( $attrs['url'] ) ) {
				$this->maybe_add_url( $attrs['url'], $block_type, $results, $seen );
			}
		}

		if ( $inner_html ) {
			$this->collect_img_srcs( $inner_html, $block_type, $results, $seen );
		}
	}

	private function collect_img_srcs( string $html, string $block_type, array &$results, array &$seen ): void {
		if ( preg_match_all( self::IMG_SRC_PATTERN, $html, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$this->maybe_add_url( $url, $block_type, $results, $seen );
			}
		}
	}

	private function maybe_add_url( string $url, string $block_type, array &$results, array &$seen ): void {
		$url_clean = strtok( $url, '?' );
		$url_key   = preg_replace( '#^https?://#', '//', $url_clean );

		if ( isset( $seen[ $url_key ] ) ) {
			return;
		}
		$seen[ $url_key ] = true;

		$results[] = array(
			'url'        => $url_clean,
			'block_type' => $block_type,
		);
	}

	private function in_consumed( int $offset, array $consumed ): bool {
		foreach ( $consumed as $range ) {
			if ( $offset >= $range[0] && $offset < $range[1] ) {
				return true;
			}
		}
		return false;
	}
}
