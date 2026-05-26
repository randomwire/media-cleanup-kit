<?php
/**
 * Image Kit — Admin page with tab navigation.
 *
 * Renders the unified Tools > Image Kit page and dispatches
 * to module tab content and AJAX handlers.
 */

defined( 'ABSPATH' ) || exit;

class Image_Kit_Admin_Page {

	const NONCE_ACTION = 'image_kit_nonce';
	const PAGE_SLUG    = 'image-kit';

	/**
	 * @var Image_Kit_Module[]
	 */
	private $modules;

	/**
	 * @param Image_Kit_Module[] $modules Registered modules.
	 */
	public function __construct( array $modules ) {
		$this->modules = $modules;

		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'register_ajax_handlers' ) );
	}

	/**
	 * Register the Tools > Image Kit menu page.
	 */
	public function register_page(): void {
		$hook = add_management_page(
			__( 'Image Kit', 'image-kit' ),
			__( 'Image Kit', 'image-kit' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( $hook ) {
			add_action( "admin_enqueue_scripts", function ( $page_hook ) use ( $hook ) {
				if ( $page_hook !== $hook ) {
					return;
				}
				$this->enqueue_assets();
			} );
		}
	}

	/**
	 * Register all module AJAX handlers.
	 */
	public function register_ajax_handlers(): void {
		foreach ( $this->modules as $module ) {
			$module->register_ajax_handlers();
		}
	}

	/**
	 * Enqueue shared + module-specific assets.
	 */
	private function enqueue_assets(): void {
		wp_enqueue_style(
			'image-kit-admin',
			IMAGE_KIT_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			IMAGE_KIT_VERSION
		);

		wp_enqueue_script(
			'image-kit-admin',
			IMAGE_KIT_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			IMAGE_KIT_VERSION,
			true
		);

		// Shared lightbox helper — auto-wires to .ik-thumb and .ik-iu-thumbnail
		// clicks inside #image-kit-app. No dependencies.
		wp_enqueue_script(
			'image-kit-lightbox',
			IMAGE_KIT_PLUGIN_URL . 'assets/js/lightbox.js',
			array(),
			IMAGE_KIT_VERSION,
			true
		);

		wp_localize_script( 'image-kit-admin', 'imageKit', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
		) );

		foreach ( $this->modules as $module ) {
			$module->enqueue_assets();
		}
	}

	/**
	 * Render the admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$module_list = array_values( $this->modules );
		?>
		<div class="wrap" id="image-kit-app">
			<h1><?php esc_html_e( 'Image Kit', 'image-kit' ); ?></h1>
			<p><?php esc_html_e( 'Tools for cleaning up and optimizing your WordPress media library.', 'image-kit' ); ?></p>

			<?php if ( empty( $module_list ) ) : ?>
				<p><em><?php esc_html_e( 'No modules available.', 'image-kit' ); ?></em></p>
				<?php return; ?>
			<?php endif; ?>

			<div class="ik-tabs-wrap">
				<!-- Tab navigation (left sidebar) -->
				<div class="ik-tabs" role="tablist">
					<?php foreach ( $module_list as $i => $module ) : ?>
						<button type="button"
							class="ik-tab<?php echo 0 === $i ? ' active' : ''; ?>"
							role="tab"
							data-tab="<?php echo esc_attr( $module->get_slug() ); ?>"
							aria-selected="<?php echo 0 === $i ? 'true' : 'false'; ?>"
							aria-controls="ik-tab-<?php echo esc_attr( $module->get_slug() ); ?>">
							<?php echo esc_html( $module->get_name() ); ?>
						</button>
					<?php endforeach; ?>
				</div>

				<!-- Tab panels -->
				<div class="ik-tab-panels">
					<?php foreach ( $module_list as $i => $module ) : ?>
						<div id="ik-tab-<?php echo esc_attr( $module->get_slug() ); ?>"
							class="ik-tab-content"
							role="tabpanel"
							style="<?php echo 0 !== $i ? 'display:none;' : ''; ?>">
							<p class="description"><?php echo esc_html( $module->get_description() ); ?></p>
							<?php $module->render_tab_content(); ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}
}
