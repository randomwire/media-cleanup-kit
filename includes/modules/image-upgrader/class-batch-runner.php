<?php
/**
 * Image Kit — Image Upgrader batch runner.
 *
 * Handles both the scan phase (dry_run=true, collecting replacement data)
 * and the apply phase (writing changes via wp_update_post).
 */

defined( 'ABSPATH' ) || exit;

class Image_Kit_Image_Upgrader_Batch_Runner {

	/** @var int Posts per AJAX batch. */
	const BATCH_SIZE = 10;

	/** @var Image_Kit_Core_Run_Log */
	private $run_log;

	/** @var Image_Kit_Image_Upgrader_Scanner */
	private $scanner;

	/**
	 * @param Image_Kit_Core_Run_Log $run_log Run log instance.
	 */
	public function __construct( Image_Kit_Core_Run_Log $run_log ) {
		$this->run_log = $run_log;
		$this->scanner = new Image_Kit_Image_Upgrader_Scanner();
	}

	/**
	 * Get total number of posts to process.
	 *
	 * @param array $post_types Array of post type slugs.
	 * @return int
	 */
	public function get_total_posts( $post_types ) {
		$query = new WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
				'fields'         => 'ids',
			)
		);

		return $query->found_posts;
	}

	/**
	 * Process a scan batch (dry run).
	 *
	 * @param int   $run_id      Run ID.
	 * @param int   $offset      Current offset.
	 * @param array $post_types  Post types to scan.
	 * @param int   $total_posts Total posts.
	 * @return array
	 */
	public function process_scan_batch( $run_id, $offset, $post_types, $total_posts = 0 ) {
		$run = $this->run_log->get_run( $run_id );
		if ( ! $run || 'running' !== $run->status ) {
			return array(
				'success' => false,
				'message' => __( 'Run is not in a valid state for scanning.', 'media-cleanup-kit' ),
			);
		}

		$post_ids = $this->get_post_ids( $post_types, $offset, self::BATCH_SIZE );
		$total    = $total_posts > 0 ? $total_posts : $this->get_total_posts( $post_types );
		$done     = ( $offset + count( $post_ids ) ) >= $total;

		$batch_replaced = 0;
		$batch_skipped  = 0;
		$batch_updated  = 0;
		$batch_errors   = 0;
		$log_lines      = array();
		$is_first_post  = true;

		foreach ( $post_ids as $post_id ) {
			if ( $is_first_post ) {
				$is_first_post = false;
			} else {
				usleep( 50000 );
			}

			$post = get_post( $post_id );

			try {
				$scan_result = $this->scanner->scan_post( $post_id, true );
			} catch ( \Exception $e ) {
				$scan_result = array(
					'post_id'         => $post_id,
					'images_replaced' => 0,
					'images_skipped'  => 0,
					'replacements'    => array(),
					'error_message'   => $e->getMessage(),
				);
				$batch_errors++;
			}

			$has_activity = $scan_result['images_replaced'] > 0
				|| $scan_result['images_skipped'] > 0
				|| ! empty( $scan_result['error_message'] );

			if ( $has_activity ) {
				$this->run_log->insert_item(
					array(
						'run_id'          => $run_id,
						'post_id'         => $post_id,
						'post_title'      => $post ? $post->post_title : '',
						'images_replaced' => $scan_result['images_replaced'],
						'images_skipped'  => $scan_result['images_skipped'],
						'replacements'    => $scan_result['replacements'],
						'error_message'   => $scan_result['error_message'],
					)
				);
			}

			$batch_replaced += $scan_result['images_replaced'];
			$batch_skipped  += $scan_result['images_skipped'];

			if ( $scan_result['images_replaced'] > 0 ) {
				$batch_updated++;
			}

			$log_lines[] = array(
				'post_id'  => $post_id,
				'title'    => $post ? $post->post_title : sprintf( __( 'Post #%d', 'media-cleanup-kit' ), $post_id ),
				'replaced' => $scan_result['images_replaced'],
				'skipped'  => $scan_result['images_skipped'],
			);
		}

		$this->run_log->update_run(
			$run_id,
			array(
				'posts_scanned'   => $run->posts_scanned + count( $post_ids ),
				'images_replaced' => $run->images_replaced + $batch_replaced,
				'images_skipped'  => $run->images_skipped + $batch_skipped,
			)
		);

		if ( $done ) {
			$this->run_log->update_run(
				$run_id,
				array(
					'status'             => 'pending_review',
					'post_snapshot_time' => current_time( 'mysql', true ),
				)
			);
		}

		$updated_run = $this->run_log->get_run( $run_id );

		return array(
			'success'  => true,
			'offset'   => $offset + count( $post_ids ),
			'done'     => $done,
			'progress' => array(
				'posts_scanned'   => (int) $updated_run->posts_scanned,
				'images_replaced' => (int) $updated_run->images_replaced,
				'images_skipped'  => (int) $updated_run->images_skipped,
			),
			'log_lines' => $log_lines,
		);
	}

	/**
	 * Process an apply batch (writes changes to post_content).
	 *
	 * @param int $run_id Run ID.
	 * @param int $offset Current item offset.
	 * @return array
	 */
	public function process_apply_batch( $run_id, $offset ) {
		$run = $this->run_log->get_run( $run_id );
		if ( ! $run || ! in_array( $run->status, array( 'pending_review', 'applying' ), true ) ) {
			return array(
				'success' => false,
				'message' => __( 'Run is not in a valid state for applying.', 'media-cleanup-kit' ),
			);
		}

		if ( 'pending_review' === $run->status ) {
			$this->run_log->update_run(
				$run_id,
				array(
					'status' => 'applying',
					'mode'   => 'apply',
				)
			);
		}

		$result = $this->run_log->get_actionable_items_paginated( $run_id, $offset, self::BATCH_SIZE );
		$batch  = $result['items'];
		$total  = $result['total'];

		$actionable_batch = array();
		foreach ( $batch as $item ) {
			$replacements = json_decode( $item->replacements, true );
			if ( ! is_array( $replacements ) ) {
				continue;
			}

			$has_actionable = false;
			foreach ( $replacements as $rep ) {
				if ( ! $rep['skipped'] && empty( $rep['excluded'] ) ) {
					$has_actionable = true;
					break;
				}
			}

			if ( $has_actionable ) {
				$actionable_batch[] = $item;
			}
		}

		$done = ( $offset + count( $batch ) ) >= $total;

		wp_defer_term_counting( true );
		wp_suspend_cache_invalidation( true );

		$batch_updated = 0;
		$batch_errors  = 0;
		$log_lines     = array();
		$is_first_item = true;

		foreach ( $actionable_batch as $item ) {
			if ( $is_first_item ) {
				$is_first_item = false;
			} else {
				usleep( 50000 );
			}

			$post = get_post( $item->post_id );
			if ( ! $post ) {
				$batch_errors++;
				continue;
			}

			if ( $run->post_snapshot_time ) {
				if ( $post->post_modified_gmt > $run->post_snapshot_time ) {
					$log_lines[] = array(
						'post_id' => $item->post_id,
						'title'   => $post->post_title,
						'status'  => 'skipped_content_changed',
					);
					continue;
				}
			}

			$replacements = json_decode( $item->replacements, true );
			$changed      = false;

			foreach ( $replacements as $rep ) {
				if ( $rep['skipped'] || ! empty( $rep['excluded'] ) ) {
					continue;
				}

				if ( ! empty( $rep['from_url'] ) && ! empty( $rep['to_url'] ) ) {
					$selections = array();
					foreach ( $replacements as $sel_rep ) {
						if ( ! empty( $sel_rep['candidates'] ) && ! empty( $sel_rep['attachment_id'] ) && ! empty( $sel_rep['from_url'] ) ) {
							$selections[ $sel_rep['from_url'] ] = (int) $sel_rep['attachment_id'];
						}
					}

					$apply_result = $this->scanner->scan_post( $item->post_id, false, $selections );

					if ( ! empty( $apply_result['error_message'] ) ) {
						$batch_errors++;
						$log_lines[] = array(
							'post_id' => $item->post_id,
							'title'   => $post->post_title,
							'status'  => 'error',
							'message' => $apply_result['error_message'],
						);
					} else {
						$batch_updated++;
						$log_lines[] = array(
							'post_id'  => $item->post_id,
							'title'    => $post->post_title,
							'status'   => 'applied',
							'replaced' => $apply_result['images_replaced'],
						);
					}

					$changed = true;
					break;
				}
			}

			if ( ! $changed ) {
				$log_lines[] = array(
					'post_id' => $item->post_id,
					'title'   => $post->post_title,
					'status'  => 'no_changes',
				);
			}
		}

		wp_suspend_cache_invalidation( false );
		wp_defer_term_counting( false );

		if ( $done ) {
			$this->run_log->update_run(
				$run_id,
				array(
					'status'        => ( $batch_errors > 0 && 0 === $batch_updated ) ? 'failed' : 'completed',
					'completed_at'  => current_time( 'mysql', true ),
				)
			);
		}

		return array(
			'success'        => true,
			'offset'         => $offset + count( $batch ),
			'done'           => $done,
			'total_to_apply' => $total,
			'log_lines'      => $log_lines,
		);
	}

	/**
	 * Process an audit scan batch.
	 *
	 * @param int   $run_id      Run ID.
	 * @param int   $offset      Current offset.
	 * @param array $post_types  Post types to audit.
	 * @param int   $total_posts Total posts.
	 * @return array
	 */
	public function process_audit_batch( $run_id, $offset, $post_types, $total_posts = 0 ) {
		$run = $this->run_log->get_run( $run_id );
		if ( ! $run || 'running' !== $run->status ) {
			return array(
				'success' => false,
				'message' => __( 'Run is not in a valid state for auditing.', 'media-cleanup-kit' ),
			);
		}

		$post_ids = $this->get_post_ids( $post_types, $offset, self::BATCH_SIZE );
		$total    = $total_posts > 0 ? $total_posts : $this->get_total_posts( $post_types );
		$done     = ( $offset + count( $post_ids ) ) >= $total;

		$batch_replaced = 0;
		$batch_skipped  = 0;
		$batch_updated  = 0;
		$batch_errors   = 0;
		$log_lines      = array();
		$is_first_post  = true;

		foreach ( $post_ids as $post_id ) {
			if ( $is_first_post ) {
				$is_first_post = false;
			} else {
				usleep( 50000 );
			}

			$post = get_post( $post_id );

			try {
				$audit_result = $this->scanner->audit_post( $post_id, true );
			} catch ( \Exception $e ) {
				$audit_result = array(
					'post_id'         => $post_id,
					'images_replaced' => 0,
					'images_skipped'  => 0,
					'replacements'    => array(),
					'error_message'   => $e->getMessage(),
				);
				$batch_errors++;
			}

			$has_activity = $audit_result['images_replaced'] > 0
				|| $audit_result['images_skipped'] > 0
				|| ! empty( $audit_result['error_message'] );

			if ( $has_activity ) {
				$this->run_log->insert_item(
					array(
						'run_id'          => $run_id,
						'post_id'         => $post_id,
						'post_title'      => $post ? $post->post_title : '',
						'images_replaced' => $audit_result['images_replaced'],
						'images_skipped'  => $audit_result['images_skipped'],
						'replacements'    => $audit_result['replacements'],
						'error_message'   => $audit_result['error_message'],
					)
				);
			}

			$batch_replaced += $audit_result['images_replaced'];
			$batch_skipped  += $audit_result['images_skipped'];

			if ( $audit_result['images_replaced'] > 0 ) {
				$batch_updated++;
			}

			$log_lines[] = array(
				'post_id'  => $post_id,
				'title'    => $post ? $post->post_title : sprintf( __( 'Post #%d', 'media-cleanup-kit' ), $post_id ),
				'replaced' => $audit_result['images_replaced'],
				'skipped'  => $audit_result['images_skipped'],
			);
		}

		$this->run_log->update_run(
			$run_id,
			array(
				'posts_scanned'   => $run->posts_scanned + count( $post_ids ),
				'images_replaced' => $run->images_replaced + $batch_replaced,
				'images_skipped'  => $run->images_skipped + $batch_skipped,
			)
		);

		if ( $done ) {
			$this->run_log->update_run(
				$run_id,
				array(
					'status'             => 'pending_review',
					'post_snapshot_time' => current_time( 'mysql', true ),
				)
			);
		}

		$updated_run = $this->run_log->get_run( $run_id );

		return array(
			'success'  => true,
			'offset'   => $offset + count( $post_ids ),
			'done'     => $done,
			'progress' => array(
				'posts_scanned'   => (int) $updated_run->posts_scanned,
				'images_replaced' => (int) $updated_run->images_replaced,
				'images_skipped'  => (int) $updated_run->images_skipped,
			),
			'log_lines' => $log_lines,
		);
	}

	/**
	 * Process an audit apply batch.
	 *
	 * @param int $run_id Run ID.
	 * @param int $offset Current item offset.
	 * @return array
	 */
	public function process_audit_apply_batch( $run_id, $offset ) {
		$run = $this->run_log->get_run( $run_id );
		if ( ! $run || ! in_array( $run->status, array( 'pending_review', 'applying' ), true ) ) {
			return array(
				'success' => false,
				'message' => __( 'Run is not in a valid state for applying.', 'media-cleanup-kit' ),
			);
		}

		if ( 'pending_review' === $run->status ) {
			$this->run_log->update_run(
				$run_id,
				array(
					'status' => 'applying',
					'mode'   => 'audit_apply',
				)
			);
		}

		$result = $this->run_log->get_actionable_items_paginated( $run_id, $offset, self::BATCH_SIZE );
		$batch  = $result['items'];
		$total  = $result['total'];
		$done   = ( $offset + count( $batch ) ) >= $total;

		wp_defer_term_counting( true );
		wp_suspend_cache_invalidation( true );

		$batch_updated = 0;
		$batch_errors  = 0;
		$log_lines     = array();
		$is_first_item = true;

		foreach ( $batch as $item ) {
			if ( $is_first_item ) {
				$is_first_item = false;
			} else {
				usleep( 50000 );
			}

			$post = get_post( $item->post_id );
			if ( ! $post ) {
				$batch_errors++;
				continue;
			}

			if ( $run->post_snapshot_time ) {
				if ( $post->post_modified_gmt > $run->post_snapshot_time ) {
					$log_lines[] = array(
						'post_id' => $item->post_id,
						'title'   => $post->post_title,
						'status'  => 'skipped_content_changed',
					);
					continue;
				}
			}

			$replacements = json_decode( $item->replacements, true );
			if ( ! is_array( $replacements ) ) {
				continue;
			}

			$has_actionable = false;
			foreach ( $replacements as $rep ) {
				if ( ! $rep['skipped'] && empty( $rep['excluded'] ) ) {
					$has_actionable = true;
					break;
				}
			}

			if ( ! $has_actionable ) {
				$log_lines[] = array(
					'post_id' => $item->post_id,
					'title'   => $post->post_title,
					'status'  => 'no_changes',
				);
				continue;
			}

			$apply_result = $this->scanner->audit_post( $item->post_id, false );

			if ( ! empty( $apply_result['error_message'] ) ) {
				$batch_errors++;
				$log_lines[] = array(
					'post_id' => $item->post_id,
					'title'   => $post->post_title,
					'status'  => 'error',
					'message' => $apply_result['error_message'],
				);
			} else {
				$batch_updated++;
				$log_lines[] = array(
					'post_id'  => $item->post_id,
					'title'    => $post->post_title,
					'status'   => 'applied',
					'replaced' => $apply_result['images_replaced'],
				);
			}
		}

		wp_suspend_cache_invalidation( false );
		wp_defer_term_counting( false );

		if ( $done ) {
			$this->run_log->update_run(
				$run_id,
				array(
					'status'        => ( $batch_errors > 0 && 0 === $batch_updated ) ? 'failed' : 'completed',
					'completed_at'  => current_time( 'mysql', true ),
				)
			);
		}

		return array(
			'success'        => true,
			'offset'         => $offset + count( $batch ),
			'done'           => $done,
			'total_to_apply' => $total,
			'log_lines'      => $log_lines,
		);
	}

	/**
	 * Get post IDs for the given post types, ordered by ID.
	 *
	 * @param array $post_types Post type slugs.
	 * @param int   $offset     Offset.
	 * @param int   $limit      Limit.
	 * @return int[]
	 */
	private function get_post_ids( $post_types, $offset, $limit ) {
		$query = new WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);

		return $query->posts;
	}
}
