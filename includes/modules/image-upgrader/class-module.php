<?php
/**
 * Image Kit — Image Upgrader module.
 *
 * Scans post content for resized image variants and replaces them with
 * full-size originals from the media library.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-scanner.php';
require_once __DIR__ . '/class-batch-runner.php';

class Image_Kit_Module_Image_Upgrader extends Image_Kit_Module {

	/** @var Image_Kit_Core_Run_Log */
	private $run_log;

	public function __construct() {
		$this->run_log = new Image_Kit_Core_Run_Log();
	}

	public function get_slug(): string {
		return 'image-upgrader';
	}

	public function get_name(): string {
		return __( 'Image Upgrader', 'image-kit' );
	}

	public function get_description(): string {
		return __( 'Replace downsized image variants with full-size originals from the media library.', 'image-kit' );
	}

	public function activate(): void {
		$this->run_log->create_tables();

		if ( ! wp_next_scheduled( 'image_kit_upgrader_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'image_kit_upgrader_cleanup' );
		}
	}

	public function deactivate(): void {
		wp_clear_scheduled_hook( 'image_kit_upgrader_cleanup' );
	}

	public function register_ajax_handlers(): void {
		add_action( 'wp_ajax_' . $this->ajax_action( 'start_run' ), array( $this, 'ajax_start_run' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'process_batch' ), array( $this, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'apply_run' ), array( $this, 'ajax_apply_run' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'cancel_run' ), array( $this, 'ajax_cancel_run' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'discard_run' ), array( $this, 'ajax_discard_run' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'delete_run' ), array( $this, 'ajax_delete_run' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'get_run_items' ), array( $this, 'ajax_get_run_items' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'apply_single_post' ), array( $this, 'ajax_apply_single_post' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'get_history' ), array( $this, 'ajax_get_history' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'run_diagnostics' ), array( $this, 'ajax_run_diagnostics' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'select_candidate' ), array( $this, 'ajax_select_candidate' ) );

		add_action( 'image_kit_upgrader_cleanup', array( $this, 'cron_discard_stale' ) );
	}

	public function enqueue_assets(): void {
		wp_enqueue_media();

		wp_enqueue_script(
			'image-kit-image-upgrader',
			IMAGE_KIT_PLUGIN_URL . 'assets/js/image-upgrader.js',
			array( 'image-kit-admin' ),
			IMAGE_KIT_VERSION,
			true
		);

		$pending_review = $this->run_log->get_pending_review();

		wp_localize_script( 'image-kit-image-upgrader', 'imageKitUpgrader', array(
			'startRunAction'      => $this->ajax_action( 'start_run' ),
			'processBatchAction'  => $this->ajax_action( 'process_batch' ),
			'applyRunAction'      => $this->ajax_action( 'apply_run' ),
			'cancelRunAction'     => $this->ajax_action( 'cancel_run' ),
			'discardRunAction'    => $this->ajax_action( 'discard_run' ),
			'deleteRunAction'     => $this->ajax_action( 'delete_run' ),
			'getRunItemsAction'   => $this->ajax_action( 'get_run_items' ),
			'applySingleAction'   => $this->ajax_action( 'apply_single_post' ),
			'getHistoryAction'    => $this->ajax_action( 'get_history' ),
			'diagnosticsAction'   => $this->ajax_action( 'run_diagnostics' ),
			'selectCandidateAction' => $this->ajax_action( 'select_candidate' ),
			'batchSize'           => Image_Kit_Image_Upgrader_Batch_Runner::BATCH_SIZE,
			'pendingReview'       => $pending_review ? array(
				'run_id' => (int) $pending_review->id,
				'status' => $pending_review->status,
				'mode'   => $pending_review->mode,
			) : null,
		) );
	}

	public function cron_discard_stale(): void {
		$this->run_log->discard_stale_reviews();
	}

	// ──────────────────────────────────────────────────────────────────
	// AJAX Handlers
	// ──────────────────────────────────────────────────────────────────

	private function verify_ajax(): void {
		check_ajax_referer( Image_Kit_Admin_Page::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'image-kit' ), 403 );
		}
	}

	public function ajax_start_run(): void {
		$this->verify_ajax();

		$active = $this->run_log->get_active_run();
		if ( $active ) {
			wp_send_json_error( array(
				'message' => __( 'A run is already in progress.', 'image-kit' ),
			) );
		}

		$post_types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['post_types'] ) : array( 'post', 'page' );

		$valid_types = array_filter( $post_types, 'post_type_exists' );
		if ( empty( $valid_types ) ) {
			wp_send_json_error( array(
				'message' => __( 'No valid post types selected.', 'image-kit' ),
			) );
		}

		$batch_runner = new Image_Kit_Image_Upgrader_Batch_Runner( $this->run_log );
		$total_posts  = $batch_runner->get_total_posts( $valid_types );

		if ( 0 === $total_posts ) {
			wp_send_json_error( array(
				'message' => __( 'No published posts found for the selected post types.', 'image-kit' ),
			) );
		}

		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'scan';
		if ( ! in_array( $mode, array( 'scan', 'audit' ), true ) ) {
			$mode = 'scan';
		}

		$run_id = $this->run_log->create_run( array(
			'post_types' => implode( ',', $valid_types ),
			'status'     => 'running',
			'mode'       => $mode,
		) );

		if ( ! $run_id ) {
			wp_send_json_error( array(
				'message' => __( 'Failed to create run record.', 'image-kit' ),
			) );
		}

		wp_send_json_success( array(
			'run_id'      => $run_id,
			'total_posts' => $total_posts,
			'post_types'  => $valid_types,
		) );
	}

	public function ajax_process_batch(): void {
		$this->verify_ajax();

		$run_id      = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;
		$offset      = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$total_posts = isset( $_POST['total_posts'] ) ? absint( $_POST['total_posts'] ) : 0;
		$post_types  = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['post_types'] ) : array();

		if ( ! $run_id || empty( $post_types ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request parameters.', 'image-kit' ) ) );
		}

		$batch_runner = new Image_Kit_Image_Upgrader_Batch_Runner( $this->run_log );

		$run = $this->run_log->get_run( $run_id );
		if ( $run && 'audit' === $run->mode ) {
			$result = $batch_runner->process_audit_batch( $run_id, $offset, $post_types, $total_posts );
		} else {
			$result = $batch_runner->process_scan_batch( $run_id, $offset, $post_types, $total_posts );
		}

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	public function ajax_apply_run(): void {
		$this->verify_ajax();

		$run_id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		if ( ! $run_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid run ID.', 'image-kit' ) ) );
		}

		if ( 0 === $offset && isset( $_POST['exclusions'] ) ) {
			$exclusions = json_decode( stripslashes( $_POST['exclusions'] ), true );
			if ( is_array( $exclusions ) ) {
				foreach ( $exclusions as $item_id => $excluded_indices ) {
					$this->run_log->update_item_exclusions( absint( $item_id ), array_map( 'absint', $excluded_indices ) );
				}
			}
		}

		$batch_runner = new Image_Kit_Image_Upgrader_Batch_Runner( $this->run_log );

		$run = $this->run_log->get_run( $run_id );
		if ( $run && in_array( $run->mode, array( 'audit', 'audit_apply' ), true ) ) {
			$result = $batch_runner->process_audit_apply_batch( $run_id, $offset );
		} else {
			$result = $batch_runner->process_apply_batch( $run_id, $offset );
		}

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	public function ajax_cancel_run(): void {
		$this->verify_ajax();

		$run_id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;

		if ( ! $run_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid run ID.', 'image-kit' ) ) );
		}

		$items  = $this->run_log->get_items_for_run( $run_id );
		$status = ! empty( $items ) ? 'pending_review' : 'failed';

		$update_data = array(
			'status'       => $status,
			'completed_at' => current_time( 'mysql', true ),
		);
		if ( 'pending_review' === $status ) {
			$update_data['post_snapshot_time'] = current_time( 'mysql', true );
		}
		$this->run_log->update_run( $run_id, $update_data );

		wp_send_json_success();
	}

	public function ajax_discard_run(): void {
		$this->verify_ajax();

		$run_id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;

		if ( ! $run_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid run ID.', 'image-kit' ) ) );
		}

		$this->run_log->update_run( $run_id, array(
			'status'       => 'discarded',
			'completed_at' => current_time( 'mysql', true ),
		) );

		wp_send_json_success();
	}

	public function ajax_delete_run(): void {
		$this->verify_ajax();

		$run_id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;

		if ( ! $run_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid run ID.', 'image-kit' ) ) );
		}

		$this->run_log->delete_run( $run_id );
		wp_send_json_success();
	}

	public function ajax_get_run_items(): void {
		$this->verify_ajax();

		$run_id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;

		if ( ! $run_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid run ID.', 'image-kit' ) ) );
		}

		$items = $this->run_log->get_items_for_run( $run_id );

		$formatted = array();
		foreach ( $items as $item ) {
			$formatted[] = array(
				'id'              => (int) $item->id,
				'post_id'         => (int) $item->post_id,
				'post_title'      => $item->post_title,
				'images_replaced' => (int) $item->images_replaced,
				'images_skipped'  => (int) $item->images_skipped,
				'replacements'    => json_decode( $item->replacements, true ),
				'applied_at'      => $item->applied_at,
				'error_message'   => $item->error_message,
				'edit_url'        => get_edit_post_link( $item->post_id, 'raw' ),
				'view_url'        => get_permalink( $item->post_id ),
				'post_date'       => get_post_datetime( $item->post_id ) ? get_post_datetime( $item->post_id )->format( 'Y-m-d' ) : '',
			);
		}

		wp_send_json_success( array( 'items' => $formatted ) );
	}

	public function ajax_apply_single_post(): void {
		$this->verify_ajax();

		$run_id  = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;
		$item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;

		if ( ! $run_id || ! $item_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'image-kit' ) ) );
		}

		$run = $this->run_log->get_run( $run_id );
		if ( ! $run || ! in_array( $run->status, array( 'pending_review', 'applying' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Run is not in a valid state for applying.', 'image-kit' ) ) );
		}

		$item = $this->run_log->get_item( $item_id );
		if ( ! $item || (int) $item->run_id !== $run_id ) {
			wp_send_json_error( array( 'message' => __( 'Item not found.', 'image-kit' ) ) );
		}

		$post = get_post( $item->post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post not found.', 'image-kit' ) ) );
		}

		if ( $run->post_snapshot_time && $post->post_modified_gmt > $run->post_snapshot_time ) {
			wp_send_json_error( array(
				'message' => __( 'Post has been modified since the scan. Skipped to avoid overwriting changes.', 'image-kit' ),
			) );
		}

		$selections   = array();
		$replacements = json_decode( $item->replacements, true );
		if ( is_array( $replacements ) ) {
			foreach ( $replacements as $rep ) {
				if ( ! empty( $rep['candidates'] ) && ! empty( $rep['attachment_id'] ) && ! empty( $rep['from_url'] ) ) {
					$selections[ $rep['from_url'] ] = (int) $rep['attachment_id'];
				}
			}
		}

		$scanner = new Image_Kit_Image_Upgrader_Scanner();

		if ( in_array( $run->mode, array( 'audit', 'audit_apply' ), true ) ) {
			$apply_result = $scanner->audit_post( $item->post_id, false );
		} else {
			$apply_result = $scanner->scan_post( $item->post_id, false, $selections );
		}

		if ( ! empty( $apply_result['error_message'] ) ) {
			$this->run_log->update_run( $run_id, array(
				'error_count' => $run->error_count + 1,
			) );
			wp_send_json_error( array( 'message' => $apply_result['error_message'] ) );
		}

		$this->run_log->update_run( $run_id, array(
			'posts_updated' => $run->posts_updated + 1,
		) );
		$this->run_log->update_item_applied( $item_id );

		wp_send_json_success( array(
			'post_id'  => $item->post_id,
			'title'    => $post->post_title,
			'replaced' => $apply_result['images_replaced'],
		) );
	}

	public function ajax_get_history(): void {
		$this->verify_ajax();

		$page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$data = $this->run_log->get_runs( $page, 20 );

		$formatted_runs = array();
		foreach ( $data['runs'] as $run ) {
			$formatted_runs[] = array(
				'id'              => (int) $run->id,
				'started_at'      => $run->started_at,
				'completed_at'    => $run->completed_at,
				'mode'            => $run->mode,
				'status'          => $run->status,
				'post_types'      => $run->post_types,
				'posts_scanned'   => (int) $run->posts_scanned,
				'posts_updated'   => (int) $run->posts_updated,
				'images_replaced' => (int) $run->images_replaced,
				'images_skipped'  => (int) $run->images_skipped,
				'error_count'     => (int) $run->error_count,
			);
		}

		wp_send_json_success( array(
			'runs'        => $formatted_runs,
			'total'       => $data['total'],
			'total_pages' => ceil( $data['total'] / 20 ),
			'page'        => $page,
		) );
	}

	public function ajax_run_diagnostics(): void {
		$this->verify_ajax();

		$scanner = new Image_Kit_Image_Upgrader_Scanner();
		$data    = $scanner->run_diagnostics( 50 );

		wp_send_json_success( $data );
	}

	public function ajax_select_candidate(): void {
		$this->verify_ajax();

		$item_id       = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
		$rep_index     = isset( $_POST['rep_index'] ) ? absint( $_POST['rep_index'] ) : 0;
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! $item_id || ! $attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'image-kit' ) ) );
		}

		$item = $this->run_log->get_item( $item_id );
		if ( ! $item || empty( $item->replacements ) ) {
			wp_send_json_error( array( 'message' => __( 'Item not found.', 'image-kit' ) ) );
		}

		$replacements = json_decode( $item->replacements, true );
		if ( ! is_array( $replacements ) || ! isset( $replacements[ $rep_index ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Replacement not found.', 'image-kit' ) ) );
		}

		$rep = &$replacements[ $rep_index ];

		if ( empty( $rep['candidates'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No candidates for this replacement.', 'image-kit' ) ) );
		}

		$chosen = null;
		foreach ( $rep['candidates'] as $candidate ) {
			if ( (int) $candidate['attachment_id'] === $attachment_id ) {
				$chosen = $candidate;
				break;
			}
		}

		if ( ! $chosen ) {
			wp_send_json_error( array( 'message' => __( 'Candidate not found.', 'image-kit' ) ) );
		}

		$rep['attachment_id']     = $chosen['attachment_id'];
		$rep['to_url']            = $chosen['to_url'];
		$rep['to_dimensions']     = $chosen['to_dimensions'];
		$rep['original_filename'] = wp_basename( $chosen['to_url'] );

		$this->run_log->update_item_replacements( (int) $item->id, $replacements );

		wp_send_json_success( array(
			'attachment_id' => $chosen['attachment_id'],
			'to_url'        => $chosen['to_url'],
			'to_dimensions' => $chosen['to_dimensions'],
		) );
	}

	// ──────────────────────────────────────────────────────────────────
	// Tab Content
	// ──────────────────────────────────────────────────────────────────

	public function render_tab_content(): void {
		$post_types      = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );
		$default_checked = array( 'post', 'page' );
		?>

		<!-- Sub-tabs -->
		<div class="ik-sub-tabs">
			<button type="button" class="ik-sub-tab active" data-subtab="run"><?php esc_html_e( 'Run', 'image-kit' ); ?></button>
			<button type="button" class="ik-sub-tab" data-subtab="history"><?php esc_html_e( 'History', 'image-kit' ); ?></button>
		</div>

		<!-- ═══ Run sub-tab ═══ -->
		<div id="ik-iu-tab-run" class="ik-iu-subtab-content">

			<!-- Configuration -->
			<div class="ik-panel" id="ik-iu-config">
				<h3><?php esc_html_e( 'Scan Configuration', 'image-kit' ); ?></h3>
				<fieldset>
					<legend><?php esc_html_e( 'Post Types', 'image-kit' ); ?></legend>
					<?php foreach ( $post_types as $pt ) : ?>
						<label>
							<input type="checkbox"
								name="ik_iu_post_types[]"
								value="<?php echo esc_attr( $pt->name ); ?>"
								<?php checked( in_array( $pt->name, $default_checked, true ) ); ?>>
							<?php echo esc_html( $pt->labels->name ); ?>
						</label><br>
					<?php endforeach; ?>
				</fieldset>
				<p>
					<button type="button" id="ik-iu-start-scan" class="button button-primary"><?php esc_html_e( 'Scan', 'image-kit' ); ?></button>
					<button type="button" id="ik-iu-start-audit" class="button"><?php esc_html_e( 'Markup Audit', 'image-kit' ); ?></button>
					<button type="button" id="ik-iu-run-diagnostics" class="button"><?php esc_html_e( 'Run Diagnostics', 'image-kit' ); ?></button>
				</p>
			</div>

			<!-- Diagnostics -->
			<div id="ik-iu-diagnostics" class="ik-panel" style="display:none;">
				<h3><?php esc_html_e( 'Diagnostics', 'image-kit' ); ?></h3>
				<div id="ik-iu-diagnostics-output"></div>
			</div>

			<!-- Progress -->
			<div id="ik-iu-progress" class="ik-panel" style="display:none;">
				<h3 id="ik-iu-progress-heading"><?php esc_html_e( 'Scanning...', 'image-kit' ); ?></h3>
				<div id="ik-iu-scan-progress" class="ik-progress">
					<div class="ik-progress-bar"><div class="ik-progress-fill"></div></div>
					<span class="ik-progress-text">0%</span>
				</div>
				<div class="ik-iu-counters">
					<span><strong><?php esc_html_e( 'Posts scanned:', 'image-kit' ); ?></strong> <span id="ik-iu-counter-scanned">0</span></span>
					<span><strong><span id="ik-iu-counter-replaced-label"><?php esc_html_e( 'Images found:', 'image-kit' ); ?></span></strong> <span id="ik-iu-counter-replaced">0</span></span>
					<span><strong><?php esc_html_e( 'Images skipped:', 'image-kit' ); ?></strong> <span id="ik-iu-counter-skipped">0</span></span>
				</div>
				<div id="ik-iu-log-panel" class="ik-log"></div>
				<p>
					<button type="button" id="ik-iu-cancel-scan" class="button"><?php esc_html_e( 'Cancel', 'image-kit' ); ?></button>
				</p>
			</div>

			<!-- Preview -->
			<div id="ik-iu-preview" class="ik-panel" style="display:none;">
				<div id="ik-iu-preview-banner" class="ik-result ik-result-info">
					<p id="ik-iu-preview-summary"></p>
				</div>
				<div id="ik-iu-preview-table-wrap"></div>
				<div id="ik-iu-missing-panel" style="display:none;"></div>
				<div class="ik-iu-preview-actions" style="margin-top:16px;">
					<button type="button" id="ik-iu-apply-changes" class="button button-primary"><?php esc_html_e( 'Apply Changes', 'image-kit' ); ?></button>
					<button type="button" id="ik-iu-export-csv" class="button"><?php esc_html_e( 'Export as CSV', 'image-kit' ); ?></button>
					<button type="button" id="ik-iu-discard" class="button"><?php esc_html_e( 'Discard', 'image-kit' ); ?></button>
				</div>
			</div>

			<!-- Apply Progress -->
			<div id="ik-iu-apply-progress" class="ik-panel" style="display:none;">
				<h3><?php esc_html_e( 'Applying changes...', 'image-kit' ); ?></h3>
				<div id="ik-iu-apply-progress-bar" class="ik-progress">
					<div class="ik-progress-bar"><div class="ik-progress-fill"></div></div>
					<span class="ik-progress-text">0%</span>
				</div>
				<div id="ik-iu-apply-log-panel" class="ik-log"></div>
			</div>

			<!-- Success -->
			<div id="ik-iu-success" class="ik-panel" style="display:none;">
				<div class="ik-result ik-result-success">
					<p id="ik-iu-success-message"></p>
				</div>
				<p>
					<button type="button" id="ik-iu-goto-history" class="button"><?php esc_html_e( 'View History', 'image-kit' ); ?></button>
				</p>
			</div>

		</div>

		<!-- ═══ History sub-tab ═══ -->
		<div id="ik-iu-tab-history" class="ik-iu-subtab-content" style="display:none;">
			<div id="ik-iu-history-table-wrap">
				<p><?php esc_html_e( 'Loading...', 'image-kit' ); ?></p>
			</div>
			<div id="ik-iu-history-pagination"></div>
		</div>

		<!-- Detail Modal -->
		<div id="ik-iu-detail-modal" class="ik-modal" style="display:none;">
			<div class="ik-modal-overlay"></div>
			<div class="ik-modal-content">
				<div class="ik-modal-header">
					<h3 id="ik-iu-modal-title"></h3>
					<button type="button" class="ik-modal-close">&times;</button>
				</div>
				<div id="ik-iu-modal-body" class="ik-modal-body"></div>
			</div>
		</div>

		<?php
	}
}
