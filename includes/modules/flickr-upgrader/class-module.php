<?php
/**
 * Media Cleanup Kit — Replace Flickr Images module.
 *
 * Mirrors the Replace Low-Res Images workflow shape:
 *   1. Scan posts for Gutenberg wp:image blocks whose src points at a
 *      Flickr-hosted file (filename pattern {photo_id}_{secret}_{size}.{ext}).
 *   2. Export CSV + handoff panel showing the local flickr-fetch.py command
 *      and the rsync command that uploads the results to
 *      wp-content/uploads/flickr-replacements/.
 *   3. Scan that drop directory, resolve each file to its existing attachment
 *      via the Flickr photo_id prefix, and apply replacements with backup +
 *      thumbnail regeneration + block JSON cleanup.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-scanner.php';
require_once __DIR__ . '/class-apply.php';

class Image_Kit_Module_Flickr_Upgrader extends Image_Kit_Module {

	const BATCH_SIZE = 20;
	const PAGE_SIZE  = 50;

	public function get_slug(): string {
		return 'flickr-upgrader';
	}

	public function get_name(): string {
		return __( 'Replace Flickr Images', 'media-cleanup-kit' );
	}

	public function get_description(): string {
		return __( 'Find embedded Flickr images in posts and replace them with larger versions fetched via the bundled flickr-fetch.py workflow.', 'media-cleanup-kit' );
	}

	public function register_ajax_handlers(): void {
		add_action( 'wp_ajax_' . $this->ajax_action( 'scan' ),         array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'scan_drop' ),    array( $this, 'ajax_scan_drop' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'apply' ),        array( $this, 'ajax_apply' ) );
		add_action( 'wp_ajax_' . $this->ajax_action( 'cleanup_drop' ), array( $this, 'ajax_cleanup_drop' ) );
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
			'image-kit-flickr-upgrader',
			IMAGE_KIT_PLUGIN_URL . 'assets/js/flickr-upgrader.js',
			array( 'image-kit-scan-ui' ),
			IMAGE_KIT_VERSION,
			true
		);

		$uploads = wp_upload_dir();

		wp_localize_script( 'image-kit-flickr-upgrader', 'imageKitFlickr', array(
			'action'           => $this->ajax_action( 'scan' ),
			'scanDropAction'   => $this->ajax_action( 'scan_drop' ),
			'applyAction'      => $this->ajax_action( 'apply' ),
			'cleanupDropAction'=> $this->ajax_action( 'cleanup_drop' ),
			'batchSize'        => self::BATCH_SIZE,
			'pageSize'         => self::PAGE_SIZE,
			'uploadsBasedir'   => $uploads['basedir'],
			'dropDirName'      => Image_Kit_Flickr_Upgrader_Apply::DROP_DIR_NAME,
		) );
	}

	private function verify_ajax(): void {
		check_ajax_referer( Image_Kit_Admin_Page::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'media-cleanup-kit' ) ), 403 );
		}
	}

	public function ajax_scan(): void {
		$this->verify_ajax();

		$offset      = absint( $_POST['offset'] ?? 0 );
		$post_types  = isset( $_POST['post_types'] )
			? array_map( 'sanitize_key', wp_unslash( (array) $_POST['post_types'] ) )
			: array( 'post', 'page' );
		$date_from   = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '';
		$date_to     = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '';
		$known_total = absint( $_POST['total_posts'] ?? 0 );

		$scanner = new Image_Kit_Flickr_Upgrader_Scanner();
		$result  = $scanner->scan_batch( $post_types, $offset, self::BATCH_SIZE, $date_from, $date_to, $known_total );

		// Stamp each item with a stable id (helper rowKey).
		$idx = $offset * 100;
		foreach ( $result['items'] as &$item ) {
			$item['id'] = ++$idx;
		}
		unset( $item );

		wp_send_json_success( array(
			'items'     => $result['items'],
			'offset'    => $result['offset'],
			'total'     => $result['total_posts'],
			'done'      => $result['done'],
			'progress'  => array( 'posts_scanned' => $result['offset'] ),
			'log_lines' => array(),
		) );
	}

	public function ajax_scan_drop(): void {
		$this->verify_ajax();
		$apply  = new Image_Kit_Flickr_Upgrader_Apply();
		$result = $apply->scan_drop();
		if ( ! $result['ok'] ) {
			wp_send_json_error( array(
				'message' => __( 'Could not scan flickr-replacements directory.', 'media-cleanup-kit' ),
				'details' => $result['errors'],
			) );
		}
		wp_send_json_success( array( 'items' => $result['items'] ) );
	}

	public function ajax_apply(): void {
		$this->verify_ajax();
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing attachment_id.', 'media-cleanup-kit' ) ) );
		}
		$apply  = new Image_Kit_Flickr_Upgrader_Apply();
		$result = $apply->apply_one( $attachment_id );
		if ( ! $result['success'] ) {
			wp_send_json_error( $result );
		}
		wp_send_json_success( $result );
	}

	public function ajax_cleanup_drop(): void {
		$this->verify_ajax();
		$apply  = new Image_Kit_Flickr_Upgrader_Apply();
		$result = $apply->cleanup_drop_dir();
		if ( ! $result['success'] ) {
			wp_send_json_error( $result );
		}
		wp_send_json_success( $result );
	}

	public function render_tab_content(): void {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<div class="ik-panel ik-scan-config" id="ik-fu-config">
			<?php $this->render_panel_header(); ?>
			<fieldset>
				<legend><strong><?php esc_html_e( 'Post types to scan:', 'media-cleanup-kit' ); ?></strong></legend>
				<?php
				foreach ( $post_types as $pt ) {
					if ( 'attachment' === $pt->name ) {
						continue;
					}
					$checked = in_array( $pt->name, array( 'post', 'page' ), true ) ? 'checked' : '';
					printf(
						'<label style="margin-right:16px;"><input type="checkbox" class="ik-fu-post-type" value="%s" %s> %s</label> ',
						esc_attr( $pt->name ),
						$checked,
						esc_html( $pt->label )
					);
				}
				?>
			</fieldset>

			<div style="margin:12px 0;">
				<label>
					<strong><?php esc_html_e( 'Date range (optional):', 'media-cleanup-kit' ); ?></strong>
					<input type="date" id="ik-fu-date-from" class="ik-fu-date-input">
					<span>&ndash;</span>
					<input type="date" id="ik-fu-date-to" class="ik-fu-date-input">
					<span class="description"><?php esc_html_e( 'Filter by post publish date.', 'media-cleanup-kit' ); ?></span>
				</label>
			</div>

			<p>
				<button type="button" id="ik-fu-scan" class="button button-primary">
					<?php esc_html_e( 'Scan for Flickr Images', 'media-cleanup-kit' ); ?>
				</button>
			</p>
		</div>

		<div class="ik-panel ik-scan-progress" id="ik-fu-progress"></div>
		<div class="ik-panel ik-scan-results" id="ik-fu-results"></div>

		<div id="ik-fu-handoff" class="ik-panel" style="display:none;margin-top:24px;">
			<h3><?php esc_html_e( 'Next: fetch larger versions from Flickr', 'media-cleanup-kit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Hand the scan results off to the offline flickr-fetch.py script, which calls the Flickr API for each photo and downloads the largest available size. The commands below use absolute paths for your install — run them from a working directory on your Mac.', 'media-cleanup-kit' ); ?>
			</p>

			<h4><?php esc_html_e( '1. Export the CSV', 'media-cleanup-kit' ); ?></h4>
			<p class="description">
				<?php
				printf(
					/* translators: %s: name of the CSV file the export produces */
					esc_html__( 'Click Export CSV in the results toolbar above to download %s — this is the file the script reads.', 'media-cleanup-kit' ),
					'<code>flickr-images.csv</code>'
				);
				?>
			</p>

			<h4><?php esc_html_e( '2. Run flickr-fetch.py locally', 'media-cleanup-kit' ); ?></h4>
			<p class="description">
				<?php
				printf(
					/* translators: %s: path to flickr-fetch.py inside the plugin */
					esc_html__( 'The script ships with the plugin at %s. You need a Flickr API key. Run it from the same directory as the CSV you just downloaded.', 'media-cleanup-kit' ),
					'<code>' . esc_html( 'wp-content/plugins/media-cleanup-kit/tools/flickr-fetch.py' ) . '</code>'
				);
				?>
			</p>
			<pre id="ik-fu-fetch-cmd" class="ik-code-block"></pre>

			<h4><?php esc_html_e( '3. Upload the downloaded files', 'media-cleanup-kit' ); ?></h4>
			<pre id="ik-fu-rsync-up" class="ik-code-block"></pre>

			<h4><?php esc_html_e( '4. Apply replacements', 'media-cleanup-kit' ); ?></h4>
			<p>
				<button type="button" id="ik-fu-scan-drop" class="button button-primary">
					<?php esc_html_e( 'Scan flickr-replacements directory', 'media-cleanup-kit' ); ?>
				</button>
			</p>
			<div id="ik-fu-apply-errors" style="display:none;"></div>
			<div id="ik-fu-apply-results" style="display:none;">
				<p id="ik-fu-apply-summary"></p>
				<p>
					<label><input type="checkbox" id="ik-fu-apply-select-all" checked> <strong><?php esc_html_e( 'Select all', 'media-cleanup-kit' ); ?></strong></label>
					<button type="button" id="ik-fu-apply-btn" class="button button-primary" style="margin-left:12px;">
						<?php esc_html_e( 'Apply Selected', 'media-cleanup-kit' ); ?>
					</button>
					<button type="button" id="ik-fu-cleanup-btn" class="button" style="margin-left:8px;display:none;">
						<?php esc_html_e( 'Delete flickr-replacements directory', 'media-cleanup-kit' ); ?>
					</button>
				</p>
				<div id="ik-fu-apply-progress" class="ik-progress" style="display:none;">
					<div class="ik-progress-bar"><div class="ik-progress-fill"></div></div>
					<span class="ik-progress-text"></span>
				</div>
				<table class="widefat striped" id="ik-fu-apply-table">
					<thead>
						<tr>
							<th class="ik-col-check"></th>
							<th><?php esc_html_e( 'Attachment', 'media-cleanup-kit' ); ?></th>
							<th><?php esc_html_e( 'Original', 'media-cleanup-kit' ); ?></th>
							<th><?php esc_html_e( 'Replacement', 'media-cleanup-kit' ); ?></th>
							<th><?php esc_html_e( 'Photo ID', 'media-cleanup-kit' ); ?></th>
							<th><?php esc_html_e( 'Status', 'media-cleanup-kit' ); ?></th>
						</tr>
					</thead>
					<tbody id="ik-fu-apply-tbody"></tbody>
				</table>
			</div>
		</div>
		<?php
	}
}
