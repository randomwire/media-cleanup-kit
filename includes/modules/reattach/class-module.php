<?php
/**
 * Image Kit — Attach Unparented Media module.
 *
 * Finds attachments with post_parent = 0 and proposes the first post that
 * references them. Supersedes the standalone Post Attach plugin.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-scanner.php';

class Image_Kit_Module_Reattach extends Image_Kit_Module {

	public function get_slug(): string {
		return 'reattach';
	}

	public function get_name(): string {
		return __( 'Attach Unparented Media', 'media-cleanup-kit' );
	}

	public function get_description(): string {
		return __( 'Find media library items with no parent post and attach them to the first post/page that references them.', 'media-cleanup-kit' );
	}

	public function register_ajax_handlers(): void {
		add_action( 'wp_ajax_' . $this->ajax_action( 'scan' ),  array( $this, 'ajax_scan_batch' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'apply' ), array( $this, 'ajax_apply' ) );
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
			'image-kit-reattach',
			IMAGE_KIT_PLUGIN_URL . 'assets/js/reattach.js',
			array( 'image-kit-scan-ui' ),
			IMAGE_KIT_VERSION,
			true
		);

		$scanner = new Image_Kit_Reattach_Scanner();

		wp_localize_script( 'image-kit-reattach', 'imageKitReattach', array(
			'scanAction'      => $this->ajax_action( 'scan' ),
			'applyAction'     => $this->ajax_action( 'apply' ),
			'scanBatchSize'   => 50,
			'unattachedCount' => $scanner->count_unattached(),
		) );
	}

	private function verify_ajax(): void {
		check_ajax_referer( Image_Kit_Admin_Page::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'media-cleanup-kit' ) ) );
		}
	}

	public function ajax_scan_batch(): void {
		$this->verify_ajax();
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}

		$offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 50;
		if ( $batch_size < 1 || $batch_size > 200 ) {
			$batch_size = 50;
		}

		$scanner = new Image_Kit_Reattach_Scanner();
		$result  = $scanner->scan_batch( $offset, $batch_size );

		$matches_found = 0;
		foreach ( $result['attachments'] as $att ) {
			if ( ! empty( $att['parent_id'] ) ) {
				$matches_found++;
			}
		}

		wp_send_json_success( array(
			'items'    => $result['attachments'],
			'offset'   => $result['offset'],
			'total'    => $result['total'],
			'done'     => $result['done'],
			'progress' => array(
				'attachments_scanned' => $result['offset'],
				'matches_found'       => $matches_found,
			),
		) );
	}

	public function ajax_apply(): void {
		$this->verify_ajax();

		$raw_items = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array();
		if ( is_string( $raw_items ) ) {
			$raw_items = json_decode( $raw_items, true );
		}
		if ( ! is_array( $raw_items ) ) {
			$raw_items = array();
		}

		$scanner = new Image_Kit_Reattach_Scanner();
		$results = array();

		foreach ( $raw_items as $item ) {
			$attachment_id = isset( $item['attachment_id'] ) ? absint( $item['attachment_id'] ) : 0;
			$parent_id     = isset( $item['parent_id'] ) ? absint( $item['parent_id'] ) : 0;
			$outcome       = $scanner->attach( $attachment_id, $parent_id );
			$results[]     = array_merge(
				array( 'attachment_id' => $attachment_id, 'parent_id' => $parent_id ),
				$outcome
			);
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	public function render_tab_content(): void {
		$count = ( new Image_Kit_Reattach_Scanner() )->count_unattached();
		?>
		<div class="ik-panel ik-scan-config" id="ik-ra-config">
			<p class="description">
				<?php
				printf(
					/* translators: %s: HTML <strong> count of unattached attachments. */
					esc_html__( 'Find media library items with no parent post (currently %s) and attach them to the first post that references them.', 'media-cleanup-kit' ),
					'<strong>' . esc_html( number_format_i18n( $count ) ) . '</strong>'
				);
				?>
			</p>
			<?php if ( $count > 0 ) : ?>
				<p>
					<button id="ik-ra-scan" class="button button-primary"><?php esc_html_e( 'Scan Unattached Media', 'media-cleanup-kit' ); ?></button>
				</p>
			<?php else : ?>
				<p><em><?php esc_html_e( 'All media is attached. Nothing to do here.', 'media-cleanup-kit' ); ?></em></p>
			<?php endif; ?>
		</div>

		<div class="ik-panel ik-scan-progress" id="ik-ra-progress"></div>
		<div class="ik-panel ik-scan-results"  id="ik-ra-results"></div>
		<?php
	}
}
