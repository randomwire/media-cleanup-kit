<?php
/**
 * Image Kit — Broken Images module.
 *
 * Scans posts for internal image references where the file is missing
 * from the filesystem. Supports Gutenberg blocks and raw <img> tags.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-scanner.php';

class Image_Kit_Module_Broken_Images extends Image_Kit_Module {

	const BATCH_SIZE = 50;
	const PAGE_SIZE  = 50;

	public function get_slug(): string {
		return 'broken-images';
	}

	public function get_name(): string {
		return __( 'Find Broken Images', 'media-cleanup-kit' );
	}

	public function get_description(): string {
		return __( 'Scan posts and pages for internal image embeds and featured images where the file is missing from the uploads directory.', 'media-cleanup-kit' );
	}

	public function register_ajax_handlers(): void {
		add_action( 'wp_ajax_' . $this->ajax_action( 'scan' ), array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'apply_remove' ), array( $this, 'ajax_apply_remove' ) );
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
			'image-kit-broken-images',
			IMAGE_KIT_PLUGIN_URL . 'assets/js/broken-images.js',
			array( 'image-kit-scan-ui' ),
			IMAGE_KIT_VERSION,
			true
		);

		wp_localize_script( 'image-kit-broken-images', 'imageKitBrokenImages', array(
			'action'            => $this->ajax_action( 'scan' ),
			'applyRemoveAction' => $this->ajax_action( 'apply_remove' ),
			'batchSize'         => self::BATCH_SIZE,
		) );
	}

	public function ajax_scan(): void {
		check_ajax_referer( Image_Kit_Admin_Page::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'media-cleanup-kit' ), 403 );
		}

		$scanner     = new Image_Kit_Broken_Images_Scanner();
		$offset      = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$known_total = isset( $_POST['total'] ) ? absint( $_POST['total'] ) : 0;
		$post_total  = ( 0 === $known_total ) ? $scanner->get_candidate_post_count() : $known_total;
		$posts       = $scanner->get_post_batch( $offset, self::BATCH_SIZE );
		$broken      = $scanner->check_posts( $posts );

		$batch_count = is_array( $posts ) ? count( $posts ) : 0;
		$processed   = $offset + $batch_count;
		$done        = $batch_count < self::BATCH_SIZE || $processed >= $post_total;

		// Stamp each broken item with a stable id (helper needs rowKey).
		$idx = $offset * 100;
		foreach ( $broken as &$b ) {
			$b['id'] = ++$idx;
		}
		unset( $b );

		$log_lines = array();
		if ( ! empty( $broken ) ) {
			$log_lines[] = array(
				'type'  => 'info',
				'title' => sprintf( '%d broken reference(s) in this batch', count( $broken ) ),
			);
		}

		wp_send_json_success( array(
			'items'     => $broken,
			'offset'    => $processed,
			'total'     => $post_total,
			'done'      => $done,
			'progress'  => array(
				'posts_scanned' => $processed,
				'broken_found'  => 0, // accumulated client-side from items.length
			),
			'log_lines' => $log_lines,
		) );
	}

	public function ajax_apply_remove(): void {
		check_ajax_referer( Image_Kit_Admin_Page::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'media-cleanup-kit' ) ), 403 );
		}

		$post_id    = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$image_url  = isset( $_POST['image_url'] ) ? esc_url_raw( wp_unslash( $_POST['image_url'] ) ) : '';
		$block_type = isset( $_POST['block_type'] ) ? sanitize_text_field( wp_unslash( $_POST['block_type'] ) ) : '';

		if ( ! $post_id || '' === $image_url || '' === $block_type ) {
			wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'media-cleanup-kit' ) ) );
		}

		$scanner = new Image_Kit_Broken_Images_Scanner();
		$result  = $scanner->remove_broken_from_post( $post_id, $image_url, $block_type );

		if ( ! $result['success'] ) {
			wp_send_json_error( $result );
		}

		wp_send_json_success( $result );
	}

	public function render_tab_content(): void {
		$scanner    = new Image_Kit_Broken_Images_Scanner();
		$post_count = $scanner->get_candidate_post_count();
		?>
		<div class="ik-panel ik-scan-config" id="ik-bi-config">
			<?php $this->render_panel_header(); ?>
			<p class="ik-panel-status">
				<?php
				printf(
					/* translators: %s: number of posts */
					esc_html__( 'Found %s post(s) containing image references.', 'media-cleanup-kit' ),
					'<strong>' . esc_html( number_format_i18n( $post_count ) ) . '</strong>'
				);
				?>
			</p>
			<?php if ( $post_count > 0 ) : ?>
				<p>
					<button type="button" id="ik-bi-scan" class="button button-primary" data-total="<?php echo (int) $post_count; ?>">
						<?php esc_html_e( 'Scan for Broken Images', 'media-cleanup-kit' ); ?>
					</button>
				</p>
			<?php else : ?>
				<p><em><?php esc_html_e( 'No posts with image references found.', 'media-cleanup-kit' ); ?></em></p>
			<?php endif; ?>
		</div>

		<div class="ik-panel ik-scan-progress" id="ik-bi-progress"></div>
		<div class="ik-panel ik-scan-results" id="ik-bi-results"></div>
		<?php
	}
}
