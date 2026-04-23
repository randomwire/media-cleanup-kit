<?php
/**
 * Image Kit — Abstract module base class.
 *
 * Every module extends this and implements the required methods.
 */

defined( 'ABSPATH' ) || exit;

abstract class Image_Kit_Module {

	/**
	 * Unique slug for this module (used in AJAX actions, tab IDs, etc.).
	 */
	abstract public function get_slug(): string;

	/**
	 * Human-readable name shown in the tab.
	 */
	abstract public function get_name(): string;

	/**
	 * Short description shown below the tab heading.
	 */
	abstract public function get_description(): string;

	/**
	 * Register AJAX handlers for this module.
	 *
	 * Called during admin_init. Actions should use the prefix:
	 * image_kit_{slug}_{action}
	 */
	abstract public function register_ajax_handlers(): void;

	/**
	 * Render the tab content for the admin page.
	 *
	 * Called inside the Image Kit admin page when this module's tab is active.
	 */
	abstract public function render_tab_content(): void;

	/**
	 * Enqueue module-specific JS/CSS.
	 *
	 * Called only on the Image Kit admin page. The shared admin.js and admin.css
	 * are already enqueued by the admin page class.
	 */
	public function enqueue_assets(): void {}

	/**
	 * Called on plugin activation. Override to create tables, options, etc.
	 */
	public function activate(): void {}

	/**
	 * Called on plugin deactivation. Override to clear cron jobs, etc.
	 */
	public function deactivate(): void {}

	/**
	 * Called on plugin uninstall. Override to drop tables, delete options, etc.
	 */
	public static function uninstall(): void {}

	/**
	 * Helper: get the AJAX action name for this module.
	 */
	protected function ajax_action( string $action ): string {
		return 'image_kit_' . $this->get_slug_underscored() . '_' . $action;
	}

	/**
	 * Get slug with hyphens replaced by underscores (for AJAX action names).
	 */
	protected function get_slug_underscored(): string {
		return str_replace( '-', '_', $this->get_slug() );
	}
}
