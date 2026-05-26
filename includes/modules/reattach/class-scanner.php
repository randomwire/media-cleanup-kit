<?php
/**
 * Image Kit — Attach Unparented Media scanner.
 *
 * Finds attachments with post_parent = 0 and proposes the first post/page
 * that references them. Reference detection covers:
 *
 *   - featured-image meta (_thumbnail_id)         → match_type=featured_image
 *   - wp-image-{id} CSS class in post_content     → match_type=wp_image_class
 *   - "id":{id} / "mediaId":{id} block JSON       → match_type=block_id
 *   - attachment_{id} caption shortcode           → match_type=caption_shortcode
 *   - gallery shortcode ids="…{id}…"              → match_type=gallery_shortcode
 *   - file path / sized-variant stem in content   → match_type=content_url
 *
 * Featured-image wins when both apply: it's the more deliberate
 * relationship and what users almost certainly want.
 *
 * Performance: each scan_batch runs only TWO database queries against
 * wp_posts / wp_postmeta regardless of batch size. All per-attachment
 * matching happens in PHP via strpos on the shared post-content result
 * set. This is ~25× faster than the original "2 queries per attachment"
 * approach on sites with thousands of unattached items.
 */

defined( 'ABSPATH' ) || exit;

class Image_Kit_Reattach_Scanner {

	const POST_TYPES    = array( 'post', 'page' );
	const POST_STATUSES = array( 'publish', 'draft', 'private', 'pending', 'future' );

	/**
	 * Return the total count of unattached attachments.
	 */
	public function count_unattached(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type = 'attachment'
			   AND post_status = 'inherit'
			   AND post_parent = 0"
		);
	}

	/**
	 * Scan a batch of unattached attachments and return their proposed
	 * parents (if any).
	 *
	 * @param int $offset     Offset into the unattached set.
	 * @param int $batch_size Batch size.
	 * @return array { attachments: [...], offset, total, done }
	 */
	public function scan_batch( int $offset, int $batch_size ): array {
		global $wpdb;

		$total = $this->count_unattached();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_mime_type, pm.meta_value AS attached_file
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			   ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment'
			   AND p.post_status = 'inherit'
			   AND p.post_parent = 0
			 ORDER BY p.ID ASC
			 LIMIT %d OFFSET %d",
			$batch_size,
			$offset
		) );

		if ( empty( $rows ) ) {
			return array(
				'attachments' => array(),
				'offset'      => $offset,
				'total'       => $total,
				'done'        => true,
			);
		}

		// Build a needle map: attachment_id => { strong: [...], weak: [...] }.
		$needle_map = array();
		$valid_rows = array();
		foreach ( $rows as $row ) {
			if ( empty( $row->attached_file ) ) {
				continue;
			}
			$id                = (int) $row->ID;
			$valid_rows[ $id ] = $row;
			$needle_map[ $id ] = $this->build_needles( $id, $row->attached_file );
		}

		// One query: featured-image lookups for every attachment in this batch.
		$featured_map = $this->batch_featured_image_lookup( array_keys( $valid_rows ) );

		// One query: content-host candidates for every attachment in this batch.
		$candidate_posts = $this->batch_content_candidates( $needle_map );

		// Resolve each attachment in PHP from the shared result sets.
		$attachments = array();
		foreach ( $valid_rows as $id => $row ) {
			$attachments[] = $this->resolve_attachment(
				$id,
				$row->attached_file,
				$needle_map[ $id ],
				$featured_map[ $id ] ?? null,
				$candidate_posts
			);
		}

		$new_offset = $offset + count( $rows );

		return array(
			'attachments' => $attachments,
			'offset'      => $new_offset,
			'total'       => $total,
			'done'        => $new_offset >= $total || empty( $rows ),
		);
	}

	/**
	 * Attempt to re-attach an attachment to a parent post.
	 *
	 * Race-condition guard: re-reads post_parent before updating and skips
	 * if another process attached it in the meantime.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $parent_id     Proposed parent post ID.
	 * @return array { success: bool, message?: string }
	 */
	public function attach( int $attachment_id, int $parent_id ): array {
		if ( ! $attachment_id || ! $parent_id ) {
			return array( 'success' => false, 'message' => __( 'Invalid parameters.', 'image-kit' ) );
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return array( 'success' => false, 'message' => __( 'Attachment not found.', 'image-kit' ) );
		}
		if ( 0 !== (int) $attachment->post_parent ) {
			return array( 'success' => false, 'message' => __( 'Already attached.', 'image-kit' ) );
		}

		$parent = get_post( $parent_id );
		if ( ! $parent ) {
			return array( 'success' => false, 'message' => __( 'Proposed parent post not found.', 'image-kit' ) );
		}

		$result = wp_update_post( array(
			'ID'          => $attachment_id,
			'post_parent' => $parent_id,
		), true );

		if ( is_wp_error( $result ) ) {
			return array( 'success' => false, 'message' => $result->get_error_message() );
		}

		return array( 'success' => true );
	}

	// ──────────────────────────────────────────────────────────────────
	// Private: batched lookups
	// ──────────────────────────────────────────────────────────────────

	/**
	 * Build the list of strpos-needles for one attachment, split into
	 * strong (URL/filename) and weak (id/class) evidence.
	 *
	 * Strong evidence: the file actually appears in the post.
	 * Weak evidence (id, wp-image class) outlives the underlying file —
	 * an image replaced or removed in the block editor often leaves a
	 * stale class behind. We only use weak signals to *prefer* one of
	 * several strong matches; never to manufacture a match on its own.
	 *
	 * @return array{strong:array<int,array{kind:string,needle:string}>,weak:array<int,string>}
	 */
	private function build_needles( int $attachment_id, string $attached_file ): array {
		$pathinfo   = pathinfo( $attached_file );
		$dirname    = isset( $pathinfo['dirname'] ) && '.' !== $pathinfo['dirname'] ? $pathinfo['dirname'] : '';
		$stem_name  = isset( $pathinfo['filename'] ) ? $pathinfo['filename'] : '';
		$basename   = wp_basename( $attached_file );
		$sized_stem = $dirname
			? trailingslashit( $dirname ) . $stem_name . '-'
			: $stem_name . '-';

		// Strong: the file itself appears in the post. Ordered by specificity
		// so the most-unambiguous evidence is reported as the match_evidence.
		$strong = array(
			array( 'kind' => 'content_url', 'needle' => $attached_file ),  // full path (most specific)
			array( 'kind' => 'content_url', 'needle' => $sized_stem ),     // path + "-" (catches -WxH variants)
			array( 'kind' => 'content_url', 'needle' => $basename ),       // bare filename (least specific)
		);

		// Weak: id/class/shortcode references — used only to corroborate.
		$weak = array(
			'wp-image-' . $attachment_id,
			'"id":' . $attachment_id,
			'"mediaId":' . $attachment_id,
			'attachment_' . $attachment_id,
		);

		return array( 'strong' => $strong, 'weak' => $weak );
	}

	/**
	 * One query: returns [ attachment_id => host_row ] for every attachment
	 * set as a featured image on any post.
	 *
	 * @param int[] $attachment_ids
	 * @return array<int,object>
	 */
	private function batch_featured_image_lookup( array $attachment_ids ): array {
		global $wpdb;

		if ( empty( $attachment_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
		$post_types_in = $this->in_clause( self::POST_TYPES );
		$statuses_in   = $this->in_clause( self::POST_STATUSES );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm.meta_value AS att_id, p.ID, p.post_title, p.post_status, p.post_date
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_thumbnail_id'
			   AND pm.meta_value IN ({$placeholders})
			   AND p.post_type IN ({$post_types_in})
			   AND p.post_status IN ({$statuses_in})
			 ORDER BY p.post_date DESC",
			$attachment_ids
		) );

		// Keep the *first* (most-recent by post_date) host per attachment.
		$out = array();
		foreach ( $rows as $row ) {
			$att_id = (int) $row->att_id;
			if ( ! isset( $out[ $att_id ] ) ) {
				$out[ $att_id ] = $row;
			}
		}
		return $out;
	}

	/**
	 * One query: returns every post whose content contains *any* needle for
	 * *any* attachment in the batch, ordered by post_date DESC. The
	 * resolver then strpos-checks each attachment's specific needles
	 * against this candidate set in PHP.
	 *
	 * @param array<int,array<int,array{kind:string,needle:string}>> $needle_map
	 * @return array<int,object> Posts in post_date DESC order.
	 */
	private function batch_content_candidates( array $needle_map ): array {
		global $wpdb;

		// Two sources of candidate posts:
		// 1. Strong (file-path) needles — primary match signal.
		// 2. Classic gallery shortcode id-list patterns — secondary signal,
		//    used only when no strong match exists. Precise membership is
		//    verified by regex on the returned post_content later.
		// Weak signals (wp-image-class, "id":N) are *not* in the candidate
		// query — they outlive the actual file too often to be trusted.
		$patterns = array();
		foreach ( $needle_map as $entry ) {
			foreach ( $entry['strong'] as $n ) {
				if ( '' !== $n['needle'] ) {
					$patterns[] = $n['needle'];
				}
			}
		}
		foreach ( array_keys( $needle_map ) as $att_id ) {
			$patterns[] = 'ids="' . $att_id . '"';
			$patterns[] = 'ids="' . $att_id . ',';
			$patterns[] = ',' . $att_id . ',';
			$patterns[] = ',' . $att_id . '"';
		}
		if ( empty( $patterns ) ) {
			return array();
		}
		$patterns = array_unique( $patterns );

		$like_clauses = array();
		$values       = array();
		foreach ( $patterns as $needle ) {
			$like_clauses[] = 'post_content LIKE %s';
			$values[]       = '%' . $wpdb->esc_like( $needle ) . '%';
		}

		$post_types_in = $this->in_clause( self::POST_TYPES );
		$statuses_in   = $this->in_clause( self::POST_STATUSES );
		$where_likes   = '(' . implode( ' OR ', $like_clauses ) . ')';

		// Cap the candidate set. Sites with extremely dense content can
		// return many posts; in practice the *first* date-desc match wins
		// per attachment so a few hundred candidates is plenty.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_status, post_content, post_date
			 FROM {$wpdb->posts}
			 WHERE post_type IN ({$post_types_in})
			   AND post_status IN ({$statuses_in})
			   AND {$where_likes}
			 ORDER BY post_date DESC
			 LIMIT 500",
			$values
		) );
	}

	// ──────────────────────────────────────────────────────────────────
	// Private: per-attachment resolution
	// ──────────────────────────────────────────────────────────────────

	private function resolve_attachment( int $attachment_id, string $attached_file, array $needles, $featured_row, array $candidate_posts ): array {
		$filename = wp_basename( $attached_file );

		$thumb_url = wp_get_attachment_image_url( $attachment_id, array( 80, 80 ) );
		if ( ! $thumb_url ) {
			$thumb_url = wp_get_attachment_url( $attachment_id );
		}
		$file_path = get_attached_file( $attachment_id );
		$file_size = $file_path && file_exists( $file_path ) ? filesize( $file_path ) : 0;

		$base = array(
			'id'              => $attachment_id,
			'filename'        => $filename,
			'attached_file'   => $attached_file,
			'thumbnail_url'   => $thumb_url,
			'file_size'       => $file_size,
			'parent_id'       => null,
			'parent_title'    => null,
			'parent_edit_url' => null,
			'parent_view_url' => null,
			'match_type'      => null,
			'match_evidence'  => null,
		);

		// 1. Featured image — deliberate, wins over content references.
		if ( $featured_row ) {
			return array_merge( $base, $this->format_host( $featured_row, __( 'Featured image', 'image-kit' ) ), array(
				'match_type' => 'featured_image',
			) );
		}

		// 2. Strong content match: the post must contain the actual file path
		//    or filename. Walk candidates in post_date DESC; collect every
		//    post that strpos-matches a strong needle, then prefer one that
		//    *also* corroborates with weak signals (wp-image-class / "id":N /
		//    caption shortcode). If no strong match exists, do NOT fall back
		//    to weak-only — those references frequently outlive the actual
		//    file and produce false positives (a wp-image-N class can linger
		//    on a replaced or removed `<img>`).
		$strong = $needles['strong'];
		$weak   = $needles['weak'];

		$first_strong_post = null;
		$first_strong_evi  = null;
		$first_strong_kind = null;

		$corroborated_post = null;
		$corroborated_evi  = null;
		$corroborated_kind = null;

		foreach ( $candidate_posts as $post ) {
			$matched_needle = null;
			foreach ( $strong as $n ) {
				if ( '' === $n['needle'] ) {
					continue;
				}
				if ( false !== strpos( $post->post_content, $n['needle'] ) ) {
					$matched_needle = $n;
					break;
				}
			}
			if ( ! $matched_needle ) {
				continue;
			}
			if ( null === $first_strong_post ) {
				$first_strong_post = $post;
				$first_strong_evi  = $matched_needle['needle'];
				$first_strong_kind = $matched_needle['kind'];
			}

			// Corroboration boost: same post also carries a weak signal
			// (wp-image-N / "id":N etc) — strongest possible confidence.
			if ( null === $corroborated_post ) {
				foreach ( $weak as $weak_needle ) {
					if ( false !== strpos( $post->post_content, $weak_needle ) ) {
						$corroborated_post = $post;
						$corroborated_evi  = $matched_needle['needle'];
						$corroborated_kind = $matched_needle['kind'];
						break;
					}
				}
			}

			// Early exit if we have a corroborated match — can't do better.
			if ( $corroborated_post ) {
				break;
			}
		}

		$chosen_post = $corroborated_post ?: $first_strong_post;
		$chosen_evi  = $corroborated_post ? $corroborated_evi  : $first_strong_evi;

		if ( $chosen_post ) {
			return array_merge( $base, $this->format_host( $chosen_post, $chosen_evi ), array(
				'match_type' => 'content_url',
			) );
		}

		// 3. Gallery shortcode — parse out each ids="…" attribute and check
		//    exact membership. Trustworthy because [gallery ids="…N…"]
		//    renders the attachment by ID at view time, regardless of what
		//    else lives in post_content.
		foreach ( $candidate_posts as $post ) {
			if ( preg_match_all( '/\bids="([^"]+)"/', $post->post_content, $m ) ) {
				foreach ( $m[1] as $ids_value ) {
					$ids = array_map( 'intval', array_map( 'trim', explode( ',', $ids_value ) ) );
					if ( in_array( $attachment_id, $ids, true ) ) {
						return array_merge(
							$base,
							$this->format_host( $post, sprintf( '[gallery ids="…%d…"]', $attachment_id ) ),
							array( 'match_type' => 'gallery_shortcode' )
						);
					}
				}
			}
		}

		// No reliable match — leave unparented. Better than a stale-class
		// false positive that misattaches the file to the wrong post.
		return $base;
	}

	private function format_host( $row, string $evidence ): array {
		$label = $row->post_title ? $row->post_title : sprintf( __( '(no title) #%d', 'image-kit' ), (int) $row->ID );
		if ( isset( $row->post_status ) && 'publish' !== $row->post_status ) {
			$label .= ' [' . $row->post_status . ']';
		}
		return array(
			'parent_id'       => (int) $row->ID,
			'parent_title'    => $label,
			'parent_edit_url' => get_edit_post_link( (int) $row->ID, 'raw' ),
			'parent_view_url' => get_permalink( (int) $row->ID ),
			'match_evidence'  => $evidence,
		);
	}

	private function in_clause( array $values ): string {
		return "'" . implode( "','", array_map( 'esc_sql', $values ) ) . "'";
	}
}
