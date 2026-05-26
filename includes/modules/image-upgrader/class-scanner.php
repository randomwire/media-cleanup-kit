<?php
/**
 * Image Kit — Image Upgrader scanner.
 *
 * Scans post_content for resized image variants (e.g. photo-300x200.jpg, photo-scaled.jpg)
 * and replaces them with the full-size original from the media library.
 *
 * Processing follows strict priority order so each image occurrence is handled only once:
 * 1. Gutenberg blocks
 * 2. Caption shortcodes
 * 3. Classic <img> with wp-image-{id} class
 * 4. Raw <img> tags
 * 5. <a> wrapper hrefs
 */

defined( 'ABSPATH' ) || exit;

class Image_Kit_Image_Upgrader_Scanner {

	/** @var array<string, int|false> Attachment lookup cache. */
	private $attachment_cache = array();

	/** @var array<string, bool> CDN/remote file verification cache. */
	private $file_exists_cache = array();

	/** @var string[] Historical base URLs discovered from attachment metadata and GUIDs. */
	private $url_aliases = array();

	/** @var bool Whether the alias map has been built for this run. */
	private $alias_map_built = false;

	/** @var string Current uploads base URL. */
	private $uploads_baseurl = '';

	/** @var string Current uploads base directory. */
	private $uploads_basedir = '';

	/** @var array<string, bool> Tracks processed URLs to prevent double-processing. */
	private $processed_urls = array();

	/** @var array Maps old src URL => full-size URL + attachment_id for <a> href processing. */
	private $img_replacement_map = array();

	/** @var string[] Lazy-loading attributes to check for resized URLs. */
	private $lazy_attrs = array( 'data-src', 'data-lazy-src', 'data-original', 'data-srcset' );

	/**
	 * Constructor.
	 */
	public function __construct() {
		$upload_dir            = wp_upload_dir();
		$this->uploads_baseurl = $upload_dir['baseurl'];
		$this->uploads_basedir = $upload_dir['basedir'];
	}

	/**
	 * Scan a single post for resized images.
	 *
	 * @param int   $post_id    Post ID.
	 * @param bool  $dry_run    If true, do not write changes.
	 * @param array $selections Optional map of from_url => attachment_id for candidate selection.
	 * @return array
	 */
	public function scan_post( $post_id, $dry_run = true, $selections = array() ) {
		$result = array(
			'post_id'         => $post_id,
			'images_replaced' => 0,
			'images_skipped'  => 0,
			'replacements'    => array(),
			'error_message'   => null,
		);

		$post = get_post( $post_id );
		if ( ! $post || empty( $post->post_content ) ) {
			return $result;
		}

		if ( ! $this->alias_map_built ) {
			$this->build_url_alias_map();
		}

		$content = $post->post_content;
		$matches = $this->find_resized_urls( $content );

		$resolved = array();
		$is_first = true;

		foreach ( $matches as $resized_url => $match_info ) {
			if ( $is_first ) {
				$is_first = false;
			} else {
				usleep( 10000 );
			}

			$resolution             = $this->resolve_resized_url( $resized_url, $match_info );
			$result['replacements'][] = $resolution;

			if ( $resolution['skipped'] ) {
				$result['images_skipped']++;
			} else {
				$result['images_replaced']++;
				$resolved[ $resized_url ] = $resolution;
			}
		}

		// Filename-match pass.
		$filename_matches = $this->find_nonuploads_imgs( $content, $matches );
		foreach ( $filename_matches as $src_url => $candidate_ids ) {
			if ( ! $is_first ) {
				usleep( 10000 );
			}
			$is_first = false;

			$resolution             = $this->resolve_filename_match( $src_url, $candidate_ids, $selections );
			$result['replacements'][] = $resolution;

			if ( $resolution['skipped'] ) {
				$result['images_skipped']++;
			} else {
				$result['images_replaced']++;
				$resolved[ $src_url ] = $resolution;
			}
		}

		if ( empty( $resolved ) ) {
			return $result;
		}

		if ( $dry_run ) {
			$this->detect_markup_types( $content, $resolved, $result );
		} else {
			$this->processed_urls      = array();
			$this->img_replacement_map = array();

			$content = $this->process_gutenberg_blocks( $content, $resolved );
			$content = $this->process_caption_shortcodes( $content, $resolved );
			$content = $this->process_classic_imgs( $content, $resolved );
			$content = $this->process_raw_imgs( $content, $resolved );
			$content = $this->process_anchor_hrefs( $content, $resolved );

			if ( $content !== $post->post_content ) {
				$update_result = wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => $content,
					),
					true
				);

				if ( is_wp_error( $update_result ) ) {
					$result['error_message'] = $update_result->get_error_message();
				}
			}
		}

		return $result;
	}

	/**
	 * Audit a single post for incomplete Gutenberg image block markup.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $dry_run If true, do not write changes.
	 * @return array Same shape as scan_post().
	 */
	public function audit_post( $post_id, $dry_run = true ) {
		$result = array(
			'post_id'         => $post_id,
			'images_replaced' => 0,
			'images_skipped'  => 0,
			'replacements'    => array(),
			'error_message'   => null,
		);

		$post = get_post( $post_id );
		if ( ! $post || empty( $post->post_content ) ) {
			return $result;
		}

		if ( ! $this->alias_map_built ) {
			$this->build_url_alias_map();
		}

		$content  = $post->post_content;
		$findings = $this->find_incomplete_blocks( $content );

		foreach ( $findings as $finding ) {
			$src_url       = $finding['src_url'];
			$attachment_id = $this->lookup_attachment( $src_url );

			if ( ! $attachment_id ) {
				$filename      = wp_basename( $src_url );
				$candidate_ids = $this->lookup_attachments_by_filename( $filename );
				if ( ! empty( $candidate_ids ) ) {
					$attachment_id = $candidate_ids[0];
				} else {
					foreach ( $this->edited_filename_alternatives( $filename ) as $alt ) {
						$candidate_ids = $this->lookup_attachments_by_filename( $alt );
						if ( ! empty( $candidate_ids ) ) {
							$attachment_id = $candidate_ids[0];
							break;
						}
					}
				}
			}

			$replacement = array(
				'from_url'            => $src_url,
				'to_url'              => $src_url,
				'attachment_id'       => null,
				'markup_type'         => 'gutenberg_audit',
				'from_size'           => 'audit',
				'issues'              => $finding['issues'],
				'proposed_block_json' => array(),
				'to_dimensions'       => null,
				'skipped'             => false,
				'skip_reason'         => null,
				'excluded'            => false,
			);

			if ( ! $attachment_id ) {
				$replacement['skipped']     = true;
				$replacement['skip_reason'] = 'attachment_not_found';
				$result['replacements'][]   = $replacement;
				$result['images_skipped']++;
				continue;
			}

			$replacement['attachment_id'] = $attachment_id;

			$proposed = $finding['block_json'];
			if ( empty( $proposed['id'] ) ) {
				$proposed['id'] = $attachment_id;
			}
			if ( empty( $proposed['sizeSlug'] ) ) {
				$proposed['sizeSlug'] = 'full';
			}
			if ( ! isset( $proposed['linkDestination'] ) ) {
				$proposed['linkDestination'] = $this->determine_link_destination(
					$finding['inner_html'],
					$attachment_id
				);
			}

			$replacement['proposed_block_json'] = $proposed;

			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( $meta && isset( $meta['width'], $meta['height'] ) ) {
				$replacement['to_dimensions'] = array(
					'width'  => (int) $meta['width'],
					'height' => (int) $meta['height'],
				);
			}

			$result['replacements'][] = $replacement;
			$result['images_replaced']++;
		}

		if ( ! $dry_run && ! empty( $result['replacements'] ) ) {
			$actionable = array_filter( $result['replacements'], function ( $r ) {
				return ! $r['skipped'] && ! $r['excluded'];
			} );

			if ( ! empty( $actionable ) ) {
				$new_content = $this->apply_audit_fixes( $content, $actionable );

				if ( $new_content !== $content ) {
					$update_result = wp_update_post(
						array(
							'ID'           => $post_id,
							'post_content' => $new_content,
						),
						true
					);

					if ( is_wp_error( $update_result ) ) {
						$result['error_message'] = $update_result->get_error_message();
					}
				}
			}
		}

		return $result;
	}

	// ──────────────────────────────────────────────────────────────────
	// Private: URL alias map
	// ──────────────────────────────────────────────────────────────────

	private function build_url_alias_map() {
		global $wpdb;

		$this->alias_map_built = true;
		$this->url_aliases     = array();

		$cached = get_transient( 'image_kit_upgrader_url_aliases' );
		if ( is_array( $cached ) ) {
			$this->url_aliases = $cached;
			return;
		}

		$current_base = $this->normalize_protocol( $this->uploads_baseurl );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$guids = $wpdb->get_col(
			"SELECT DISTINCT guid FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid != '' LIMIT 500"
		);

		$discovered_bases = array();

		foreach ( $guids as $guid ) {
			if ( preg_match( '#^(https?://[^/]+/.*?/uploads)/#i', $guid, $m ) ) {
				$base = $this->normalize_protocol( $m[1] );
				if ( $base !== $current_base && ! isset( $discovered_bases[ $base ] ) ) {
					$discovered_bases[ $base ] = true;
				}
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_samples = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 100",
				'_wp_attached_file',
				'http%'
			)
		);

		foreach ( $meta_samples as $meta_url ) {
			if ( preg_match( '#^(https?://[^/]+/.*?/uploads)/#i', $meta_url, $m ) ) {
				$base = $this->normalize_protocol( $m[1] );
				if ( $base !== $current_base && ! isset( $discovered_bases[ $base ] ) ) {
					$discovered_bases[ $base ] = true;
				}
			}
		}

		$this->url_aliases = array_keys( $discovered_bases );
		set_transient( 'image_kit_upgrader_url_aliases', $this->url_aliases, HOUR_IN_SECONDS );
	}

	// ──────────────────────────────────────────────────────────────────
	// Private: Find resized URLs
	// ──────────────────────────────────────────────────────────────────

	private function find_resized_urls( $content ) {
		$bases = array( preg_quote( $this->uploads_baseurl, '#' ) );
		foreach ( $this->url_aliases as $alias ) {
			$bases[] = preg_quote( $alias, '#' );
		}

		$protocol_agnostic_bases = array();
		foreach ( $bases as $base ) {
			$protocol_agnostic_bases[] = preg_replace(
				'#^https?\\\\://#i',
				'(?:https?:)?//',
				$base
			);
		}

		$base_pattern = '(?:' . implode( '|', $protocol_agnostic_bases ) . ')';
		$pattern      = '#(' . $base_pattern . '/[^\s"\'<>]+?)-(\d+x\d+|scaled)\.(jpe?g|png|gif|webp|avif)#i';

		$found = array();

		if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$full_url = $match[0];

				if ( isset( $found[ $full_url ] ) ) {
					continue;
				}

				$base_url  = $match[1];
				$size_part = $match[ count( $match ) - 2 ];
				$extension = $match[ count( $match ) - 1 ];

				$found[ $full_url ] = array(
					'base_url'  => $base_url,
					'size_part' => $size_part,
					'extension' => $extension,
					'full_url'  => $base_url . '.' . $extension,
				);
			}
		}

		return $found;
	}

	// ──────────────────────────────────────────────────────────────────
	// Private: Resolve resized URL to attachment
	// ──────────────────────────────────────────────────────────────────

	private function resolve_resized_url( $resized_url, $match_info ) {
		$replacement = array(
			'attachment_id'        => null,
			'original_filename'    => null,
			'from_url'             => $resized_url,
			'to_url'               => null,
			'from_size'            => $match_info['size_part'],
			'to_dimensions'        => null,
			'markup_type'          => null,
			'format_variant_used'  => null,
			'link_href_updated'    => false,
			'skipped'              => false,
			'skip_reason'          => null,
			'excluded'             => false,
		);

		// For -scaled images, look up the scaled URL first (WordPress stores
		// -scaled in _wp_attached_file for big images). This avoids matching
		// a different attachment that shares the same base filename.
		if ( 'scaled' === $match_info['size_part'] ) {
			$attachment_id = $this->lookup_attachment( $resized_url );

			if ( ! $attachment_id ) {
				$candidate_url = $match_info['full_url'];
				$attachment_id = $this->lookup_attachment( $candidate_url );
			}
		} else {
			$candidate_url = $match_info['full_url'];
			$attachment_id = $this->lookup_attachment( $candidate_url );

			if ( ! $attachment_id ) {
				$scaled_url    = $match_info['base_url'] . '-scaled.' . $match_info['extension'];
				$attachment_id = $this->lookup_attachment( $scaled_url );
			}
		}

		if ( ! $attachment_id ) {
			$replacement['skipped']     = true;
			$replacement['skip_reason'] = 'attachment_not_found';
			return $replacement;
		}

		// Verify the found attachment actually owns the resized URL by checking
		// that the source URL matches the attachment's known file or its sizes.
		if ( ! $this->attachment_owns_url( $attachment_id, $resized_url ) ) {
			$replacement['skipped']     = true;
			$replacement['skip_reason'] = 'attachment_not_found';
			return $replacement;
		}

		$replacement['attachment_id'] = $attachment_id;

		$full_url = wp_get_attachment_url( $attachment_id );

		if ( ! $full_url ) {
			$replacement['skipped']     = true;
			$replacement['skip_reason'] = 'attachment_not_found';
			return $replacement;
		}

		$normalized_resized = $this->normalize_protocol( $resized_url );
		$normalized_full    = $this->normalize_protocol( $full_url );
		if ( $normalized_resized === $normalized_full ) {
			$replacement['skipped']     = true;
			$replacement['skip_reason'] = 'already_full_size';
			return $replacement;
		}

		$replacement['original_filename'] = wp_basename( $full_url );

		if ( ! $this->verify_file_exists( $full_url, $attachment_id ) ) {
			$variant_url = $this->find_format_variant( $full_url, $attachment_id );

			if ( $variant_url ) {
				$full_url                           = $variant_url;
				$replacement['format_variant_used'] = pathinfo( $variant_url, PATHINFO_EXTENSION );
			} else {
				$replacement['skipped']     = true;
				$replacement['skip_reason'] = 'file_missing';
				return $replacement;
			}
		}

		$replacement['to_url'] = $full_url;

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( $meta && isset( $meta['width'], $meta['height'] ) ) {
			$replacement['to_dimensions'] = array(
				'width'  => (int) $meta['width'],
				'height' => (int) $meta['height'],
			);
		}

		return $replacement;
	}

	// ──────────────────────────────────────────────────────────────────
	// Private: Attachment lookup chain
	// ──────────────────────────────────────────────────────────────────

	private function lookup_attachment( $url ) {
		$cache_key = $this->normalize_protocol( $url );
		if ( isset( $this->attachment_cache[ $cache_key ] ) ) {
			return $this->attachment_cache[ $cache_key ];
		}

		// User-supplied alias (Upload-replacement flow): the user explicitly
		// mapped this URL to an attachment when the automatic lookup failed.
		$aliased = $this->lookup_alias( $url );
		if ( $aliased ) {
			$this->attachment_cache[ $cache_key ] = $aliased;
			return $aliased;
		}

		$attachment_id = attachment_url_to_postid( $url );

		if ( ! $attachment_id ) {
			global $wpdb;

			$filename     = wp_basename( $url );
			$like_pattern = '%/' . $wpdb->esc_like( $filename );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$attachment_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta}
					WHERE meta_key = '_wp_attached_file'
					AND ( meta_value = %s OR meta_value LIKE %s )
					LIMIT 1",
					$filename,
					$like_pattern
				)
			);
		}

		if ( ! $attachment_id ) {
			global $wpdb;

			$filename = wp_basename( $url );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$attachment_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid LIKE %s LIMIT 1",
					'%/' . $wpdb->esc_like( $filename )
				)
			);
		}

		// Edited-image fallback. WordPress's image editor saves edited copies
		// as {name}-e{timestamp}.{ext} (with an optional -WxH size suffix).
		// The _wp_attached_file postmeta still references the original file,
		// so direct lookups above miss it. Strip the suffix(es) and retry.
		if ( ! $attachment_id ) {
			$alternatives = $this->edited_filename_alternatives( wp_basename( $url ) );
			foreach ( $alternatives as $alt_filename ) {
				$candidates = $this->lookup_attachments_by_filename( $alt_filename );
				if ( ! empty( $candidates ) ) {
					$attachment_id = $candidates[0];
					break;
				}
			}
		}

		$result                               = $attachment_id ? $attachment_id : false;
		$this->attachment_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Return candidate filenames to look up when the URL's basename doesn't
	 * match `_wp_attached_file` directly. Covers three WordPress-isms:
	 *
	 *   1. Edited-image suffix `-e<timestamp>` (saved by the image editor).
	 *   2. Thumbnail size suffix `-WxH`.
	 *   3. Big-image auto-scaling: WP renames the uploaded file to
	 *      `<name>-scaled.<ext>` and points `_wp_attached_file` at the
	 *      scaled copy, keeping the pre-scale filename in the metadata's
	 *      `original_image` field. URLs in post content often reference
	 *      the pre-scale name, so we also try ADDING `-scaled` here.
	 *
	 * Examples:
	 *   image-129-e1462792051861.jpeg
	 *     → [image-129.jpeg, image-129-e1462792051861-scaled.jpeg,
	 *        image-129-scaled.jpeg]
	 *   image-129-300x200.jpeg
	 *     → [image-129.jpeg, image-129-scaled.jpeg]
	 *
	 * @param string $filename Basename to expand.
	 * @return string[] Distinct alternative basenames (excluding the original).
	 */
	private function edited_filename_alternatives( $filename ) {
		$candidates = array();

		// 1. Strip -e<timestamp> (8+ digits).
		$stripped_e = preg_replace( '/-e\d{8,}(?=(?:-\d+x\d+)?\.[a-z0-9]+$)/i', '', $filename );
		if ( $stripped_e !== $filename ) {
			$candidates[] = $stripped_e;
		}

		// 2. Strip -WxH size suffix.
		$stripped_size = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $filename );
		if ( $stripped_size !== $filename ) {
			$candidates[] = $stripped_size;
		}

		// 1 + 2 combined.
		if ( $stripped_e !== $filename ) {
			$both = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $stripped_e );
			if ( $both !== $stripped_e ) {
				$candidates[] = $both;
			}
		}

		// 3. For every candidate so far (and the original), also try adding
		//    -scaled before the extension. Covers big-image auto-scale where
		//    _wp_attached_file holds the scaled name but the URL doesn't.
		$with_scaled = array();
		$base_pool   = array_merge( array( $filename ), $candidates );
		foreach ( $base_pool as $cand ) {
			if ( preg_match( '/-scaled\.[a-z0-9]+$/i', $cand ) ) {
				continue;
			}
			$scaled = preg_replace( '/(\.[a-z0-9]+)$/i', '-scaled$1', $cand );
			if ( $scaled && $scaled !== $cand ) {
				$with_scaled[] = $scaled;
			}
		}

		$candidates = array_merge( $candidates, $with_scaled );

		return array_values( array_unique( array_filter( $candidates, function ( $f ) use ( $filename ) {
			return $f && $f !== $filename;
		} ) ) );
	}

	/**
	 * Verify that an attachment actually owns a given URL.
	 *
	 * Checks the attachment's main file, its -scaled variant, and all
	 * registered thumbnail sizes. Prevents mismatches when different
	 * attachments share the same base filename (e.g. IMG_0185.jpeg
	 * and IMG_0185-1.jpeg).
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $url           URL to verify ownership of.
	 * @return bool
	 */
	private function attachment_owns_url( $attachment_id, $url ) {
		$url_basename = wp_basename( $url );

		// Check main file.
		$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( $attached_file && wp_basename( $attached_file ) === $url_basename ) {
			return true;
		}

		// Check the attachment URL itself (may differ from _wp_attached_file due to CDN).
		$att_url = wp_get_attachment_url( $attachment_id );
		if ( $att_url && wp_basename( $att_url ) === $url_basename ) {
			return true;
		}

		// Check registered thumbnail sizes.
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size_data ) {
				if ( isset( $size_data['file'] ) && $size_data['file'] === $url_basename ) {
					return true;
				}
			}
		}

		// Check if the original (pre-scaled) filename matches.
		if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) {
			if ( $meta['original_image'] === $url_basename ) {
				return true;
			}
		}

		return false;
	}

	private function verify_file_exists( $url, $attachment_id ) {
		if ( isset( $this->file_exists_cache[ $url ] ) ) {
			return $this->file_exists_cache[ $url ];
		}

		$url_host     = wp_parse_url( $url, PHP_URL_HOST );
		$uploads_host = wp_parse_url( $this->uploads_baseurl, PHP_URL_HOST );

		if ( $url_host === $uploads_host ) {
			$local_path = get_attached_file( $attachment_id );
			$exists     = $local_path && file_exists( $local_path );

			$this->file_exists_cache[ $url ] = $exists;
			return $exists;
		}

		$response = wp_remote_head( $url, array( 'timeout' => 5 ) );

		if ( is_wp_error( $response ) ) {
			$this->file_exists_cache[ $url ] = false;
			return false;
		}

		$exists                          = 200 === wp_remote_retrieve_response_code( $response );
		$this->file_exists_cache[ $url ] = $exists;
		return $exists;
	}

	private function find_format_variant( $url, $attachment_id ) {
		$path_info = pathinfo( $url );
		$base      = $path_info['dirname'] . '/' . $path_info['filename'];

		foreach ( array( 'webp', 'avif' ) as $ext ) {
			$variant_url = $base . '.' . $ext;

			if ( $this->verify_file_exists( $variant_url, $attachment_id ) ) {
				return $variant_url;
			}
		}

		return null;
	}

	// ──────────────────────────────────────────────────────────────────
	// Private: Markup type detection (dry run)
	// ──────────────────────────────────────────────────────────────────

	private function detect_markup_types( $content, $resolved, &$result ) {
		$gutenberg_regions = array();
		if ( preg_match_all( '#<!-- wp:image\s.*?-->.*?<!-- /wp:image -->#s', $content, $gb_matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $gb_matches[0] as $m ) {
				$gutenberg_regions[] = array( $m[1], $m[1] + strlen( $m[0] ) );
			}
		}

		$caption_regions = array();
		if ( preg_match_all( '#\[caption\s[^\]]*\].*?\[/caption\]#s', $content, $cap_matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $cap_matches[0] as $m ) {
				$caption_regions[] = array( $m[1], $m[1] + strlen( $m[0] ) );
			}
		}

		foreach ( $result['replacements'] as &$rep ) {
			if ( $rep['skipped'] || empty( $rep['from_url'] ) ) {
				continue;
			}

			$from_url = $rep['from_url'];
			$pos      = strpos( $content, $from_url );
			if ( false === $pos ) {
				$proto_relative = preg_replace( '#^https?://#i', '//', $from_url );
				$pos            = strpos( $content, $proto_relative );
			}

			if ( false === $pos ) {
				$rep['markup_type'] = 'raw_img';
				continue;
			}

			$markup_type = 'raw_img';

			foreach ( $gutenberg_regions as $region ) {
				if ( $pos >= $region[0] && $pos < $region[1] ) {
					$markup_type = 'gutenberg';
					break;
				}
			}

			if ( 'raw_img' === $markup_type ) {
				foreach ( $caption_regions as $region ) {
					if ( $pos >= $region[0] && $pos < $region[1] ) {
						$markup_type = 'caption_shortcode';
						break;
					}
				}
			}

			if ( 'raw_img' === $markup_type ) {
				$tag_start = strrpos( substr( $content, 0, $pos ), '<img' );
				if ( false !== $tag_start ) {
					$tag_end = strpos( $content, '>', $pos );
					if ( false !== $tag_end ) {
						$img_tag = substr( $content, $tag_start, $tag_end - $tag_start + 1 );
						if ( preg_match( '/class="[^"]*wp-image-\d+/', $img_tag ) ) {
							$markup_type = 'classic_img';
						}
					}
				}
			}

			$rep['markup_type'] = $markup_type;
		}
		unset( $rep );
	}

	// ──────────────────────────────────────────────────────────────────
	// Private: Content processing (apply mode)
	// ──────────────────────────────────────────────────────────────────

	private function process_gutenberg_blocks( $content, $resolved ) {
		$pattern = '#(<!-- wp:image\s+(\{(?:[^{}]|\{[^{}]*\})*\})\s*-->)(.*?)(<!-- /wp:image -->)#s';

		return preg_replace_callback( $pattern, function ( $block_match ) use ( $resolved ) {
			$json_str        = $block_match[2];
			$inner_html      = $block_match[3];
			$closing_comment = $block_match[4];

			$attrs    = json_decode( $json_str, true );
			$modified = false;

			foreach ( $resolved as $resized_url => $replacement ) {
				if ( false === strpos( $inner_html, $resized_url ) && false === strpos( $inner_html, $this->normalize_protocol( $resized_url ) ) ) {
					continue;
				}

				if ( isset( $this->processed_urls[ $resized_url ] ) ) {
					continue;
				}

				$this->processed_urls[ $resized_url ]           = true;
				$replacement['markup_type']                      = 'gutenberg';
				$this->img_replacement_map[ $resized_url ]      = $replacement;

				if ( is_array( $attrs ) ) {
					$attrs['sizeSlug'] = 'full';
					unset( $attrs['width'], $attrs['height'] );
					$modified = true;
				}

				$inner_html = $this->update_img_tag( $inner_html, $resized_url, $replacement );

				if ( preg_match( '/\bsize-[\w-]+/', $inner_html ) ) {
					$inner_html = preg_replace( '/\bsize-[\w-]+/', 'size-full', $inner_html, 1 );
				} else {
					$inner_html = preg_replace(
						'/class="wp-block-image/',
						'class="wp-block-image size-full',
						$inner_html,
						1
					);
				}
			}

			if ( ! $modified ) {
				return $block_match[0];
			}

			$new_json    = wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES );
			$new_opening = '<!-- wp:image ' . $new_json . ' -->';

			return $new_opening . $inner_html . $closing_comment;
		}, $content );
	}

	private function process_caption_shortcodes( $content, $resolved ) {
		$pattern = '#(\[caption\s[^\]]*\])(.*?)(\[/caption\])#s';

		return preg_replace_callback( $pattern, function ( $sc_match ) use ( $resolved ) {
			$opening  = $sc_match[1];
			$inner    = $sc_match[2];
			$closing  = $sc_match[3];
			$modified = false;

			foreach ( $resolved as $resized_url => $replacement ) {
				if ( false === strpos( $inner, $resized_url ) ) {
					continue;
				}

				if ( isset( $this->processed_urls[ $resized_url ] ) ) {
					continue;
				}

				$this->processed_urls[ $resized_url ]      = true;
				$replacement['markup_type']                 = 'caption_shortcode';
				$this->img_replacement_map[ $resized_url ] = $replacement;

				$inner    = $this->update_img_tag( $inner, $resized_url, $replacement );
				$modified = true;
			}

			if ( ! $modified ) {
				return $sc_match[0];
			}

			$opening = preg_replace( '/\s+width="\d+"/', '', $opening );

			return $opening . $inner . $closing;
		}, $content );
	}

	private function process_classic_imgs( $content, $resolved ) {
		$pattern = '#<img\s[^>]*class="[^"]*wp-image-\d+[^"]*"[^>]*/?\s*>#i';

		return preg_replace_callback( $pattern, function ( $img_match ) use ( $resolved ) {
			$img_tag  = $img_match[0];
			$modified = false;

			foreach ( $resolved as $resized_url => $replacement ) {
				if ( false === strpos( $img_tag, $resized_url ) ) {
					continue;
				}

				if ( isset( $this->processed_urls[ $resized_url ] ) ) {
					continue;
				}

				$this->processed_urls[ $resized_url ]      = true;
				$replacement['markup_type']                 = 'classic_img';
				$this->img_replacement_map[ $resized_url ] = $replacement;

				$img_tag  = $this->update_img_tag( $img_tag, $resized_url, $replacement, true );
				$modified = true;
			}

			return $modified ? $img_tag : $img_match[0];
		}, $content );
	}

	private function process_raw_imgs( $content, $resolved ) {
		$pattern = '#<img\s[^>]*/?\s*>#i';

		return preg_replace_callback( $pattern, function ( $img_match ) use ( $resolved ) {
			$img_tag  = $img_match[0];
			$modified = false;

			foreach ( $resolved as $resized_url => $replacement ) {
				if ( false === strpos( $img_tag, $resized_url ) ) {
					continue;
				}

				if ( isset( $this->processed_urls[ $resized_url ] ) ) {
					continue;
				}

				$this->processed_urls[ $resized_url ]      = true;
				$replacement['markup_type']                 = 'raw_img';
				$this->img_replacement_map[ $resized_url ] = $replacement;

				$img_tag  = $this->update_img_tag( $img_tag, $resized_url, $replacement, false );
				$modified = true;
			}

			return $modified ? $img_tag : $img_match[0];
		}, $content );
	}

	private function process_anchor_hrefs( $content, $resolved ) {
		if ( empty( $this->img_replacement_map ) ) {
			return $content;
		}

		$pattern = '#<a\s([^>]*href="([^"]+)"[^>]*)>\s*(<img\s[^>]*/?\s*>)\s*</a>#i';

		return preg_replace_callback( $pattern, function ( $a_match ) use ( $resolved ) {
			$a_attrs = $a_match[1];
			$href    = $a_match[2];
			$img_tag = $a_match[3];

			foreach ( $this->img_replacement_map as $resized_url => $replacement ) {
				if ( false === strpos( $img_tag, $replacement['to_url'] ) ) {
					continue;
				}

				$href_normalized = $this->normalize_protocol( $href );
				$base_pattern    = preg_quote( pathinfo( $replacement['to_url'], PATHINFO_DIRNAME ) . '/' . pathinfo( $replacement['to_url'], PATHINFO_FILENAME ), '#' );

				if ( preg_match( '#^' . $base_pattern . '-(\d+x\d+|scaled)\.(jpe?g|png|gif|webp|avif)$#i', $href_normalized ) ) {
					$new_a_attrs = str_replace( $href, $replacement['to_url'], $a_attrs );
					return '<a ' . $new_a_attrs . '>' . $img_tag . '</a>';
				}
			}

			return $a_match[0];
		}, $content );
	}

	// ──────────────────────────────────────────────────────────────────
	// Private: <img> tag updater
	// ──────────────────────────────────────────────────────────────────

	private function update_img_tag( $html, $resized_url, $replacement, $update_size_class = false ) {
		$to_url     = $replacement['to_url'];
		$dimensions = $replacement['to_dimensions'];

		$html = str_replace( $resized_url, $to_url, $html );

		if ( $dimensions ) {
			$html = preg_replace( '/\bwidth="\d+"/', 'width="' . $dimensions['width'] . '"', $html );
			$html = preg_replace( '/\bheight="\d+"/', 'height="' . $dimensions['height'] . '"', $html );
		}

		$html = preg_replace( '/\s+srcset="[^"]*"/', '', $html );
		$html = preg_replace( '/\s+data-srcset="[^"]*"/', '', $html );
		$html = preg_replace( '/\s+sizes="[^"]*"/', '', $html );

		foreach ( $this->lazy_attrs as $attr ) {
			if ( false !== strpos( $html, $attr . '="' ) ) {
				$html = preg_replace(
					'/' . preg_quote( $attr, '/' ) . '="[^"]*' . preg_quote( $resized_url, '/' ) . '[^"]*"/',
					$attr . '="' . esc_attr( $to_url ) . '"',
					$html
				);
			}
		}

		$html = preg_replace(
			'/\s+style="[^"]*?\b(width|height)\s*:\s*\d+px[^"]*?"/',
			'',
			$html
		);

		if ( $update_size_class ) {
			$html = preg_replace( '/\bsize-[\w-]+/', 'size-full', $html );
		}

		return $html;
	}

	// ──────────────────────────────────────────────────────────────────
	// Private: Filename-match pass
	// ──────────────────────────────────────────────────────────────────

	private function find_nonuploads_imgs( $content, $resized_matches ) {
		$found = array();

		if ( ! preg_match_all( '#<img\s[^>]*src=["\']([^"\']+)["\'][^>]*>#i', $content, $img_matches ) ) {
			return $found;
		}

		$site_host    = wp_parse_url( site_url(), PHP_URL_HOST );
		$uploads_base = $this->normalize_protocol( $this->uploads_baseurl );

		$already_matched = array();
		foreach ( $resized_matches as $url => $info ) {
			$already_matched[ $url ] = true;
		}

		$checked_filenames = array();

		foreach ( array_unique( $img_matches[1] ) as $src_url ) {
			if ( isset( $already_matched[ $src_url ] ) ) {
				continue;
			}

			$src_host = wp_parse_url( $src_url, PHP_URL_HOST );
			if ( $src_host && strtolower( $src_host ) !== strtolower( $site_host ) ) {
				continue;
			}

			$normalized_src = $this->normalize_protocol( $src_url );
			if ( 0 === strpos( $normalized_src, $uploads_base ) ) {
				if ( ! preg_match( '#-\d+x\d+\.(jpe?g|png|gif|webp|avif)$#i', $src_url )
					&& ! preg_match( '#-scaled\.(jpe?g|png|gif|webp|avif)$#i', $src_url ) ) {
					$relative_path = substr( $normalized_src, strlen( $uploads_base ) + 1 );
					if ( preg_match( '#^\d{4}/\d{2}/#', $relative_path ) ) {
						continue;
					}
				}
			}

			if ( ! preg_match( '#\.(jpe?g|png|gif|webp|avif)(\?.*)?$#i', $src_url ) ) {
				continue;
			}

			$filename = wp_basename( wp_parse_url( $src_url, PHP_URL_PATH ) );
			if ( empty( $filename ) || isset( $checked_filenames[ $filename ] ) ) {
				if ( isset( $checked_filenames[ $filename ] ) && ! empty( $checked_filenames[ $filename ] ) ) {
					$found[ $src_url ] = $checked_filenames[ $filename ];
				}
				continue;
			}

			$candidate_ids                     = $this->lookup_attachments_by_filename( $filename );
			$checked_filenames[ $filename ]    = $candidate_ids;

			if ( ! empty( $candidate_ids ) ) {
				$valid_candidates = array();
				foreach ( $candidate_ids as $att_id ) {
					$att_url = wp_get_attachment_url( $att_id );
					if ( $att_url && $this->normalize_protocol( $att_url ) !== $normalized_src ) {
						$valid_candidates[] = $att_id;
					}
				}

				if ( ! empty( $valid_candidates ) ) {
					$found[ $src_url ] = $valid_candidates;
				}
			}
		}

		return $found;
	}

	private function resolve_filename_match( $from_url, $candidate_ids, $selections = array() ) {
		$replacement = array(
			'attachment_id'        => null,
			'original_filename'    => null,
			'from_url'             => $from_url,
			'to_url'               => null,
			'from_size'            => 'nonstandard_path',
			'to_dimensions'        => null,
			'markup_type'          => 'filename_match',
			'format_variant_used'  => null,
			'link_href_updated'    => false,
			'skipped'              => false,
			'skip_reason'          => null,
			'excluded'             => false,
			'candidates'           => array(),
		);

		$valid_candidates = array();
		foreach ( $candidate_ids as $att_id ) {
			$att_url = wp_get_attachment_url( $att_id );
			if ( ! $att_url ) {
				continue;
			}

			$candidate = array(
				'attachment_id' => (int) $att_id,
				'to_url'        => $att_url,
				'to_dimensions' => null,
				'filename'      => get_post_meta( $att_id, '_wp_attached_file', true ),
			);

			$meta = wp_get_attachment_metadata( $att_id );
			if ( $meta && isset( $meta['width'], $meta['height'] ) ) {
				$candidate['to_dimensions'] = array(
					'width'  => (int) $meta['width'],
					'height' => (int) $meta['height'],
				);
			}

			if ( $this->verify_file_exists( $att_url, $att_id ) ) {
				$valid_candidates[] = $candidate;
			}
		}

		if ( empty( $valid_candidates ) ) {
			$replacement['skipped']     = true;
			$replacement['skip_reason'] = 'file_missing';
			return $replacement;
		}

		$replacement['candidates'] = $valid_candidates;

		$chosen = $valid_candidates[0];

		if ( ! empty( $selections[ $from_url ] ) ) {
			$chosen_id = (int) $selections[ $from_url ];
			foreach ( $valid_candidates as $c ) {
				if ( $c['attachment_id'] === $chosen_id ) {
					$chosen = $c;
					break;
				}
			}
		}

		$replacement['attachment_id']     = $chosen['attachment_id'];
		$replacement['to_url']            = $chosen['to_url'];
		$replacement['to_dimensions']     = $chosen['to_dimensions'];
		$replacement['original_filename'] = wp_basename( $chosen['to_url'] );

		return $replacement;
	}

	private function lookup_attachments_by_filename( $filename ) {
		global $wpdb;

		$like_pattern = '%/' . $wpdb->esc_like( $filename );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = '_wp_attached_file'
				AND ( meta_value = %s OR meta_value LIKE %s )
				LIMIT 10",
				$filename,
				$like_pattern
			)
		);

		return ! empty( $post_ids ) ? array_map( 'intval', $post_ids ) : array();
	}

	private function normalize_protocol( $url ) {
		return preg_replace( '#^https?://#i', '//', $url );
	}

	// ──────────────────────────────────────────────────────────────────
	// Public: URL ↔ attachment alias map (Upload-replacement flow)
	//
	// When the automatic lookup chain can't resolve a URL but the user
	// supplies the correct attachment manually, we persist that mapping
	// in a WP option so future scans / lookups resolve transparently.
	// ──────────────────────────────────────────────────────────────────

	const URL_ALIAS_OPTION = 'image_kit_url_aliases';

	/**
	 * Persist a URL → attachment ID mapping. URL is protocol-normalised
	 * so http/https variants don't double-store.
	 *
	 * @param string $url           URL as it appears in post content.
	 * @param int    $attachment_id The attachment the user mapped it to.
	 * @return bool
	 */
	public function record_url_alias( $url, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id || ! $url ) {
			return false;
		}
		$key     = $this->normalize_protocol( $url );
		$aliases = get_option( self::URL_ALIAS_OPTION, array() );
		if ( ! is_array( $aliases ) ) {
			$aliases = array();
		}
		$aliases[ $key ] = $attachment_id;
		update_option( self::URL_ALIAS_OPTION, $aliases, true );

		// Invalidate the in-memory cache so a re-audit in the same request
		// picks up the new mapping.
		unset( $this->attachment_cache[ $key ] );
		return true;
	}

	/**
	 * Look up a previously-recorded alias for a URL.
	 *
	 * @param string $url URL as it appears in post content.
	 * @return int Attachment ID, or 0 if no alias / attachment no longer exists.
	 */
	public function lookup_alias( $url ) {
		if ( ! $url ) {
			return 0;
		}
		$aliases = get_option( self::URL_ALIAS_OPTION, array() );
		if ( ! is_array( $aliases ) ) {
			return 0;
		}
		$key = $this->normalize_protocol( $url );
		if ( empty( $aliases[ $key ] ) ) {
			return 0;
		}
		$attachment_id = (int) $aliases[ $key ];
		// Guard against stale aliases pointing at deleted attachments.
		if ( ! get_post( $attachment_id ) ) {
			return 0;
		}
		return $attachment_id;
	}

	// ──────────────────────────────────────────────────────────────────
	// Private: Audit mode helpers
	// ──────────────────────────────────────────────────────────────────

	private function find_incomplete_blocks( $content ) {
		$findings = array();
		$pattern  = '#<!-- wp:image\s*(\{(?:[^{}]|\{[^{}]*\})*\})?\s*-->(.*?)<!-- /wp:image -->#s';

		if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			return $findings;
		}

		foreach ( $matches as $match ) {
			$json_str   = isset( $match[1] ) ? $match[1] : '';
			$inner_html = $match[2];
			$attrs      = json_decode( $json_str, true );

			if ( ! is_array( $attrs ) ) {
				$attrs = array();
			}

			if ( ! preg_match( '#<img\s[^>]*src="([^"]+)"#i', $inner_html, $img_match ) ) {
				continue;
			}
			$src_url = $img_match[1];

			$img_class = '';
			if ( preg_match( '#<img\s[^>]*class="([^"]*)"#i', $inner_html, $class_match ) ) {
				$img_class = $class_match[1];
			}

			$figure_class = '';
			if ( preg_match( '#<figure\s[^>]*class="([^"]*)"#i', $inner_html, $fig_match ) ) {
				$figure_class = $fig_match[1];
			}

			$issues = array();

			if ( empty( $attrs['id'] ) ) {
				$issues[] = 'missing_id';
			}
			if ( empty( $attrs['sizeSlug'] ) ) {
				$issues[] = 'missing_sizeSlug';
			}
			if ( ! preg_match( '/\bwp-image-\d+\b/', $img_class ) ) {
				$issues[] = 'missing_wp_image_class';
			}
			if ( ! preg_match( '/\bsize-[\w-]+\b/', $figure_class ) ) {
				$issues[] = 'missing_size_class';
			}

			if ( empty( $issues ) ) {
				continue;
			}

			$findings[] = array(
				'src_url'    => $src_url,
				'block_json' => $attrs,
				'issues'     => $issues,
				'inner_html' => $inner_html,
			);
		}

		return $findings;
	}

	private function determine_link_destination( $inner_html, $attachment_id ) {
		if ( ! preg_match( '#<a\s[^>]*href="([^"]+)"#i', $inner_html, $a_match ) ) {
			return 'none';
		}

		$href            = $a_match[1];
		$normalized_href = $this->normalize_protocol( $href );

		$attachment_url = wp_get_attachment_url( $attachment_id );
		if ( $attachment_url && $this->normalize_protocol( $attachment_url ) === $normalized_href ) {
			return 'media';
		}

		$attachment_page = get_attachment_link( $attachment_id );
		if ( $attachment_page && $this->normalize_protocol( $attachment_page ) === $normalized_href ) {
			return 'attachment';
		}

		return 'custom';
	}

	private function apply_audit_fixes( $content, $findings ) {
		$by_src = array();
		foreach ( $findings as $f ) {
			$by_src[ $f['from_url'] ] = $f;
		}

		$pattern = '#(<!-- wp:image\s*(\{(?:[^{}]|\{[^{}]*\})*\})?\s*-->)(.*?)(<!-- /wp:image -->)#s';

		return preg_replace_callback( $pattern, function ( $match ) use ( $by_src ) {
			$json_str        = isset( $match[2] ) ? $match[2] : '';
			$inner_html      = $match[3];
			$closing_comment = $match[4];

			if ( ! preg_match( '#<img\s[^>]*src="([^"]+)"#i', $inner_html, $img_match ) ) {
				return $match[0];
			}
			$src_url = $img_match[1];

			if ( ! isset( $by_src[ $src_url ] ) ) {
				return $match[0];
			}

			$finding       = $by_src[ $src_url ];
			$attachment_id = $finding['attachment_id'];
			$proposed      = $finding['proposed_block_json'];

			$attrs = json_decode( $json_str, true );
			if ( ! is_array( $attrs ) ) {
				$attrs = array();
			}

			foreach ( $proposed as $key => $value ) {
				if ( ! isset( $attrs[ $key ] ) || ( 'id' === $key && empty( $attrs[ $key ] ) ) ) {
					$attrs[ $key ] = $value;
				}
			}

			if ( in_array( 'missing_wp_image_class', $finding['issues'], true ) ) {
				$wp_image_class = 'wp-image-' . $attachment_id;

				if ( preg_match( '#(<img\s[^>]*?)class="([^"]*)"#i', $inner_html, $cm ) ) {
					$new_class  = trim( $cm[2] . ' ' . $wp_image_class );
					$inner_html = str_replace( $cm[0], $cm[1] . 'class="' . $new_class . '"', $inner_html );
				} else {
					$inner_html = preg_replace(
						'#<img\s#i',
						'<img class="' . $wp_image_class . '" ',
						$inner_html,
						1
					);
				}
			}

			if ( in_array( 'missing_size_class', $finding['issues'], true ) ) {
				if ( preg_match( '#(<figure\s[^>]*?)class="([^"]*)"#i', $inner_html, $fm ) ) {
					$new_class  = trim( $fm[2] . ' size-full' );
					$inner_html = str_replace( $fm[0], $fm[1] . 'class="' . $new_class . '"', $inner_html );
				}
			}

			$new_json    = wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES );
			$new_opening = '<!-- wp:image ' . $new_json . ' -->';

			return $new_opening . $inner_html . $closing_comment;
		}, $content );
	}
}
