<?php
/**
 * Image Kit — Markup Audit module.
 *
 * Scans Gutenberg image blocks for incomplete/malformed markup (missing id,
 * sizeSlug, wp-image-* class, etc.) and proposes corrected block JSON.
 * Shares the Image Upgrader scanner + batch runner + run-log infrastructure.
 */

defined( 'ABSPATH' ) || exit;

require_once IMAGE_KIT_PLUGIN_DIR . 'includes/modules/image-upgrader/class-scanner.php';
require_once IMAGE_KIT_PLUGIN_DIR . 'includes/modules/image-upgrader/class-batch-runner.php';

class Image_Kit_Module_Markup_Audit extends Image_Kit_Module {

	/** @var Image_Kit_Core_Run_Log */
	private $run_log;

	public function __construct() {
		$this->run_log = new Image_Kit_Core_Run_Log();
	}

	public function get_slug(): string {
		return 'markup-audit';
	}

	public function get_name(): string {
		return __( 'Repair Image Blocks', 'media-cleanup-kit' );
	}

	public function get_description(): string {
		return __( 'Audit Gutenberg image blocks for missing or malformed metadata (sizeSlug, attachment id, wp-image-* class) and propose corrected block JSON.', 'media-cleanup-kit' );
	}

	public function register_ajax_handlers(): void {
		add_action( 'wp_ajax_' . $this->ajax_action( 'start_run' ), array( $this, 'ajax_start_run' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'process_batch' ), array( $this, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'apply_run' ), array( $this, 'ajax_apply_run' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'cancel_run' ), array( $this, 'ajax_cancel_run' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'discard_run' ), array( $this, 'ajax_discard_run' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'get_run_items' ), array( $this, 'ajax_get_run_items' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'apply_single_post' ), array( $this, 'ajax_apply_single_post' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'resolve_missing' ), array( $this, 'ajax_resolve_missing' ) );
	}

	public function enqueue_assets(): void {
		// Needed by the Upload-replacement control on attachment_not_found rows.
		wp_enqueue_media();

		wp_enqueue_script(
			'image-kit-scan-ui',
			IMAGE_KIT_PLUGIN_URL . 'assets/js/scan-ui.js',
			array( 'image-kit-admin' ),
			IMAGE_KIT_VERSION,
			true
		);

		wp_enqueue_script(
			'image-kit-markup-audit',
			IMAGE_KIT_PLUGIN_URL . 'assets/js/markup-audit.js',
			array( 'image-kit-scan-ui' ),
			IMAGE_KIT_VERSION,
			true
		);

		$pending_review = $this->run_log->get_pending_review( array( 'audit', 'audit_apply' ) );

		wp_localize_script( 'image-kit-markup-audit', 'imageKitMarkupAudit', array(
			'startRunAction'     => $this->ajax_action( 'start_run' ),
			'processBatchAction' => $this->ajax_action( 'process_batch' ),
			'applyRunAction'     => $this->ajax_action( 'apply_run' ),
			'cancelRunAction'    => $this->ajax_action( 'cancel_run' ),
			'discardRunAction'   => $this->ajax_action( 'discard_run' ),
			'getRunItemsAction'  => $this->ajax_action( 'get_run_items' ),
			'applySingleAction'  => $this->ajax_action( 'apply_single_post' ),
			'resolveMissingAction' => $this->ajax_action( 'resolve_missing' ),
			'batchSize'          => Image_Kit_Image_Upgrader_Batch_Runner::BATCH_SIZE,
			'pendingReview'      => $pending_review ? array(
				'run_id' => (int) $pending_review->id,
				'status' => $pending_review->status,
				'mode'   => $pending_review->mode,
			) : null,
		) );
	}

	// ──────────────────────────────────────────────────────────────────
	// AJAX
	// ──────────────────────────────────────────────────────────────────

	private function verify_ajax(): void {
		check_ajax_referer( Image_Kit_Admin_Page::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'media-cleanup-kit' ), 403 );
		}
	}

	public function ajax_start_run(): void {
		$this->verify_ajax();

		// Self-heal: any *audit-mode* 'running' / 'applying' row older than 5
		// minutes is almost certainly abandoned (closed browser, JS error).
		// Scope to audit modes so Repair Image Blocks doesn't cancel a
		// Restore Full Size run (or vice versa) running in another tab.
		$this->run_log->clear_stale_active_runs( 5 * MINUTE_IN_SECONDS, array( 'audit', 'audit_apply' ) );

		$active = $this->run_log->get_active_run();
		if ( $active ) {
			wp_send_json_error( array( 'message' => __( 'A run is already in progress.', 'media-cleanup-kit' ) ) );
		}

		$post_types  = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['post_types'] ) : array( 'post', 'page' );
		$valid_types = array_filter( $post_types, 'post_type_exists' );
		if ( empty( $valid_types ) ) {
			wp_send_json_error( array( 'message' => __( 'No valid post types selected.', 'media-cleanup-kit' ) ) );
		}

		$batch_runner = new Image_Kit_Image_Upgrader_Batch_Runner( $this->run_log );
		$total_posts  = $batch_runner->get_total_posts( $valid_types );
		if ( 0 === $total_posts ) {
			wp_send_json_error( array( 'message' => __( 'No published posts found for the selected post types.', 'media-cleanup-kit' ) ) );
		}

		$run_id = $this->run_log->create_run( array(
			'post_types' => implode( ',', $valid_types ),
			'status'     => 'running',
			'mode'       => 'audit',
		) );

		if ( ! $run_id ) {
			wp_send_json_error( array( 'message' => __( 'Failed to create run record.', 'media-cleanup-kit' ) ) );
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
			wp_send_json_error( array( 'message' => __( 'Invalid request parameters.', 'media-cleanup-kit' ) ) );
		}

		$batch_runner = new Image_Kit_Image_Upgrader_Batch_Runner( $this->run_log );
		$result       = $batch_runner->process_audit_batch( $run_id, $offset, $post_types, $total_posts );

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
			wp_send_json_error( array( 'message' => __( 'Invalid run ID.', 'media-cleanup-kit' ) ) );
		}

		if ( 0 === $offset && isset( $_POST['exclusions'] ) ) {
			$exclusions = json_decode( wp_unslash( $_POST['exclusions'] ), true );
			if ( is_array( $exclusions ) ) {
				foreach ( $exclusions as $item_id => $excluded_indices ) {
					$this->run_log->update_item_exclusions( absint( $item_id ), array_map( 'absint', $excluded_indices ) );
				}
			}
		}

		$batch_runner = new Image_Kit_Image_Upgrader_Batch_Runner( $this->run_log );
		$result       = $batch_runner->process_audit_apply_batch( $run_id, $offset );

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
			wp_send_json_error( array( 'message' => __( 'Invalid run ID.', 'media-cleanup-kit' ) ) );
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
			wp_send_json_error( array( 'message' => __( 'Invalid run ID.', 'media-cleanup-kit' ) ) );
		}
		$this->run_log->update_run( $run_id, array(
			'status'       => 'discarded',
			'completed_at' => current_time( 'mysql', true ),
		) );
		wp_send_json_success();
	}

	public function ajax_get_run_items(): void {
		$this->verify_ajax();
		$run_id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;
		if ( ! $run_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid run ID.', 'media-cleanup-kit' ) ) );
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
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'media-cleanup-kit' ) ) );
		}

		$run = $this->run_log->get_run( $run_id );
		if ( ! $run || ! in_array( $run->status, array( 'pending_review', 'applying' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Run is not in a valid state for applying.', 'media-cleanup-kit' ) ) );
		}

		$item = $this->run_log->get_item( $item_id );
		if ( ! $item || (int) $item->run_id !== $run_id ) {
			wp_send_json_error( array( 'message' => __( 'Item not found.', 'media-cleanup-kit' ) ) );
		}

		$post = get_post( $item->post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post not found.', 'media-cleanup-kit' ) ) );
		}

		if ( $run->post_snapshot_time && $post->post_modified_gmt > $run->post_snapshot_time ) {
			wp_send_json_error( array(
				'message' => __( 'Post has been modified since the scan. Skipped to avoid overwriting changes.', 'media-cleanup-kit' ),
			) );
		}

		$scanner      = new Image_Kit_Image_Upgrader_Scanner();
		$apply_result = $scanner->audit_post( $item->post_id, false );

		if ( ! empty( $apply_result['error_message'] ) ) {
			wp_send_json_error( array( 'message' => $apply_result['error_message'] ) );
		}

		$this->run_log->update_item_applied( $item_id );

		wp_send_json_success( array(
			'post_id'  => $item->post_id,
			'title'    => $post->post_title,
			'replaced' => $apply_result['images_replaced'],
		) );
	}

	/**
	 * Upload-replacement handler: the user supplied an attachment for a URL
	 * the automatic lookup couldn't resolve. Persist the mapping, re-audit
	 * the item, and return the refreshed payload so the JS can swap the row
	 * in place without a full rescan.
	 */
	public function ajax_resolve_missing(): void {
		$this->verify_ajax();

		$run_id        = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;
		$item_id       = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		$url           = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		if ( ! $run_id || ! $item_id || ! $attachment_id || ! $url ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'media-cleanup-kit' ) ) );
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Selected file is not a valid attachment.', 'media-cleanup-kit' ) ) );
		}
		if ( 0 !== strpos( (string) get_post_mime_type( $attachment_id ), 'image/' ) ) {
			wp_send_json_error( array( 'message' => __( 'Selected file is not an image.', 'media-cleanup-kit' ) ) );
		}

		$item = $this->run_log->get_item( $item_id );
		if ( ! $item || (int) $item->run_id !== $run_id ) {
			wp_send_json_error( array( 'message' => __( 'Item not found.', 'media-cleanup-kit' ) ) );
		}

		$scanner = new Image_Kit_Image_Upgrader_Scanner();
		$scanner->record_url_alias( $url, $attachment_id );

		// Re-audit (dry-run) so the row reflects the new resolution.
		$result = $scanner->audit_post( $item->post_id, true );

		$this->run_log->update_item_replacements(
			$item_id,
			$result['replacements'],
			array(
				'images_replaced' => $result['images_replaced'],
				'images_skipped'  => $result['images_skipped'],
			)
		);

		$post = get_post( $item->post_id );

		wp_send_json_success( array(
			'item' => array(
				'id'              => (int) $item_id,
				'post_id'         => (int) $item->post_id,
				'post_title'      => $post ? $post->post_title : $item->post_title,
				'images_replaced' => (int) $result['images_replaced'],
				'images_skipped'  => (int) $result['images_skipped'],
				'replacements'    => $result['replacements'],
				'applied_at'      => $item->applied_at,
				'error_message'   => $item->error_message,
				'edit_url'        => get_edit_post_link( $item->post_id, 'raw' ),
				'view_url'        => get_permalink( $item->post_id ),
				'post_date'       => get_post_datetime( $item->post_id ) ? get_post_datetime( $item->post_id )->format( 'Y-m-d' ) : '',
			),
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
		<div class="ik-panel ik-scan-config" id="ik-ma-config">
			<h3><?php esc_html_e( 'Audit Configuration', 'media-cleanup-kit' ); ?></h3>
			<fieldset>
				<legend><?php esc_html_e( 'Post Types', 'media-cleanup-kit' ); ?></legend>
				<?php foreach ( $post_types as $pt ) : ?>
					<label>
						<input type="checkbox"
							name="ik_ma_post_types[]"
							value="<?php echo esc_attr( $pt->name ); ?>"
							<?php checked( in_array( $pt->name, $default_checked, true ) ); ?>>
						<?php echo esc_html( $pt->labels->name ); ?>
					</label><br>
				<?php endforeach; ?>
			</fieldset>
			<p>
				<button type="button" id="ik-ma-start" class="button button-primary"><?php esc_html_e( 'Run Audit', 'media-cleanup-kit' ); ?></button>
			</p>
		</div>

		<div class="ik-panel ik-scan-progress" id="ik-ma-progress"></div>
		<div class="ik-panel ik-scan-results" id="ik-ma-results"></div>
		<?php
	}
}
