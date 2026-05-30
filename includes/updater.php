<?php
/**
 * GitHub-Release-based auto-updates for this plugin.
 *
 * Reads the repository URL (Plugin URI) and slug (Text Domain) from the plugin's
 * own header, so this file is identical across all of our GitHub-hosted plugins.
 * Vendored library: includes/plugin-update-checker/ (Plugin Update Checker v5, MIT).
 *
 * @package randomwire
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

if ( ! function_exists( 'randomwire_init_github_updater' ) ) {
	/**
	 * Wire up update checks against the plugin's GitHub Releases.
	 *
	 * @param string $main_file Absolute path to the plugin's main file (__FILE__).
	 */
	function randomwire_init_github_updater( $main_file ) {
		$data = get_file_data(
			$main_file,
			array(
				'uri'  => 'Plugin URI',
				'slug' => 'Text Domain',
			)
		);

		if ( empty( $data['uri'] ) ) {
			return;
		}

		$slug    = ! empty( $data['slug'] ) ? $data['slug'] : basename( dirname( $main_file ) );
		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			rtrim( $data['uri'], '/' ) . '/',
			$main_file,
			$slug
		);

		// Download the .zip asset we attach to each Release, not GitHub's source tarball.
		$checker->getVcsApi()->enableReleaseAssets( '/\.zip($|[?#])/' );
	}
}
