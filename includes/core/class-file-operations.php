<?php
/**
 * Image Kit — File operations utility.
 *
 * Shared file manipulation methods extracted from image-relocate
 * (move, rollback, unique filename) and flickr-upgrader (replace-in-place, backup).
 */

defined( 'ABSPATH' ) || exit;

class Image_Kit_Core_File_Operations {

	/**
	 * Roll back a set of moves.
	 *
	 * @param array $moved_map Array of [ 'from' => original, 'to' => new location ].
	 */
	public static function rollback_moves( array $moved_map ): void {
		foreach ( $moved_map as $move ) {
			if ( file_exists( $move['to'] ) ) {
				@rename( $move['to'], $move['from'] );
			}
		}
	}

	/**
	 * Generate a unique filename on disk (filesystem-only check).
	 *
	 * Avoids wp_unique_filename() which can cause false self-collisions
	 * on WP 5.8.1+ when the file being moved already exists in the DB.
	 *
	 * @param string $directory Target directory.
	 * @param string $filename  Desired filename.
	 * @return string Unique filename (basename only).
	 */
	public static function unique_filename_on_disk( string $directory, string $filename ): string {
		$directory = rtrim( $directory, '/' );

		if ( ! file_exists( $directory . '/' . $filename ) ) {
			return $filename;
		}

		$info = pathinfo( $filename );
		$ext  = isset( $info['extension'] ) ? '.' . $info['extension'] : '';
		$stem = $info['filename'];

		$i = 1;
		while ( file_exists( $directory . '/' . $stem . '-' . $i . $ext ) ) {
			$i++;
		}

		return $stem . '-' . $i . $ext;
	}

	/**
	 * Validate that a path is within an allowed root directory.
	 *
	 * Uses realpath() to prevent directory traversal attacks.
	 *
	 * @param string $path         Path to validate.
	 * @param string $allowed_root Allowed root directory.
	 * @return bool True if path is within allowed root.
	 */
	public static function validate_path_within( string $path, string $allowed_root ): bool {
		$real_path = realpath( $path );
		$real_root = realpath( $allowed_root );

		if ( false === $real_path || false === $real_root ) {
			return false;
		}

		return strpos( $real_path, $real_root ) === 0;
	}
}
