<?php
/**
 * Image Kit — Low Resolution module.
 *
 * Scans post content for images below a configurable resolution threshold
 * (default 2048px on the longest side) and lists them for review.
 * Scan-only — no apply/replace workflow.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-scanner.php';

class Image_Kit_Module_Low_Resolution extends Image_Kit_Module {

	const BATCH_SIZE = 20;
	const PAGE_SIZE  = 50;

	public function get_slug(): string {
		return 'low-resolution';
	}

	public function get_name(): string {
		return __( 'Low Resolution', 'image-kit' );
	}

	public function get_description(): string {
		return __( 'Find post-content and featured images below a configurable resolution threshold (scan and report only).', 'image-kit' );
	}

	public function register_ajax_handlers(): void {
		add_action( 'wp_ajax_' . $this->ajax_action( 'scan' ), array( $this, 'ajax_scan' ) );
	}

	public function enqueue_assets(): void {
		wp_enqueue_script(
			'image-kit-low-resolution',
			IMAGE_KIT_PLUGIN_URL . 'assets/js/low-resolution.js',
			array( 'image-kit-admin' ),
			IMAGE_KIT_VERSION,
			true
		);

		wp_localize_script( 'image-kit-low-resolution', 'imageKitLowRes', array(
			'action'    => $this->ajax_action( 'scan' ),
			'batchSize' => self::BATCH_SIZE,
			'pageSize'  => self::PAGE_SIZE,
		) );
	}

	/**
	 * AJAX: Scan a batch of posts for low-resolution images.
	 */
	public function ajax_scan(): void {
		check_ajax_referer( Image_Kit_Admin_Page::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'image-kit' ), 403 );
		}

		$offset      = absint( $_POST['offset'] ?? 0 );
		$threshold   = absint( $_POST['threshold'] ?? 2048 );
		$post_types  = isset( $_POST['post_types'] )
			? array_map( 'sanitize_key', wp_unslash( (array) $_POST['post_types'] ) )
			: array( 'post', 'page' );
		$date_from   = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '';
		$date_to     = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '';
		$known_total = absint( $_POST['total_posts'] ?? 0 );

		$scanner = new Image_Kit_Low_Resolution_Scanner();
		$result  = $scanner->scan_batch( $post_types, $offset, self::BATCH_SIZE, $threshold, $date_from, $date_to, $known_total );

		wp_send_json_success( array(
			'items'       => $result['items'],
			'offset'      => $result['offset'],
			'total_posts' => $result['total_posts'],
			'done'        => $result['done'],
		) );
	}

	public function render_tab_content(): void {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<div id="ik-lr-config">
			<fieldset class="ik-panel">
				<legend><strong><?php esc_html_e( 'Post types to scan:', 'image-kit' ); ?></strong></legend>
				<?php
				foreach ( $post_types as $pt ) {
					if ( 'attachment' === $pt->name ) {
						continue;
					}
					$checked = in_array( $pt->name, array( 'post', 'page' ), true ) ? 'checked' : '';
					printf(
						'<label style="margin-right:16px;"><input type="checkbox" class="ik-lr-post-type" value="%s" %s> %s</label> ',
						esc_attr( $pt->name ),
						$checked,
						esc_html( $pt->label )
					);
				}
				?>
			</fieldset>

			<div style="margin:12px 0;">
				<label>
					<strong><?php esc_html_e( 'Size threshold (px):', 'image-kit' ); ?></strong>
					<input type="number" id="ik-lr-threshold" value="2048" min="0" class="small-text">
					<span class="description"><?php esc_html_e( 'Min longest side. 0 or blank = include all images.', 'image-kit' ); ?></span>
				</label>
			</div>

			<div style="margin:12px 0;">
				<label>
					<strong><?php esc_html_e( 'Date range (optional):', 'image-kit' ); ?></strong>
					<input type="date" id="ik-lr-date-from" class="ik-lr-date-input">
					<span>&ndash;</span>
					<input type="date" id="ik-lr-date-to" class="ik-lr-date-input">
					<span class="description"><?php esc_html_e( 'Filter by post publish date.', 'image-kit' ); ?></span>
				</label>
			</div>

			<p>
				<button type="button" id="ik-lr-scan" class="button button-primary">
					<?php esc_html_e( 'Scan for Low-Resolution Images', 'image-kit' ); ?>
				</button>
			</p>
		</div>

		<div id="ik-lr-progress" class="ik-progress" style="display:none;">
			<p>
				<?php esc_html_e( 'Scanning posts…', 'image-kit' ); ?>
				<span class="ik-progress-text"></span>
			</p>
			<div class="ik-progress-bar"><div class="ik-progress-fill"></div></div>
		</div>

		<div id="ik-lr-results" style="display:none;">
			<h2><?php esc_html_e( 'Scan Results', 'image-kit' ); ?></h2>
			<p id="ik-lr-summary"></p>

			<p id="ik-lr-export-wrap" style="display:none;">
				<button type="button" id="ik-lr-export" class="button"><?php esc_html_e( 'Export CSV', 'image-kit' ); ?></button>
			</p>

			<div id="ik-lr-pagination"></div>

			<table class="widefat striped" id="ik-lr-table" style="display:none;">
				<thead>
					<tr>
						<th class="ik-col-thumb"><?php esc_html_e( 'Thumb', 'image-kit' ); ?></th>
						<th><?php esc_html_e( 'Post', 'image-kit' ); ?></th>
						<th><?php esc_html_e( 'Image', 'image-kit' ); ?></th>
						<th><?php esc_html_e( 'Dimensions', 'image-kit' ); ?></th>
						<th><?php esc_html_e( 'Size Slug', 'image-kit' ); ?></th>
					</tr>
				</thead>
				<tbody id="ik-lr-tbody"></tbody>
			</table>

			<div id="ik-lr-pagination-bottom"></div>
		</div>
		<?php
	}
}
