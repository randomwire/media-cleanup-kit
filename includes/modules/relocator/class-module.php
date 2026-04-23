<?php
/**
 * Image Kit — Relocator module.
 *
 * Two sub-features:
 * 1. Relocate: Move images from upload subdirectories to the uploads root.
 * 2. Import Orphans: Find and import orphan files into the media library.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-scanner.php';

class Image_Kit_Module_Relocator extends Image_Kit_Module {

	public function get_slug(): string {
		return 'relocator';
	}

	public function get_name(): string {
		return __( 'Relocator', 'image-kit' );
	}

	public function get_description(): string {
		return __( 'Move images from upload subdirectories to the uploads root, or import orphan files into the media library.', 'image-kit' );
	}

	public function register_ajax_handlers(): void {
		add_action( 'wp_ajax_' . $this->ajax_action( 'scan' ), array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'apply_batch' ), array( $this, 'ajax_apply_batch' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'reset' ), array( $this, 'ajax_reset' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'scan_orphans' ), array( $this, 'ajax_scan_orphans' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'import_batch' ), array( $this, 'ajax_import_batch' ) );
	}

	public function enqueue_assets(): void {
		wp_enqueue_script(
			'image-kit-relocator',
			IMAGE_KIT_PLUGIN_URL . 'assets/js/relocator.js',
			array( 'image-kit-admin' ),
			IMAGE_KIT_VERSION,
			true
		);

		wp_localize_script( 'image-kit-relocator', 'imageKitRelocator', array(
			'scanAction'        => $this->ajax_action( 'scan' ),
			'applyAction'       => $this->ajax_action( 'apply_batch' ),
			'resetAction'       => $this->ajax_action( 'reset' ),
			'scanOrphansAction' => $this->ajax_action( 'scan_orphans' ),
			'importAction'      => $this->ajax_action( 'import_batch' ),
		) );
	}

	private function verify_ajax(): void {
		check_ajax_referer( Image_Kit_Admin_Page::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'image-kit' ), 403 );
		}
	}

	public function ajax_scan(): void {
		$this->verify_ajax();
		$scanner = new Image_Kit_Relocator_Scanner();
		$items   = $scanner->scan_attachments();
		set_transient( 'image_kit_relocator_scan', $items, DAY_IN_SECONDS );
		wp_send_json_success( array( 'items' => $items, 'total' => count( $items ) ) );
	}

	public function ajax_apply_batch(): void {
		$this->verify_ajax();

		$ids = isset( $_POST['attachment_ids'] )
			? array_map( 'absint', (array) $_POST['attachment_ids'] )
			: array();

		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'No attachments specified.', 'image-kit' ) );
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

	public function ajax_scan_orphans(): void {
		$this->verify_ajax();
		$scanner = new Image_Kit_Relocator_Scanner();
		$items   = $scanner->scan_orphan_files();
		wp_send_json_success( array( 'items' => $items, 'total' => count( $items ) ) );
	}

	public function ajax_import_batch(): void {
		$this->verify_ajax();

		$paths = isset( $_POST['paths'] )
			? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['paths'] ) )
			: array();

		if ( empty( $paths ) ) {
			wp_send_json_error( __( 'No files specified.', 'image-kit' ) );
		}

		$scanner = new Image_Kit_Relocator_Scanner();
		$results = array();
		$count   = count( $paths );

		foreach ( $paths as $i => $rel_path ) {
			$result = $scanner->import_orphan( $rel_path );
			$result['relative_path'] = $rel_path;
			$results[] = $result;
			if ( $i < $count - 1 ) {
				usleep( 100000 );
			}
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	public function render_tab_content(): void {
		?>
		<!-- Sub-tabs -->
		<div class="ik-sub-tabs">
			<button type="button" class="ik-sub-tab active" data-subtab="relocate"><?php esc_html_e( 'Relocate', 'image-kit' ); ?></button>
			<button type="button" class="ik-sub-tab" data-subtab="orphans"><?php esc_html_e( 'Import Orphans', 'image-kit' ); ?></button>
		</div>

		<!-- ═══ Relocate sub-tab ═══ -->
		<div id="ik-rel-tab-relocate" class="ik-rel-subtab-content">
			<div class="ik-wizard-steps" id="ik-rel-steps">
				<span class="ik-step" data-step="1"><?php esc_html_e( '1. Scan', 'image-kit' ); ?></span>
				<span class="ik-step-arrow">&rarr;</span>
				<span class="ik-step" data-step="2"><?php esc_html_e( '2. Apply', 'image-kit' ); ?></span>
				<span class="ik-step-arrow">&rarr;</span>
				<span class="ik-step" data-step="3"><?php esc_html_e( '3. Results', 'image-kit' ); ?></span>
			</div>

			<!-- Step 1 -->
			<div class="ik-panel" id="ik-rel-step-1">
				<h3><?php esc_html_e( 'Scan for Relocatable Images', 'image-kit' ); ?></h3>
				<p><?php esc_html_e( 'Scans all subdirectories of the uploads folder for media library images that can be moved to the uploads root.', 'image-kit' ); ?></p>
				<p><button type="button" id="ik-rel-scan" class="button button-primary"><?php esc_html_e( 'Scan for Images', 'image-kit' ); ?></button></p>
				<div id="ik-rel-scan-progress" class="ik-progress" style="display:none;">
					<div class="ik-progress-bar"><div class="ik-progress-fill"></div></div>
					<span class="ik-progress-text"></span>
				</div>
				<div id="ik-rel-scan-result" style="display:none;"></div>
				<div id="ik-rel-table-wrap" style="display:none;">
					<p>
						<label><input type="checkbox" id="ik-rel-select-all" checked> <strong><?php esc_html_e( 'Select All', 'image-kit' ); ?></strong></label>
						<span id="ik-rel-selected-count" class="ik-selected-count" style="margin-left:12px;"></span>
					</p>
					<table class="widefat striped" id="ik-rel-table">
						<thead>
							<tr>
								<th class="ik-col-check"><?php esc_html_e( 'Select', 'image-kit' ); ?></th>
								<th class="ik-col-thumb"><?php esc_html_e( 'Preview', 'image-kit' ); ?></th>
								<th><?php esc_html_e( 'Current Path', 'image-kit' ); ?></th>
								<th><?php esc_html_e( 'Target Filename', 'image-kit' ); ?></th>
								<th><?php esc_html_e( 'Thumbnails', 'image-kit' ); ?></th>
								<th><?php esc_html_e( 'Posts', 'image-kit' ); ?></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
					<p style="margin-top:16px;">
						<button type="button" id="ik-rel-apply" class="button button-primary"><?php esc_html_e( 'Relocate Selected', 'image-kit' ); ?> &rarr;</button>
					</p>
				</div>
			</div>

			<!-- Step 2 -->
			<div class="ik-panel" id="ik-rel-step-2" style="display:none;">
				<h3><?php esc_html_e( 'Relocating Images', 'image-kit' ); ?></h3>
				<div id="ik-rel-apply-progress" class="ik-progress">
					<div class="ik-progress-bar"><div class="ik-progress-fill"></div></div>
					<span class="ik-progress-text"></span>
				</div>
				<div id="ik-rel-apply-log" class="ik-log"></div>
			</div>

			<!-- Step 3 -->
			<div class="ik-panel" id="ik-rel-step-3" style="display:none;">
				<h3><?php esc_html_e( 'Results', 'image-kit' ); ?></h3>
				<div id="ik-rel-results-summary"></div>
				<table class="widefat striped" id="ik-rel-results-table">
					<thead><tr><th><?php esc_html_e( 'File', 'image-kit' ); ?></th><th><?php esc_html_e( 'Status', 'image-kit' ); ?></th><th><?php esc_html_e( 'Details', 'image-kit' ); ?></th></tr></thead>
					<tbody></tbody>
				</table>
				<p style="margin-top:16px;"><button type="button" id="ik-rel-start-over" class="button"><?php esc_html_e( 'Start Over', 'image-kit' ); ?></button></p>
			</div>

			<div class="ik-panel ik-panel-reset">
				<button type="button" id="ik-rel-reset" class="button"><?php esc_html_e( 'Reset', 'image-kit' ); ?></button>
			</div>
		</div>

		<!-- ═══ Import Orphans sub-tab ═══ -->
		<div id="ik-rel-tab-orphans" class="ik-rel-subtab-content" style="display:none;">
			<div class="ik-wizard-steps" id="ik-rel-orphan-steps">
				<span class="ik-step" data-step="1"><?php esc_html_e( '1. Scan', 'image-kit' ); ?></span>
				<span class="ik-step-arrow">&rarr;</span>
				<span class="ik-step" data-step="2"><?php esc_html_e( '2. Import', 'image-kit' ); ?></span>
				<span class="ik-step-arrow">&rarr;</span>
				<span class="ik-step" data-step="3"><?php esc_html_e( '3. Results', 'image-kit' ); ?></span>
			</div>

			<!-- Orphan Step 1 -->
			<div class="ik-panel" id="ik-rel-orphan-step-1">
				<h3><?php esc_html_e( 'Scan for Orphan Files', 'image-kit' ); ?></h3>
				<p><?php esc_html_e( 'Scans uploads for image files not in the media library. Thumbnail variants are grouped with their originals.', 'image-kit' ); ?></p>
				<p><button type="button" id="ik-rel-orphan-scan" class="button button-primary"><?php esc_html_e( 'Scan for Orphan Files', 'image-kit' ); ?></button></p>
				<div id="ik-rel-orphan-progress" class="ik-progress" style="display:none;">
					<div class="ik-progress-bar"><div class="ik-progress-fill"></div></div>
					<span class="ik-progress-text"></span>
				</div>
				<div id="ik-rel-orphan-result" style="display:none;"></div>
				<div id="ik-rel-orphan-table-wrap" style="display:none;">
					<p>
						<label><input type="checkbox" id="ik-rel-orphan-select-all" checked> <strong><?php esc_html_e( 'Select All', 'image-kit' ); ?></strong></label>
						<span id="ik-rel-orphan-selected-count" class="ik-selected-count" style="margin-left:12px;"></span>
					</p>
					<table class="widefat striped" id="ik-rel-orphan-table">
						<thead>
							<tr>
								<th class="ik-col-check"><?php esc_html_e( 'Select', 'image-kit' ); ?></th>
								<th><?php esc_html_e( 'File Path', 'image-kit' ); ?></th>
								<th><?php esc_html_e( 'Size', 'image-kit' ); ?></th>
								<th><?php esc_html_e( 'Variants', 'image-kit' ); ?></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
					<p style="margin-top:16px;">
						<button type="button" id="ik-rel-start-import" class="button button-primary"><?php esc_html_e( 'Import Selected', 'image-kit' ); ?> &rarr;</button>
					</p>
				</div>
			</div>

			<!-- Orphan Step 2 -->
			<div class="ik-panel" id="ik-rel-orphan-step-2" style="display:none;">
				<h3><?php esc_html_e( 'Importing Files', 'image-kit' ); ?></h3>
				<div id="ik-rel-import-progress" class="ik-progress">
					<div class="ik-progress-bar"><div class="ik-progress-fill"></div></div>
					<span class="ik-progress-text"></span>
				</div>
				<div id="ik-rel-import-log" class="ik-log"></div>
			</div>

			<!-- Orphan Step 3 -->
			<div class="ik-panel" id="ik-rel-orphan-step-3" style="display:none;">
				<h3><?php esc_html_e( 'Results', 'image-kit' ); ?></h3>
				<div id="ik-rel-import-summary"></div>
				<table class="widefat striped" id="ik-rel-import-table">
					<thead><tr><th><?php esc_html_e( 'File', 'image-kit' ); ?></th><th><?php esc_html_e( 'Status', 'image-kit' ); ?></th><th><?php esc_html_e( 'Details', 'image-kit' ); ?></th></tr></thead>
					<tbody></tbody>
				</table>
				<p style="margin-top:16px;"><button type="button" id="ik-rel-orphan-start-over" class="button"><?php esc_html_e( 'Start Over', 'image-kit' ); ?></button></p>
			</div>
		</div>
		<?php
	}
}
