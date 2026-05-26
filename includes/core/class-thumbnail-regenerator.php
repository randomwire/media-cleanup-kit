<?php
/**
 * Image Kit — Throttled thumbnail regenerator.
 *
 * Extracted from flickr-upgrader and low-scan. Regenerates attachment
 * metadata with protections against server overload:
 * - Extended PHP time limit
 * - Lowered process priority
 * - Temporary reduction of registered image sizes (thumbnail only)
 */

defined( 'ABSPATH' ) || exit;

class Image_Kit_Core_Thumbnail_Regenerator {

	/**
	 * Stored reference to the intermediate_image_sizes filter closure.
	 *
	 * @var callable|null
	 */
	private $size_filter_callback = null;

	/**
	 * Replace the physical file for an existing attachment in-place.
	 *
	 * Copies the new file over the old file's path, deletes old thumbnails,
	 * updates attachment metadata, and regenerates thumbnails.
	 *
	 * @param int    $attachment_id Existing WP attachment ID.
	 * @param string $new_file_path Absolute path to the new (larger) file.
	 * @param bool   $reduce_sizes  Whether to reduce image sizes during regen.
	 * @return array|\WP_Error New attachment metadata on success.
	 */
	public function replace_file_in_place( int $attachment_id, string $new_file_path, bool $reduce_sizes = true ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$this->apply_server_protections();

		$old_file = get_attached_file( $attachment_id );
		if ( ! $old_file ) {
			$this->reset_server_protections();
			return new \WP_Error( 'no_attached_file', __( 'Attachment has no associated file.', 'image-kit' ) );
		}

		if ( ! file_exists( $new_file_path ) ) {
			$this->reset_server_protections();
			return new \WP_Error( 'source_missing', __( 'New file does not exist.', 'image-kit' ) );
		}

		// Delete old thumbnails.
		$old_meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $old_meta ) && ! empty( $old_meta['sizes'] ) ) {
			$old_dir = dirname( $old_file );
			foreach ( $old_meta['sizes'] as $size_data ) {
				$thumb_path = $old_dir . '/' . $size_data['file'];
				if ( file_exists( $thumb_path ) ) {
					wp_delete_file( $thumb_path );
				}
			}
		}

		// Copy new file over old path.
		if ( ! copy( $new_file_path, $old_file ) ) {
			$this->reset_server_protections();
			return new \WP_Error( 'copy_failed', __( 'Failed to copy new file over old file.', 'image-kit' ) );
		}

		// Temporarily reduce image sizes during regeneration.
		$saved_sizes = null;
		/** This filter is documented in includes/core/class-thumbnail-regenerator.php */
		$should_reduce = apply_filters( 'image_kit_reduce_thumbnail_sizes', $reduce_sizes );
		if ( $should_reduce ) {
			$saved_sizes = $this->reduce_image_sizes();
		}

		// Generate new metadata (triggers thumbnail creation).
		$new_meta = wp_generate_attachment_metadata( $attachment_id, $old_file );

		// Restore all image sizes.
		if ( null !== $saved_sizes ) {
			$this->restore_image_sizes( $saved_sizes );
		}

		if ( empty( $new_meta ) ) {
			$this->reset_server_protections();
			return new \WP_Error( 'metadata_failed', __( 'Failed to generate attachment metadata.', 'image-kit' ) );
		}

		wp_update_attachment_metadata( $attachment_id, $new_meta );

		$this->reset_server_protections();

		return $new_meta;
	}

	/**
	 * Apply server protections for CPU/IO heavy operations.
	 */
	private function apply_server_protections(): void {
		// Extend PHP time limit.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 180 );
		}

		// Lower process priority.
		if ( function_exists( 'proc_nice' ) ) {
			@proc_nice( 10 );
		}

		// Ensure sufficient memory.
		if ( ! defined( 'WP_MAX_MEMORY_LIMIT' ) ) {
			@ini_set( 'memory_limit', '512M' );
		}
	}

	/**
	 * Reset server protections after heavy operations.
	 */
	private function reset_server_protections(): void {
		if ( function_exists( 'proc_nice' ) ) {
			@proc_nice( 0 );
		}
	}

	/**
	 * Temporarily reduce registered image sizes to thumbnail only.
	 *
	 * @return array Original sizes array for restoration.
	 */
	private function reduce_image_sizes(): array {
		global $_wp_additional_image_sizes;

		$keep = array( 'thumbnail' );

		$saved = isset( $_wp_additional_image_sizes ) ? $_wp_additional_image_sizes : array();
		$_wp_additional_image_sizes = array();

		$this->size_filter_callback = function ( $sizes ) use ( $keep ) {
			return array_intersect( $sizes, $keep );
		};
		add_filter( 'intermediate_image_sizes', $this->size_filter_callback, 999 );

		return $saved;
	}

	/**
	 * Restore all image sizes after thumbnail regeneration.
	 *
	 * @param array $saved_sizes The original $_wp_additional_image_sizes.
	 */
	private function restore_image_sizes( array $saved_sizes ): void {
		global $_wp_additional_image_sizes;
		$_wp_additional_image_sizes = $saved_sizes;

		if ( $this->size_filter_callback ) {
			remove_filter( 'intermediate_image_sizes', $this->size_filter_callback, 999 );
			$this->size_filter_callback = null;
		}
	}
}
