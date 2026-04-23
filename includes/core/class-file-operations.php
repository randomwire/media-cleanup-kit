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
	 * Move a file to a new location, with fallback to copy+delete.
	 *
	 * @param string $from Source path.
	 * @param string $to   Destination path.
	 * @return bool True on success.
	 */
	public static function move_file( string $from, string $to ): bool {
		if ( ! file_exists( $from ) ) {
			return false;
		}

		// Try rename first (fast, same-filesystem).
		if ( @rename( $from, $to ) ) {
			return true;
		}

		// Fallback: copy + delete.
		if ( copy( $from, $to ) ) {
			wp_delete_file( $from );
			return true;
		}

		return false;
	}

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
	 * Back up a file to the backup directory.
	 *
	 * @param string $file_path  Absolute path to the file to back up.
	 * @param string $backup_dir Absolute path to the backup directory (created if needed).
	 * @return string|false Backup file path on success, false on failure.
	 */
	public static function backup_file( string $file_path, string $backup_dir = '' ): string {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		if ( empty( $backup_dir ) ) {
			$upload_dir = wp_upload_dir();
			$backup_dir = $upload_dir['basedir'] . '/backup';
		}

		if ( ! wp_mkdir_p( $backup_dir ) ) {
			return false;
		}

		$filename    = wp_basename( $file_path );
		$destination = $backup_dir . '/' . $filename;

		// Append timestamp on collision.
		if ( file_exists( $destination ) ) {
			$info        = pathinfo( $filename );
			$ext         = isset( $info['extension'] ) ? '.' . $info['extension'] : '';
			$destination = $backup_dir . '/' . $info['filename'] . '-' . time() . $ext;
		}

		if ( copy( $file_path, $destination ) ) {
			return $destination;
		}

		return false;
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
