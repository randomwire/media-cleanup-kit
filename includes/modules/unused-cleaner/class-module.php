<?php
/**
 * Image Kit — Unused Cleaner module.
 *
 * Scans a directory for image files not referenced anywhere in WordPress
 * (post content, media library, featured images, Gutenberg blocks, custom
 * meta, widgets). Groups thumbnail variants and allows safe deletion.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-scanner.php';

class Image_Kit_Module_Unused_Cleaner extends Image_Kit_Module {

	public function get_slug(): string {
		return 'unused-cleaner';
	}

	public function get_name(): string {
		return __( 'Unused Cleaner', 'image-kit' );
	}

	public function get_description(): string {
		return __( 'Find and safely delete orphaned image files not referenced anywhere in WordPress.', 'image-kit' );
	}

	public function register_ajax_handlers(): void {
		add_action( 'wp_ajax_' . $this->ajax_action( 'validate' ), array( $this, 'ajax_validate_directory' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'scan' ), array( $this, 'ajax_scan_batch' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'delete' ), array( $this, 'ajax_delete_images' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'thumbnail' ), array( $this, 'ajax_serve_thumbnail' ) );
	}

	public function enqueue_assets(): void {
		wp_enqueue_script(
			'image-kit-unused-cleaner',
			IMAGE_KIT_PLUGIN_URL . 'assets/js/unused-cleaner.js',
			array( 'image-kit-admin' ),
			IMAGE_KIT_VERSION,
			true
		);

		wp_localize_script( 'image-kit-unused-cleaner', 'imageKitUnusedCleaner', array(
			'validateAction'  => $this->ajax_action( 'validate' ),
			'scanAction'      => $this->ajax_action( 'scan' ),
			'deleteAction'    => $this->ajax_action( 'delete' ),
			'thumbnailAction' => $this->ajax_action( 'thumbnail' ),
		) );
	}

	private function verify_ajax(): void {
		check_ajax_referer( Image_Kit_Admin_Page::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'image-kit' ) ) );
		}
	}

	public function ajax_validate_directory(): void {
		$this->verify_ajax();

		$scanner   = new Image_Kit_Unused_Cleaner_Scanner();
		$directory = isset( $_POST['directory'] ) ? sanitize_text_field( wp_unslash( $_POST['directory'] ) ) : '';
		$result    = $scanner->validate_directory( $directory );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	public function ajax_scan_batch(): void {
		$this->verify_ajax();
		set_time_limit( 120 );

		$scanner   = new Image_Kit_Unused_Cleaner_Scanner();
		$directory = isset( $_POST['directory'] ) ? sanitize_text_field( wp_unslash( $_POST['directory'] ) ) : '';
		$offset    = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		$real_path = realpath( $directory );
		if ( false === $real_path || ! is_dir( $real_path ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid directory.', 'image-kit' ) ) );
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

		wp_send_json_success( array(
			'results' => $results,
			'offset'  => $offset + count( $listing['files'] ),
			'total'   => $listing['total'],
			'done'    => $listing['done'],
		) );
	}

	public function ajax_delete_images(): void {
		$this->verify_ajax();

		$scanner        = new Image_Kit_Unused_Cleaner_Scanner();
		$directory      = isset( $_POST['directory'] ) ? sanitize_text_field( wp_unslash( $_POST['directory'] ) ) : '';
		$files          = isset( $_POST['files'] ) ? array_map( 'sanitize_file_name', wp_unslash( $_POST['files'] ) ) : array();
		$attachment_ids = isset( $_POST['attachment_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['attachment_ids'] ) ) : array();

		if ( empty( $files ) ) {
			wp_send_json_error( array( 'message' => __( 'No files specified.', 'image-kit' ) ) );
		}

		$real_path = realpath( $directory );
		if ( false === $real_path || ! is_dir( $real_path ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid directory.', 'image-kit' ) ) );
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

	public function ajax_serve_thumbnail(): void {
		check_ajax_referer( Image_Kit_Admin_Page::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied.', 403 );
		}

		$directory = isset( $_GET['directory'] ) ? sanitize_text_field( wp_unslash( $_GET['directory'] ) ) : '';
		$filename  = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';

		if ( empty( $directory ) || empty( $filename ) ) {
			wp_die( 'Invalid request.' );
		}

		$real_dir = realpath( $directory );
		if ( false === $real_dir ) {
			wp_die( 'Invalid directory.' );
		}

		$file_path = $real_dir . '/' . $filename;
		$real_file = realpath( $file_path );

		if ( false === $real_file || 0 !== strpos( $real_file, $real_dir . '/' ) || ! file_exists( $real_file ) ) {
			wp_die( 'File not found.' );
		}

		$mime = wp_check_filetype( $filename );
		if ( empty( $mime['type'] ) ) {
			wp_die( 'Invalid file type.' );
		}

		$editor = wp_get_image_editor( $real_file );
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( 80, 80, true );
			$temp = $editor->save( null, $mime['type'] );
			if ( ! is_wp_error( $temp ) && ! empty( $temp['path'] ) ) {
				header( 'Content-Type: ' . $mime['type'] );
				header( 'Cache-Control: public, max-age=3600' );
				readfile( $temp['path'] );
				wp_delete_file( $temp['path'] );
				exit;
			}
		}

		header( 'Content-Type: ' . $mime['type'] );
		header( 'Cache-Control: public, max-age=3600' );
		readfile( $real_file );
		exit;
	}

	public function render_tab_content(): void {
		?>
		<!-- Config Section -->
		<div id="ik-uc-config">
			<h3><?php esc_html_e( 'Directory to Scan', 'image-kit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Enter the full server path to the directory containing images you want to check.', 'image-kit' ); ?>
			</p>
			<div style="display:flex; gap:8px; align-items:center; margin:12px 0;">
				<input type="text" id="ik-uc-directory" class="regular-text"
					value="<?php echo esc_attr( wp_upload_dir()['basedir'] ); ?>"
					placeholder="<?php echo esc_attr( wp_upload_dir()['basedir'] ); ?>">
				<button id="ik-uc-scan-btn" class="button button-primary"><?php esc_html_e( 'Scan', 'image-kit' ); ?></button>
			</div>
			<div id="ik-uc-validation-error" class="notice notice-error inline" style="display:none;"><p></p></div>
		</div>

		<!-- Progress Section -->
		<div id="ik-uc-progress" style="display:none;">
			<h3 id="ik-uc-progress-title"><?php esc_html_e( 'Scanning...', 'image-kit' ); ?></h3>
			<div class="ik-progress">
				<div class="ik-progress-bar"><div class="ik-progress-fill"></div></div>
				<span class="ik-progress-text">0%</span>
			</div>
			<div style="display:flex; gap:16px; margin:8px 0;">
				<span><?php esc_html_e( 'Scanned:', 'image-kit' ); ?> <strong id="ik-uc-count-scanned">0</strong></span>
				<span><?php esc_html_e( 'Used:', 'image-kit' ); ?> <strong id="ik-uc-count-used">0</strong></span>
				<span><?php esc_html_e( 'Unused:', 'image-kit' ); ?> <strong id="ik-uc-count-unused">0</strong></span>
			</div>
			<button id="ik-uc-cancel-btn" class="button"><?php esc_html_e( 'Cancel', 'image-kit' ); ?></button>
		</div>

		<!-- Results Section -->
		<div id="ik-uc-results" style="display:none;">
			<div style="margin:12px 0;">
				<p>
					<?php esc_html_e( 'Total:', 'image-kit' ); ?> <strong id="ik-uc-summary-total">0</strong> |
					<?php esc_html_e( 'Used:', 'image-kit' ); ?> <strong id="ik-uc-summary-used">0</strong> |
					<?php esc_html_e( 'Unused:', 'image-kit' ); ?> <strong id="ik-uc-summary-unused">0</strong>
					(<span id="ik-uc-summary-size">0 MB</span>)
				</p>
			</div>

			<div style="display:flex; gap:4px; margin-bottom:12px;">
				<button class="button ik-uc-filter-btn active" data-filter="all"><?php esc_html_e( 'All', 'image-kit' ); ?></button>
				<button class="button ik-uc-filter-btn" data-filter="unused"><?php esc_html_e( 'Unused Only', 'image-kit' ); ?></button>
				<button class="button ik-uc-filter-btn" data-filter="used"><?php esc_html_e( 'Used Only', 'image-kit' ); ?></button>
			</div>

			<div style="display:flex; gap:8px; align-items:center; margin-bottom:12px;">
				<label><input type="checkbox" id="ik-uc-select-all"> <?php esc_html_e( 'Select all unused', 'image-kit' ); ?></label>
				<button id="ik-uc-delete-btn" class="button button-primary" disabled><?php esc_html_e( 'Delete Selected', 'image-kit' ); ?></button>
				<button id="ik-uc-export-btn" class="button"><?php esc_html_e( 'Export CSV', 'image-kit' ); ?></button>
				<span id="ik-uc-selected-count" class="ik-selected-count"></span>
			</div>

			<table class="widefat striped" id="ik-uc-table">
				<thead>
					<tr>
						<th class="check-column"><input type="checkbox" id="ik-uc-check-all"></th>
						<th class="ik-uc-sortable" data-sort="filename"><?php esc_html_e( 'Filename', 'image-kit' ); ?> <span class="ik-uc-sort-arrow"></span></th>
						<th class="ik-uc-sortable" data-sort="size"><?php esc_html_e( 'Size', 'image-kit' ); ?> <span class="ik-uc-sort-arrow"></span></th>
						<th><?php esc_html_e( 'Status', 'image-kit' ); ?></th>
						<th><?php esc_html_e( 'Used In', 'image-kit' ); ?></th>
						<th><?php esc_html_e( 'Thumbnails', 'image-kit' ); ?></th>
					</tr>
				</thead>
				<tbody id="ik-uc-tbody"></tbody>
			</table>

			<div style="display:flex; gap:8px; align-items:center; margin:12px 0;">
				<button class="button" id="ik-uc-page-prev">&laquo; <?php esc_html_e( 'Previous', 'image-kit' ); ?></button>
				<span id="ik-uc-page-info"></span>
				<button class="button" id="ik-uc-page-next"><?php esc_html_e( 'Next', 'image-kit' ); ?> &raquo;</button>
			</div>
		</div>

		<!-- Delete Progress -->
		<div id="ik-uc-delete-progress" style="display:none;">
			<h3><?php esc_html_e( 'Deleting...', 'image-kit' ); ?></h3>
			<div class="ik-progress">
				<div class="ik-progress-bar"><div class="ik-progress-fill"></div></div>
				<span class="ik-progress-text">0%</span>
			</div>
			<div style="display:flex; gap:16px; margin:8px 0;">
				<span><?php esc_html_e( 'Deleted:', 'image-kit' ); ?> <strong id="ik-uc-delete-count">0</strong> / <strong id="ik-uc-delete-total">0</strong></span>
				<span><?php esc_html_e( 'Errors:', 'image-kit' ); ?> <strong id="ik-uc-delete-errors">0</strong></span>
			</div>
			<button id="ik-uc-cancel-delete-btn" class="button"><?php esc_html_e( 'Cancel', 'image-kit' ); ?></button>
		</div>
		<?php
	}
}
