<?php
/**
 * Image Kit — Orphan Importer module.
 *
 * Finds image files in the uploads directory that aren't in the media library
 * and imports them as attachments.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-scanner.php';

class Image_Kit_Module_Orphan_Importer extends Image_Kit_Module {

	const SCAN_BATCH_SIZE = 500;

	public function get_slug(): string {
		return 'orphan-importer';
	}

	public function get_name(): string {
		return __( 'Import Orphan Files', 'media-cleanup-kit' );
	}

	public function get_description(): string {
		return __( 'Find image files in the uploads directory that aren\'t in the media library and import them as attachments.', 'media-cleanup-kit' );
	}

	public function register_ajax_handlers(): void {
		add_action( 'wp_ajax_' . $this->ajax_action( 'init_scan' ), array( $this, 'ajax_init_scan' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'scan_batch' ), array( $this, 'ajax_scan_batch' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'import_batch' ), array( $this, 'ajax_import_batch' ) );
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
			'image-kit-orphan-importer',
			IMAGE_KIT_PLUGIN_URL . 'assets/js/orphan-importer.js',
			array( 'image-kit-scan-ui' ),
			IMAGE_KIT_VERSION,
			true
		);

		wp_localize_script( 'image-kit-orphan-importer', 'imageKitOrphanImporter', array(
			'initScanAction'  => $this->ajax_action( 'init_scan' ),
			'scanBatchAction' => $this->ajax_action( 'scan_batch' ),
			'importAction'    => $this->ajax_action( 'import_batch' ),
			'scanBatchSize'   => self::SCAN_BATCH_SIZE,
		) );
	}

	private function verify_ajax(): void {
		check_ajax_referer( Image_Kit_Admin_Page::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'media-cleanup-kit' ), 403 );
		}
	}

	public function ajax_init_scan(): void {
		$this->verify_ajax();
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}

		$scanner = new Image_Kit_Orphan_Importer_Scanner();
		$result  = $scanner->init_scan();

		wp_send_json_success( $result );
	}

	public function ajax_scan_batch(): void {
		$this->verify_ajax();
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 60 );
		}

		$token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'Missing scan token.', 'media-cleanup-kit' ) ) );
		}

		$scanner = new Image_Kit_Orphan_Importer_Scanner();
		$result  = $scanner->scan_orphans_batch( $token, $offset, self::SCAN_BATCH_SIZE );

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		// Stamp each grouped orphan with a stable id derived from its path.
		if ( ! empty( $result['items'] ) && is_array( $result['items'] ) ) {
			$idx = 0;
			foreach ( $result['items'] as &$item ) {
				$item['id'] = 'oi-' . $idx++;
			}
			unset( $item );
		}

		$result['progress']  = array( 'files_compared' => isset( $result['offset'] ) ? (int) $result['offset'] : 0 );
		$result['log_lines'] = array();

		wp_send_json_success( $result );
	}

	public function ajax_import_batch(): void {
		$this->verify_ajax();

		$paths = isset( $_POST['paths'] )
			? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['paths'] ) )
			: array();

		if ( empty( $paths ) ) {
			wp_send_json_error( __( 'No files specified.', 'media-cleanup-kit' ) );
		}

		$scanner = new Image_Kit_Orphan_Importer_Scanner();
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
		<div class="ik-panel ik-scan-config" id="ik-oi-config">
			<h3><?php esc_html_e( 'Scan for Orphan Files', 'media-cleanup-kit' ); ?></h3>
			<p><?php esc_html_e( 'Scans uploads for image files not in the media library. Thumbnail variants are grouped with their originals.', 'media-cleanup-kit' ); ?></p>
			<p>
				<button type="button" id="ik-oi-scan" class="button button-primary"><?php esc_html_e( 'Scan for Orphan Files', 'media-cleanup-kit' ); ?></button>
			</p>
			<div id="ik-oi-indexing" style="display:none;">
				<p><em><?php esc_html_e( 'Indexing files…', 'media-cleanup-kit' ); ?></em></p>
			</div>
		</div>

		<div class="ik-panel ik-scan-progress" id="ik-oi-progress"></div>
		<div class="ik-panel ik-scan-results" id="ik-oi-results"></div>
		<?php
	}
}
