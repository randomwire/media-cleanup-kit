<?php
/**
 * Image Kit — Relocator module.
 *
 * Move images from upload subdirectories to the uploads root.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-scanner.php';

class Image_Kit_Module_Relocator extends Image_Kit_Module {

	const SCAN_BATCH_SIZE = 25;

	public function get_slug(): string {
		return 'relocator';
	}

	public function get_name(): string {
		return __( 'Flatten Uploads', 'media-cleanup-kit' );
	}

	public function get_description(): string {
		return __( 'Move images from upload subdirectories to the uploads root.', 'media-cleanup-kit' );
	}

	public function register_ajax_handlers(): void {
		add_action( 'wp_ajax_' . $this->ajax_action( 'scan' ), array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'apply_batch' ), array( $this, 'ajax_apply_batch' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'reset' ), array( $this, 'ajax_reset' ) );
	}

	public function enqueue_assets(): void {
		wp_enqueue_script(
			'image-kit-scan-ui',
			IMAGE_KIT_PLUGIN_URL . 'assets/js/scan-ui.js',
			array( 'image-kit-admin' ),
			IMAGE_KIT_VERSION,
			true
		);

		wp_enqueue_script(
			'image-kit-relocator',
			IMAGE_KIT_PLUGIN_URL . 'assets/js/relocator.js',
			array( 'image-kit-scan-ui' ),
			IMAGE_KIT_VERSION,
			true
		);

		wp_localize_script( 'image-kit-relocator', 'imageKitRelocator', array(
			'scanAction'    => $this->ajax_action( 'scan' ),
			'applyAction'   => $this->ajax_action( 'apply_batch' ),
			'resetAction'   => $this->ajax_action( 'reset' ),
			'scanBatchSize' => self::SCAN_BATCH_SIZE,
		) );
	}

	private function verify_ajax(): void {
		check_ajax_referer( Image_Kit_Admin_Page::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'media-cleanup-kit' ), 403 );
		}
	}

	public function ajax_scan(): void {
		$this->verify_ajax();

		$offset      = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$known_total = isset( $_POST['total'] ) ? absint( $_POST['total'] ) : 0;

		$scanner = new Image_Kit_Relocator_Scanner();
		$total   = ( 0 === $known_total ) ? $scanner->count_relocatable_attachments() : $known_total;
		$batch   = $scanner->scan_attachments_batched( $offset, self::SCAN_BATCH_SIZE );

		wp_send_json_success( array(
			'items'    => $batch['items'],
			'offset'   => $batch['offset'],
			'total'    => $total,
			'done'     => $batch['done'],
			'progress' => array(
				'attachments_checked' => $batch['offset'],
				'to_relocate'         => count( $batch['items'] ),
			),
			'log_lines' => array(),
		) );
	}

	public function ajax_apply_batch(): void {
		$this->verify_ajax();

		$ids = isset( $_POST['attachment_ids'] )
			? array_map( 'absint', (array) $_POST['attachment_ids'] )
			: array();

		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'No attachments specified.', 'media-cleanup-kit' ) );
		}

		$scanner = new Image_Kit_Relocator_Scanner();
		$results = array();
		$count   = count( $ids );

		foreach ( $ids as $i => $id ) {
			$result                  = $scanner->relocate_attachment( $id );
			$result['attachment_id'] = $id;
			$results[]               = $result;
			if ( $i < $count - 1 ) {
				usleep( 50000 );
			}
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	public function ajax_reset(): void {
		$this->verify_ajax();
		delete_transient( 'image_kit_relocator_scan' );
		wp_send_json_success( array( 'message' => 'Reset complete.' ) );
	}

	public function render_tab_content(): void {
		?>
		<div class="ik-panel ik-scan-config" id="ik-rel-config">
			<?php $this->render_panel_header(); ?>
			<p>
				<button type="button" id="ik-rel-scan" class="button button-primary"><?php esc_html_e( 'Scan for Images', 'media-cleanup-kit' ); ?></button>
			</p>
		</div>

		<div class="ik-panel ik-scan-progress" id="ik-rel-progress"></div>
		<div class="ik-panel ik-scan-results" id="ik-rel-results"></div>
		<?php
	}
}
