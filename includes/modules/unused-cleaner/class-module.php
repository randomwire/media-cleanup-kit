<?php
/**
 * Image Kit — Unused Cleaner module.
 *
 * Scans the WordPress uploads directory for image files not referenced
 * anywhere in WordPress (post content, media library, featured images,
 * Gutenberg blocks, custom meta, widgets). Groups thumbnail variants and
 * allows safe deletion.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-scanner.php';

class Image_Kit_Module_Unused_Cleaner extends Image_Kit_Module {

	public function get_slug(): string {
		return 'unused-cleaner';
	}

	public function get_name(): string {
		return __( 'Delete Unused Files', 'media-cleanup-kit' );
	}

	public function get_description(): string {
		return __( 'Find and safely delete orphaned image files not referenced anywhere in WordPress.', 'media-cleanup-kit' );
	}

	public function register_ajax_handlers(): void {
		add_action( 'wp_ajax_' . $this->ajax_action( 'scan' ), array( $this, 'ajax_scan_batch' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'delete' ), array( $this, 'ajax_delete_images' ) );
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
			'image-kit-unused-cleaner',
			IMAGE_KIT_PLUGIN_URL . 'assets/js/unused-cleaner.js',
			array( 'image-kit-scan-ui' ),
			IMAGE_KIT_VERSION,
			true
		);

		wp_localize_script( 'image-kit-unused-cleaner', 'imageKitUnusedCleaner', array(
			'scanAction'   => $this->ajax_action( 'scan' ),
			'deleteAction' => $this->ajax_action( 'delete' ),
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

		$scanner = new Image_Kit_Unused_Cleaner_Scanner();
		$offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		$real_path = realpath( wp_upload_dir()['basedir'] );
		if ( false === $real_path || ! is_dir( $real_path ) ) {
			wp_send_json_error( array( 'message' => __( 'Uploads directory not found.', 'media-cleanup-kit' ) ) );
		}

		$listing = $scanner->list_images( $real_path, $offset );

		if ( empty( $listing['files'] ) ) {
			wp_send_json_success( array(
				'results' => array(),
				'offset'  => $offset,
				'total'   => $listing['total'],
				'done'    => true,
			) );
			return;
		}

		$all_files     = $scanner->get_all_files( $real_path );
		$all_files_map = array_flip( $all_files );
		$groups        = $scanner->group_thumbnails( $listing['files'], $all_files_map );
		$results       = $scanner->check_usage_batch( $groups, $real_path );

		$new_offset = $offset + count( $listing['files'] );

		wp_send_json_success( array(
			'items'    => $results,
			'offset'   => $new_offset,
			'total'    => $listing['total'],
			'done'     => $listing['done'],
			'progress' => array(
				'files_scanned' => $new_offset,
			),
			'log_lines' => array(),
		) );
	}

	public function ajax_delete_images(): void {
		$this->verify_ajax();

		$scanner        = new Image_Kit_Unused_Cleaner_Scanner();
		$files          = isset( $_POST['files'] ) ? array_map( 'sanitize_file_name', wp_unslash( $_POST['files'] ) ) : array();
		$attachment_ids = isset( $_POST['attachment_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['attachment_ids'] ) ) : array();

		if ( empty( $files ) ) {
			wp_send_json_error( array( 'message' => __( 'No files specified.', 'media-cleanup-kit' ) ) );
		}

		$real_path = realpath( wp_upload_dir()['basedir'] );
		if ( false === $real_path || ! is_dir( $real_path ) ) {
			wp_send_json_error( array( 'message' => __( 'Uploads directory not found.', 'media-cleanup-kit' ) ) );
		}

		$results = $scanner->delete_files( $files, $real_path );

		$deleted_attachments = array();
		foreach ( $attachment_ids as $att_id ) {
			if ( $att_id > 0 && get_post_type( $att_id ) === 'attachment' ) {
				wp_delete_attachment( $att_id, true );
				$deleted_attachments[] = $att_id;
			}
		}

		$scanner->clear_cache( $real_path );

		wp_send_json_success( array(
			'results'             => $results,
			'deleted_attachments' => $deleted_attachments,
		) );
	}

	public function render_tab_content(): void {
		?>
		<div class="ik-panel ik-scan-config" id="ik-uc-config">
			<p class="description">
				<?php
				printf(
					/* translators: %s: absolute path to the WordPress uploads directory. */
					esc_html__( 'Scans the WordPress uploads directory (%s) for image files not referenced anywhere on the site.', 'media-cleanup-kit' ),
					'<code>' . esc_html( wp_upload_dir()['basedir'] ) . '</code>'
				);
				?>
			</p>
			<p>
				<button id="ik-uc-scan-btn" class="button button-primary"><?php esc_html_e( 'Scan Uploads', 'media-cleanup-kit' ); ?></button>
			</p>
		</div>

		<div class="ik-panel ik-scan-progress" id="ik-uc-progress"></div>
		<div class="ik-panel ik-scan-results" id="ik-uc-results"></div>
		<?php
	}
}
