<?php
/**
 * Image Kit — Plugin orchestrator.
 *
 * Registers modules, manages activation/deactivation lifecycle,
 * and wires up the admin page.
 */

defined( 'ABSPATH' ) || exit;

class Image_Kit_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Registered modules.
	 *
	 * @var Image_Kit_Module[]
	 */
	private $modules = array();

	/**
	 * Get or create the singleton instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->register_built_in_modules();

		/**
		 * Allow third-party modules to register themselves.
		 *
		 * @param Image_Kit_Plugin $plugin Plugin instance to register modules with.
		 */
		do_action( 'image_kit_modules', $this );

		// Wire up admin page.
		if ( is_admin() ) {
			new Image_Kit_Admin_Page( $this->modules );
		}

		// Donate / GitHub links on the Plugins screen.
		add_filter( 'plugin_row_meta', array( $this, 'add_plugin_row_meta' ), 10, 2 );
	}

	/**
	 * Append Donate + GitHub links to this plugin's row on the Plugins page.
	 *
	 * @param string[] $links Existing row meta.
	 * @param string   $file  Plugin file being filtered.
	 * @return string[]
	 */
	public function add_plugin_row_meta( $links, $file ) {
		if ( plugin_basename( IMAGE_KIT_PLUGIN_FILE ) === $file ) {
			$links[] = '<a href="https://ko-fi.com/randomwire" target="_blank" rel="noopener noreferrer">'
				. esc_html__( 'Donate', 'media-cleanup-kit' )
				. '</a>';
			$links[] = '<a href="https://github.com/randomwire/media-cleanup-kit" target="_blank" rel="noopener noreferrer">'
				. esc_html__( 'GitHub', 'media-cleanup-kit' )
				. '</a>';
		}
		return $links;
	}

	/**
	 * Register a module.
	 */
	public function register_module( Image_Kit_Module $module ): void {
		$this->modules[ $module->get_slug() ] = $module;
	}

	/**
	 * Get all registered modules.
	 *
	 * @return Image_Kit_Module[]
	 */
	public function get_modules(): array {
		return $this->modules;
	}

	/**
	 * Get a module by slug.
	 */
	public function get_module( string $slug ): ?Image_Kit_Module {
		return $this->modules[ $slug ] ?? null;
	}

	/**
	 * Register built-in modules.
	 */
	private function register_built_in_modules(): void {
		$module_dirs = array(
			'broken-images'   => 'Broken_Images',
			'image-upgrader'  => 'Image_Upgrader',
			'markup-audit'    => 'Markup_Audit',
			'relocator'       => 'Relocator',
			'orphan-importer' => 'Orphan_Importer',
			'reattach'        => 'Reattach',
			'unused-cleaner'  => 'Unused_Cleaner',
			'low-resolution'  => 'Low_Resolution',
		);

		foreach ( $module_dirs as $dir => $class_suffix ) {
			$file = IMAGE_KIT_PLUGIN_DIR . "includes/modules/{$dir}/class-module.php";
			if ( file_exists( $file ) ) {
				require_once $file;
				$class = "Image_Kit_Module_{$class_suffix}";
				if ( class_exists( $class ) ) {
					$this->register_module( new $class() );
				}
			}
		}
	}

	/**
	 * Plugin activation.
	 */
	public function activate(): void {
		foreach ( $this->modules as $module ) {
			$module->activate();
		}
	}

	/**
	 * Plugin deactivation.
	 */
	public function deactivate(): void {
		foreach ( $this->modules as $module ) {
			$module->deactivate();
		}
	}
}
