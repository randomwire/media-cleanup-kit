<?php
/**
 * Image Kit — Unused Cleaner scanner.
 *
 * Scans directories for image files, checks 6 WordPress reference sources,
 * groups thumbnail variants, and handles safe deletion.
 */

defined( 'ABSPATH' ) || exit;

class Image_Kit_Unused_Cleaner_Scanner {

	const IMAGE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tiff', 'tif' );
	const BATCH_SIZE       = 100;
	const SQL_BATCH_SIZE   = 50;
	const TRANSIENT_EXPIRY = 3600;

	public function get_all_files( string $directory ): array {
		return $this->build_file_list( $directory );
	}

	private function build_file_list( string $directory ): array {
		$transient_key = $this->get_transient_key( $directory );
		$cached        = get_transient( $transient_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$entries = scandir( $directory );
		if ( false === $entries ) {
			return array();
		}

		$files = array();
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( ! is_file( $directory . '/' . $entry ) ) {
				continue;
			}
			$ext = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, self::IMAGE_EXTENSIONS, true ) ) {
				$files[] = $entry;
			}
		}

		sort( $files, SORT_STRING | SORT_FLAG_CASE );
		set_transient( $transient_key, $files, self::TRANSIENT_EXPIRY );

		return $files;
	}

	public function list_images( string $directory, int $offset = 0, int $batch_size = self::BATCH_SIZE ): array {
		$files = $this->build_file_list( $directory );
		$total = count( $files );
		$batch = array_slice( $files, $offset, $batch_size );
		$done  = ( $offset + $batch_size ) >= $total;

		return array(
			'files'  => $batch,
			'total'  => $total,
			'offset' => $offset,
			'done'   => $done,
		);
	}

	/**
	 * Group filenames by original, detecting WordPress thumbnail variants.
	 */
	public function group_thumbnails( array $filenames, array $all_files_map = array() ): array {
		$groups    = array();
		$thumb_map = array();

		foreach ( $filenames as $filename ) {
			$original = null;

			if ( preg_match( '/^(.+)-(\d+)x(\d+)(\.[a-zA-Z]+)$/', $filename, $matches ) ) {
				$original = $matches[1] . $matches[4];
			} elseif ( preg_match( '/^(.+)-(scaled|rotated)(\.[a-zA-Z]+)$/', $filename, $matches ) ) {
				$original = $matches[1] . $matches[3];
			} elseif ( preg_match( '/^(.+)-(e\d{10,13})(\.[a-zA-Z]+)$/', $filename, $matches ) ) {
				$original = $matches[1] . $matches[3];
			}

			if ( null !== $original ) {
				$thumb_map[ $filename ] = $original;
			}
		}

		foreach ( $filenames as $filename ) {
			if ( isset( $thumb_map[ $filename ] ) ) {
				$original = $thumb_map[ $filename ];
				if ( ! isset( $groups[ $original ] ) ) {
					$groups[ $original ] = array();
				}
				$groups[ $original ][] = $filename;
			} else {
				if ( ! isset( $groups[ $filename ] ) ) {
					$groups[ $filename ] = array();
				}
				array_unshift( $groups[ $filename ], $filename );
			}
		}

		foreach ( $groups as $original => &$members ) {
			if ( ! in_array( $original, $members, true ) ) {
				if ( isset( $all_files_map[ $original ] ) ) {
					array_unshift( $members, $original );
				}
			}
			$members = array_unique( $members );
		}

		return $groups;
	}

	/**
	 * Check usage for a batch of image groups across all WordPress locations.
	 *
	 * 6 sources: post content, media library, featured images, Gutenberg blocks,
	 * custom meta, widgets.
	 */
	public function check_usage_batch( array $image_groups, string $directory ): array {
		$all_filenames = array();
		foreach ( $image_groups as $members ) {
			foreach ( $members as $filename ) {
				$all_filenames[] = $filename;
			}
		}
		$all_filenames = array_unique( $all_filenames );

		$post_content_hits  = array();
		$media_library_hits = array( 'by_file' => array(), 'by_id' => array() );
		$post_meta_hits     = array();
		$widget_hits        = array();

		$chunks = array_chunk( $all_filenames, self::SQL_BATCH_SIZE );
		foreach ( $chunks as $chunk ) {
			$post_content_hits = array_merge( $post_content_hits, $this->check_post_content( $chunk ) );
			$post_meta_hits    = array_merge( $post_meta_hits, $this->check_post_meta( $chunk ) );
			$widget_hits       = array_merge( $widget_hits, $this->check_widget_content( $chunk ) );

			$ml = $this->check_media_library( $chunk, $directory );
			$media_library_hits['by_file'] = array_merge( $media_library_hits['by_file'], $ml['by_file'] );
			$media_library_hits['by_id']   = array_merge( $media_library_hits['by_id'], $ml['by_id'] );
		}

		$attachment_ids = array_keys( $media_library_hits['by_id'] );
		$featured_hits  = array();
		$block_ref_hits = array();

		if ( ! empty( $attachment_ids ) ) {
			$id_chunks = array_chunk( $attachment_ids, self::SQL_BATCH_SIZE );
			foreach ( $id_chunks as $id_chunk ) {
				$featured_hits  = array_merge( $featured_hits, $this->check_featured_images( $id_chunk ) );
				$block_ref_hits = array_merge( $block_ref_hits, $this->check_block_references( $id_chunk ) );
			}
		}

		$results = array();
		foreach ( $image_groups as $original => $members ) {
			$used_in                 = array();
			$is_used                 = false;
			$attachment_ids_for_group = array();

			foreach ( $members as $filename ) {
				if ( ! empty( $post_content_hits[ $filename ] ) ) {
					$is_used = true;
					foreach ( $post_content_hits[ $filename ] as $post_info ) {
						$used_in[] = sprintf( 'Post: %s (#%d)', $post_info['title'], $post_info['id'] );
					}
				}

				if ( ! empty( $media_library_hits['by_file'][ $filename ] ) ) {
					$att_id = $media_library_hits['by_file'][ $filename ];
					$attachment_ids_for_group[] = $att_id;

					if ( in_array( $att_id, $featured_hits, true ) ) {
						$is_used   = true;
						$used_in[] = 'Featured Image';
					}

					if ( ! empty( $block_ref_hits[ $att_id ] ) ) {
						$is_used = true;
						foreach ( $block_ref_hits[ $att_id ] as $post_info ) {
							$used_in[] = sprintf( 'Block: %s (#%d)', $post_info['title'], $post_info['id'] );
						}
					}
				}

				if ( ! empty( $post_meta_hits[ $filename ] ) ) {
					$is_used = true;
					foreach ( $post_meta_hits[ $filename ] as $meta_info ) {
						$used_in[] = sprintf( 'Custom Field: %s (#%d)', $meta_info['key'], $meta_info['post_id'] );
					}
				}

				if ( ! empty( $widget_hits[ $filename ] ) ) {
					$is_used   = true;
					$used_in[] = 'Widget';
				}
			}

			$used_in = array_unique( $used_in );

			$display_file = in_array( $original, $members, true ) ? $original : $members[0];
			$file_path    = $directory . '/' . $display_file;
			$file_size    = file_exists( $file_path ) ? filesize( $file_path ) : 0;

			$results[] = array(
				'filename'       => $original,
				'file_size'      => $file_size,
				'is_used'        => $is_used,
				'used_in'        => $used_in,
				'group'          => $members,
				'attachment_ids' => array_unique( $attachment_ids_for_group ),
			);
		}

		return $results;
	}

	public function delete_files( array $files, string $directory ): array {
		$real_dir = realpath( $directory );
		if ( false === $real_dir ) {
			return array();
		}

		$results = array();
		foreach ( $files as $filename ) {
			$file_path = $real_dir . '/' . $filename;

			if ( ! file_exists( $file_path ) ) {
				$results[] = array( 'filename' => $filename, 'success' => true, 'error' => '' );
				continue;
			}

			$real_file = realpath( $file_path );
			if ( false === $real_file || 0 !== strpos( $real_file, $real_dir . '/' ) ) {
				$results[] = array( 'filename' => $filename, 'success' => false, 'error' => 'Invalid file path.' );
				continue;
			}

			if ( ! is_writable( dirname( $real_file ) ) ) {
				$results[] = array( 'filename' => $filename, 'success' => false, 'error' => 'Directory is not writable.' );
				continue;
			}

			$deleted = unlink( $real_file );
			clearstatcache( true, $real_file );

			$results[] = array(
				'filename' => $filename,
				'success'  => $deleted && ! file_exists( $real_file ),
				'error'    => $deleted ? '' : 'Could not delete file.',
			);
		}

		return $results;
	}

	public function clear_cache( string $directory ): void {
		delete_transient( $this->get_transient_key( $directory ) );
	}

	private function get_transient_key( string $directory ): string {
		return 'image_kit_uc_files_' . md5( $directory );
	}

	// ── Usage check methods (6 sources) ──

	private function check_post_content( array $filenames ): array {
		global $wpdb;
		if ( empty( $filenames ) ) {
			return array();
		}

		$like_clauses = array();
		$values       = array();
		foreach ( $filenames as $filename ) {
			$like_clauses[] = 'post_content LIKE %s';
			$values[]       = '%' . $wpdb->esc_like( $filename ) . '%';
		}

		$where = implode( ' OR ', $like_clauses );
		$query = $wpdb->prepare(
			"SELECT ID, post_title, post_status, post_content FROM {$wpdb->posts}
			WHERE post_type NOT IN ('revision', 'attachment') AND ({$where})",
			$values
		);

		$posts = $wpdb->get_results( $query );
		$hits  = array();

		foreach ( $posts as $post ) {
			foreach ( $filenames as $filename ) {
				if ( false !== strpos( $post->post_content, $filename ) ) {
					if ( ! isset( $hits[ $filename ] ) ) {
						$hits[ $filename ] = array();
					}
					$label = $post->post_title;
					if ( 'publish' !== $post->post_status ) {
						$label .= ' [' . $post->post_status . ']';
					}
					$hits[ $filename ][] = array( 'id' => (int) $post->ID, 'title' => $label );
				}
			}
		}

		return $hits;
	}

	private function check_media_library( array $filenames, string $directory ): array {
		global $wpdb;
		if ( empty( $filenames ) ) {
			return array( 'by_file' => array(), 'by_id' => array() );
		}

		$like_clauses = array();
		$values       = array();
		foreach ( $filenames as $filename ) {
			$like_clauses[] = 'pm.meta_value LIKE %s';
			$values[]       = '%' . $wpdb->esc_like( $filename ) . '%';
		}

		$where = implode( ' OR ', $like_clauses );
		$query = $wpdb->prepare(
			"SELECT p.ID, pm.meta_value AS attached_file
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
			WHERE p.post_type = 'attachment' AND ({$where})",
			$values
		);

		$attachments = $wpdb->get_results( $query );
		$by_file     = array();
		$by_id       = array();

		foreach ( $attachments as $att ) {
			foreach ( $filenames as $filename ) {
				if ( false !== strpos( $att->attached_file, $filename ) ) {
					$by_file[ $filename ]     = (int) $att->ID;
					$by_id[ (int) $att->ID ] = $filename;
				}
			}
		}

		return array( 'by_file' => $by_file, 'by_id' => $by_id );
	}

	private function check_featured_images( array $attachment_ids ): array {
		global $wpdb;
		if ( empty( $attachment_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
		$query        = $wpdb->prepare(
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
			WHERE meta_key = '_thumbnail_id' AND meta_value IN ({$placeholders})",
			$attachment_ids
		);

		return array_map( 'intval', $wpdb->get_col( $query ) );
	}

	private function check_block_references( array $attachment_ids ): array {
		global $wpdb;
		if ( empty( $attachment_ids ) ) {
			return array();
		}

		$like_clauses = array();
		$values       = array();
		foreach ( $attachment_ids as $att_id ) {
			$like_clauses[] = 'post_content LIKE %s';
			$values[]       = '%' . $wpdb->esc_like( 'wp-image-' . $att_id ) . '%';
			$like_clauses[] = 'post_content LIKE %s';
			$values[]       = '%' . $wpdb->esc_like( '"id":' . $att_id ) . '%';
		}

		$where = implode( ' OR ', $like_clauses );
		$query = $wpdb->prepare(
			"SELECT ID, post_title, post_status, post_content FROM {$wpdb->posts}
			WHERE post_type NOT IN ('revision', 'attachment') AND ({$where})",
			$values
		);

		$posts = $wpdb->get_results( $query );
		$hits  = array();

		foreach ( $posts as $post ) {
			foreach ( $attachment_ids as $att_id ) {
				$patterns = array( 'wp-image-' . $att_id, '"id":' . $att_id );
				foreach ( $patterns as $pattern ) {
					if ( false !== strpos( $post->post_content, $pattern ) ) {
						if ( ! isset( $hits[ $att_id ] ) ) {
							$hits[ $att_id ] = array();
						}
						$label = $post->post_title;
						if ( 'publish' !== $post->post_status ) {
							$label .= ' [' . $post->post_status . ']';
						}
						$hits[ $att_id ][] = array( 'id' => (int) $post->ID, 'title' => $label );
						break;
					}
				}
			}
		}

		return $hits;
	}

	private function check_post_meta( array $filenames ): array {
		global $wpdb;
		if ( empty( $filenames ) ) {
			return array();
		}

		$like_clauses = array();
		$values       = array();
		foreach ( $filenames as $filename ) {
			$like_clauses[] = 'meta_value LIKE %s';
			$values[]       = '%' . $wpdb->esc_like( $filename ) . '%';
		}

		$where = implode( ' OR ', $like_clauses );
		$query = $wpdb->prepare(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
			WHERE meta_key NOT IN ('_wp_attached_file', '_wp_attachment_metadata', '_thumbnail_id', '_edit_lock', '_edit_last')
			AND ({$where})",
			$values
		);

		$metas = $wpdb->get_results( $query );
		$hits  = array();

		foreach ( $metas as $meta ) {
			foreach ( $filenames as $filename ) {
				if ( false !== strpos( $meta->meta_value, $filename ) ) {
					if ( ! isset( $hits[ $filename ] ) ) {
						$hits[ $filename ] = array();
					}
					$hits[ $filename ][] = array( 'post_id' => (int) $meta->post_id, 'key' => $meta->meta_key );
				}
			}
		}

		return $hits;
	}

	private function check_widget_content( array $filenames ): array {
		global $wpdb;
		if ( empty( $filenames ) ) {
			return array();
		}

		$widgets = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'widget_' ) . '%'
			)
		);

		$hits = array();
		foreach ( $widgets as $widget ) {
			foreach ( $filenames as $filename ) {
				if ( false !== strpos( $widget->option_value, $filename ) ) {
					$hits[ $filename ] = true;
				}
			}
		}

		return $hits;
	}
}
