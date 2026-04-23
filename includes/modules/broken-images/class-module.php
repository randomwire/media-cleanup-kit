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
		return __( 'Broken Images', 'image-kit' );
	}

	public function get_description(): string {
		return __( 'Scan posts and pages for internal image embeds and featured images where the file is missing from the uploads directory.', 'image-kit' );
	}

	public function register_ajax_handlers(): void {
		add_action( 'wp_ajax_' . $this->ajax_action( 'scan' ), array( $this, 'ajax_scan' ) );
	}

	public function enqueue_assets(): void {
		wp_enqueue_script(
			'image-kit-broken-images',
			IMAGE_KIT_PLUGIN_URL . 'assets/js/broken-images.js',
			array( 'image-kit-admin' ),
			IMAGE_KIT_VERSION,
			true
		);

		wp_localize_script( 'image-kit-broken-images', 'imageKitBrokenImages', array(
			'action'    => $this->ajax_action( 'scan' ),
			'batchSize' => self::BATCH_SIZE,
			'pageSize'  => self::PAGE_SIZE,
		) );
	}

	public function ajax_scan(): void {
		check_ajax_referer( Image_Kit_Admin_Page::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'image-kit' ), 403 );
		}

		$scanner     = new Image_Kit_Broken_Images_Scanner();
		$post_offset = absint( $_POST['post_offset'] ?? 0 );
		$post_total  = $scanner->get_candidate_post_count();
		$posts       = $scanner->get_post_batch( $post_offset, self::BATCH_SIZE );
		$broken      = $scanner->check_posts( $posts );
		$processed   = min( $post_offset + self::BATCH_SIZE, $post_total );

		wp_send_json_success( array(
			'broken'          => $broken,
			'posts_processed' => $processed,
			'posts_total'     => $post_total,
			'done'            => $processed >= $post_total,
		) );
	}

	public function render_tab_content(): void {
		$scanner    = new Image_Kit_Broken_Images_Scanner();
		$post_count = $scanner->get_candidate_post_count();
		?>
		<div id="ik-bi-status">
			<p>
				<?php
				printf(
					/* translators: %s: number of posts */
					esc_html__( 'Found %s post(s) containing image references.', 'image-kit' ),
					'<strong>' . esc_html( number_format_i18n( $post_count ) ) . '</strong>'
				);
				?>
			</p>
			<?php if ( $post_count > 0 ) : ?>
				<button type="button" id="ik-bi-scan" class="button button-primary"
					data-total="<?php echo (int) $post_count; ?>">
					<?php esc_html_e( 'Scan for Broken Images', 'image-kit' ); ?>
				</button>
			<?php else : ?>
				<p><em><?php esc_html_e( 'No posts with image references found.', 'image-kit' ); ?></em></p>
			<?php endif; ?>
		</div>

		<div id="ik-bi-progress" class="ik-progress" style="display:none;">
			<p><?php esc_html_e( 'Scanning posts…', 'image-kit' ); ?>
				<span class="ik-progress-text">0 / <?php echo esc_html( number_format_i18n( $post_count ) ); ?> <?php esc_html_e( 'posts checked', 'image-kit' ); ?></span>
			</p>
			<div class="ik-progress-bar"><div class="ik-progress-fill"></div></div>
		</div>

		<div id="ik-bi-results" style="display:none;">
			<h2><?php esc_html_e( 'Scan Results', 'image-kit' ); ?></h2>
			<p id="ik-bi-summary"></p>

			<p id="ik-bi-export-wrap" style="display:none;">
				<button type="button" id="ik-bi-export" class="button"><?php esc_html_e( 'Export CSV', 'image-kit' ); ?></button>
			</p>

			<div id="ik-bi-pagination"></div>

			<table class="widefat striped" id="ik-bi-table" style="display:none;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post', 'image-kit' ); ?></th>
						<th><?php esc_html_e( 'Broken Image', 'image-kit' ); ?></th>
						<th><?php esc_html_e( 'Block Type', 'image-kit' ); ?></th>
					</tr>
				</thead>
				<tbody id="ik-bi-tbody"></tbody>
			</table>

			<div id="ik-bi-pagination-bottom"></div>
		</div>
		<?php
	}
}
