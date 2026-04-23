<?php
/**
 * Plugin Name: Image Kit
 * Description: Tools for cleaning up large WordPress media libraries — find broken images, upgrade downsized variants, reorganize uploads, remove unused files, and detect low-resolution images.
 * Version: 1.0.1
 * Author: David Gilbert
 * Requires PHP: 7.4
 * Requires at least: 5.0
 * License: GPL-2.0-or-later
 * Text Domain: image-kit
 */

defined( 'ABSPATH' ) || exit;

define( 'IMAGE_KIT_VERSION', '1.0.1' );
define( 'IMAGE_KIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'IMAGE_KIT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'IMAGE_KIT_PLUGIN_FILE', __FILE__ );

/**
 * Autoloader for Image Kit classes.
 *
 * Maps class prefixes to directories:
 *   Image_Kit_Core_*   → includes/core/
 *   Image_Kit_Module_* → resolved via module registry
 *   Image_Kit_*        → includes/
 */
spl_autoload_register( function ( $class ) {
	// Only handle our classes.
	if ( strpos( $class, 'Image_Kit_' ) !== 0 ) {
		return;
	}

	// Convert class name to file path.
	$relative = substr( $class, strlen( 'Image_Kit_' ) );
	$parts    = explode( '_', $relative );

	// Core utilities: Image_Kit_Core_* → includes/core/class-*.php
	if ( 'Core' === $parts[0] ) {
		array_shift( $parts );
		$filename = 'class-' . strtolower( implode( '-', $parts ) ) . '.php';
		$file     = IMAGE_KIT_PLUGIN_DIR . 'includes/core/' . $filename;
		if ( file_exists( $file ) ) {
			require_once $file;
		}
		return;
	}

	// Admin classes: Image_Kit_Admin_* → includes/admin/class-admin-*.php
	if ( 'Admin' === $parts[0] ) {
		$filename = 'class-' . strtolower( implode( '-', $parts ) ) . '.php';
		$file     = IMAGE_KIT_PLUGIN_DIR . 'includes/admin/' . $filename;
		if ( file_exists( $file ) ) {
			require_once $file;
		}
		return;
	}

	// Top-level includes: Image_Kit_Plugin, Image_Kit_Module → includes/class-*.php
	$filename = 'class-' . strtolower( implode( '-', $parts ) ) . '.php';
	$file     = IMAGE_KIT_PLUGIN_DIR . 'includes/' . $filename;
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Bootstrap.
add_action( 'plugins_loaded', function () {
	Image_Kit_Plugin::instance();
} );

// Activation hook.
register_activation_hook( __FILE__, function () {
	Image_Kit_Plugin::instance()->activate();
} );

// Deactivation hook.
register_deactivation_hook( __FILE__, function () {
	Image_Kit_Plugin::instance()->deactivate();
} );
