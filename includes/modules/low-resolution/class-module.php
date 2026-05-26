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
require_once __DIR__ . '/class-apply.php';

class Image_Kit_Module_Low_Resolution extends Image_Kit_Module {

	const BATCH_SIZE = 20;
	const PAGE_SIZE  = 50;

	public function get_slug(): string {
		return 'low-resolution';
	}

	public function get_name(): string {
		return __( 'Replace Low-Res Images', 'media-cleanup-kit' );
	}

	public function get_description(): string {
		return __( 'Find post-content and featured images below a configurable resolution threshold (scan and report only).', 'media-cleanup-kit' );
	}

	public function register_ajax_handlers(): void {
		add_action( 'wp_ajax_' . $this->ajax_action( 'scan' ), array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'scan_matched' ), array( $this, 'ajax_scan_matched' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'apply_matched' ), array( $this, 'ajax_apply_matched' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'cleanup_matched' ), array( $this, 'ajax_cleanup_matched' ) );
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
			'image-kit-low-resolution',
			IMAGE_KIT_PLUGIN_URL . 'assets/js/low-resolution.js',
			array( 'image-kit-scan-ui' ),
			IMAGE_KIT_VERSION,
			true
		);

		$uploads = wp_upload_dir();

		wp_localize_script( 'image-kit-low-resolution', 'imageKitLowRes', array(
			'action'              => $this->ajax_action( 'scan' ),
			'scanMatchedAction'   => $this->ajax_action( 'scan_matched' ),
			'applyMatchedAction'  => $this->ajax_action( 'apply_matched' ),
			'cleanupMatchedAction'=> $this->ajax_action( 'cleanup_matched' ),
			'batchSize'           => self::BATCH_SIZE,
			'pageSize'            => self::PAGE_SIZE,
			'uploadsBasedir'      => $uploads['basedir'],
			'matchedDirName'      => 'matched-photos',
		) );
	}

	/**
	 * AJAX: Scan a batch of posts for low-resolution images.
	 */
	public function ajax_scan(): void {
		check_ajax_referer( Image_Kit_Admin_Page::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'media-cleanup-kit' ), 403 );
		}

		$offset      = absint( $_POST['offset'] ?? 0 );
		$threshold   = absint( $_POST['threshold'] ?? 2048 );
		$post_types  = isset( $_POST['post_types'] )
			? array_map( 'sanitize_key', wp_unslash( (array) $_POST['post_types'] ) )
			: array( 'post', 'page' );
		$date_from   = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '';
		$date_to     = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '';
		$known_total = absint( $_POST['total_posts'] ?? 0 );

		$size_slugs = array();
		if ( isset( $_POST['size_slugs'] ) ) {
			foreach ( wp_unslash( (array) $_POST['size_slugs'] ) as $raw_slug ) {
				$slug = sanitize_key( $raw_slug );
				$size_slugs[] = ( 'none' === $slug ) ? '' : $slug;
			}
		}

		$scanner = new Image_Kit_Low_Resolution_Scanner();
		$result  = $scanner->scan_batch( $post_types, $offset, self::BATCH_SIZE, $threshold, $date_from, $date_to, $known_total, $size_slugs );

		// Stamp each item with a stable id (helper rowKey).
		$idx = $offset * 100;
		foreach ( $result['items'] as &$item ) {
			$item['id'] = ++$idx;
		}
		unset( $item );

		wp_send_json_success( array(
			'items'    => $result['items'],
			'offset'   => $result['offset'],
			'total'    => $result['total_posts'],
			'done'     => $result['done'],
			'progress' => array(
				'posts_scanned' => $result['offset'],
			),
			'log_lines' => array(),
		) );
	}

	private function verify_ajax(): void {
		check_ajax_referer( Image_Kit_Admin_Page::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'media-cleanup-kit' ) ), 403 );
		}
	}

	public function ajax_scan_matched(): void {
		$this->verify_ajax();
		$apply  = new Image_Kit_Low_Resolution_Apply();
		$result = $apply->scan_matched();
		if ( ! $result['ok'] ) {
			wp_send_json_error( array(
				'message' => __( 'Could not scan matched-photos directory.', 'media-cleanup-kit' ),
				'details' => $result['errors'],
			) );
		}
		wp_send_json_success( array( 'items' => $result['items'] ) );
	}

	public function ajax_apply_matched(): void {
		$this->verify_ajax();
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing attachment_id.', 'media-cleanup-kit' ) ) );
		}
		$apply  = new Image_Kit_Low_Resolution_Apply();
		$result = $apply->apply_one( $attachment_id );
		if ( ! $result['success'] ) {
			wp_send_json_error( $result );
		}
		wp_send_json_success( $result );
	}

	public function ajax_cleanup_matched(): void {
		$this->verify_ajax();
		$apply  = new Image_Kit_Low_Resolution_Apply();
		$result = $apply->cleanup_matched_dir();
		if ( ! $result['success'] ) {
			wp_send_json_error( $result );
		}
		wp_send_json_success( $result );
	}

	public function render_tab_content(): void {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<div class="ik-panel ik-scan-config" id="ik-lr-config">
			<fieldset>
				<legend><strong><?php esc_html_e( 'Post types to scan:', 'media-cleanup-kit' ); ?></strong></legend>
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

			<fieldset style="margin-top:12px;">
				<legend><strong><?php esc_html_e( 'Size slugs to include (optional):', 'media-cleanup-kit' ); ?></strong></legend>
				<?php
				$registered_sizes = get_intermediate_image_sizes();
				if ( ! in_array( 'full', $registered_sizes, true ) ) {
					$registered_sizes[] = 'full';
				}
				$slug_options = array();
				foreach ( $registered_sizes as $slug ) {
					$slug_options[ $slug ] = $slug;
				}
				$slug_options['featured-image'] = __( 'Featured Image', 'media-cleanup-kit' );
				$slug_options['none']           = __( 'Unspecified', 'media-cleanup-kit' );

				foreach ( $slug_options as $value => $label ) {
					printf(
						'<label style="margin-right:16px;"><input type="checkbox" class="ik-lr-size-slug" value="%s"> %s</label> ',
						esc_attr( $value ),
						esc_html( $label )
					);
				}
				?>
				<p class="description" style="margin-top:6px;">
					<?php esc_html_e( 'Leave all unchecked to include every size slug.', 'media-cleanup-kit' ); ?>
				</p>
			</fieldset>

			<div style="margin:12px 0;">
				<label>
					<strong><?php esc_html_e( 'Size threshold (px):', 'media-cleanup-kit' ); ?></strong>
					<input type="number" id="ik-lr-threshold" value="2048" min="0" class="small-text">
					<span class="description"><?php esc_html_e( 'Min longest side. 0 or blank = include all images.', 'media-cleanup-kit' ); ?></span>
				</label>
			</div>

			<div style="margin:12px 0;">
				<label>
					<strong><?php esc_html_e( 'Date range (optional):', 'media-cleanup-kit' ); ?></strong>
					<input type="date" id="ik-lr-date-from" class="ik-lr-date-input">
					<span>&ndash;</span>
					<input type="date" id="ik-lr-date-to" class="ik-lr-date-input">
					<span class="description"><?php esc_html_e( 'Filter by post publish date.', 'media-cleanup-kit' ); ?></span>
				</label>
			</div>

			<p>
				<button type="button" id="ik-lr-scan" class="button button-primary">
					<?php esc_html_e( 'Scan for Low-Resolution Images', 'media-cleanup-kit' ); ?>
				</button>
			</p>
		</div>

		<div class="ik-panel ik-scan-progress" id="ik-lr-progress"></div>
		<div class="ik-panel ik-scan-results" id="ik-lr-results"></div>

		<div id="ik-lr-handoff" class="ik-panel" style="display:none;margin-top:24px;">
				<h3><?php esc_html_e( 'Next: match against your photo library', 'media-cleanup-kit' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Hand the selected images off to the offline photo-match script. The commands below use absolute paths for your install — run them from a working directory on your Mac.', 'media-cleanup-kit' ); ?>
				</p>

				<h4><?php esc_html_e( '1. Download the selected images', 'media-cleanup-kit' ); ?></h4>
				<p class="description">
					<?php esc_html_e( 'Click Export CSV above first — it also writes a sibling low-resolution-files.txt that the next command consumes.', 'media-cleanup-kit' ); ?>
				</p>
				<pre id="ik-lr-rsync-down" class="ik-code-block"></pre>

				<h4><?php esc_html_e( '2. Run photo-match.py locally', 'media-cleanup-kit' ); ?></h4>
				<p class="description">
					<?php
					printf(
						/* translators: %s: path to photo-match.py inside the plugin */
						esc_html__( 'The script ships with the plugin at %s. Export your source photos (e.g. from Apple Photos) into ./exported-photos/ first.', 'media-cleanup-kit' ),
						'<code>' . esc_html( 'wp-content/plugins/image-kit/tools/photo-match.py' ) . '</code>'
					);
					?>
				</p>
				<pre id="ik-lr-pymatch-cmd" class="ik-code-block"></pre>

				<h4><?php esc_html_e( '3. Upload the matched-photos directory', 'media-cleanup-kit' ); ?></h4>
				<pre id="ik-lr-rsync-up" class="ik-code-block"></pre>

				<h4><?php esc_html_e( '4. Apply matches', 'media-cleanup-kit' ); ?></h4>
				<p>
					<button type="button" id="ik-lr-scan-matched" class="button button-primary">
						<?php esc_html_e( 'Scan matched-photos directory', 'media-cleanup-kit' ); ?>
					</button>
				</p>
				<div id="ik-lr-apply-errors" style="display:none;"></div>
				<div id="ik-lr-apply-results" style="display:none;">
					<p id="ik-lr-apply-summary"></p>
					<p>
						<label><input type="checkbox" id="ik-lr-apply-select-all" checked> <strong><?php esc_html_e( 'Select all', 'media-cleanup-kit' ); ?></strong></label>
						<button type="button" id="ik-lr-apply-btn" class="button button-primary" style="margin-left:12px;">
							<?php esc_html_e( 'Apply Selected', 'media-cleanup-kit' ); ?>
						</button>
						<button type="button" id="ik-lr-cleanup-btn" class="button" style="margin-left:8px;display:none;">
							<?php esc_html_e( 'Delete matched-photos directory', 'media-cleanup-kit' ); ?>
						</button>
					</p>
					<div id="ik-lr-apply-progress" class="ik-progress" style="display:none;">
						<div class="ik-progress-bar"><div class="ik-progress-fill"></div></div>
						<span class="ik-progress-text"></span>
					</div>
					<table class="widefat striped" id="ik-lr-apply-table">
						<thead>
							<tr>
								<th class="ik-col-check"></th>
								<th><?php esc_html_e( 'Attachment', 'media-cleanup-kit' ); ?></th>
								<th><?php esc_html_e( 'Original', 'media-cleanup-kit' ); ?></th>
								<th><?php esc_html_e( 'Replacement', 'media-cleanup-kit' ); ?></th>
								<th><?php esc_html_e( 'Confidence', 'media-cleanup-kit' ); ?></th>
								<th><?php esc_html_e( 'Status', 'media-cleanup-kit' ); ?></th>
							</tr>
						</thead>
						<tbody id="ik-lr-apply-tbody"></tbody>
					</table>
				</div>
		</div>
		<?php
	}
}
