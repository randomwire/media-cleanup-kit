<?php
/**
 * Image Kit — Database abstraction for run history.
 *
 * Manages the image_kit_upgrader_runs and image_kit_upgrader_run_items tables,
 * providing CRUD operations, concurrency guards, and stale review cleanup.
 */

defined( 'ABSPATH' ) || exit;

class Image_Kit_Core_Run_Log {

	/** @var string Runs table name. */
	private $runs_table;

	/** @var string Run items table name. */
	private $items_table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->runs_table  = $wpdb->prefix . 'image_kit_upgrader_runs';
		$this->items_table = $wpdb->prefix . 'image_kit_upgrader_run_items';
	}

	/**
	 * Create or update tables via dbDelta.
	 */
	public function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$sql_runs = "CREATE TABLE {$this->runs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			started_at datetime NOT NULL,
			completed_at datetime DEFAULT NULL,
			mode varchar(20) NOT NULL DEFAULT 'scan',
			post_snapshot_time datetime DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'running',
			post_types varchar(255) NOT NULL DEFAULT '',
			posts_scanned int(11) NOT NULL DEFAULT 0,
			images_replaced int(11) NOT NULL DEFAULT 0,
			images_skipped int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY status (status)
		) $charset_collate;";

		$sql_items = "CREATE TABLE {$this->items_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			post_title varchar(255) NOT NULL DEFAULT '',
			images_replaced int(11) NOT NULL DEFAULT 0,
			images_skipped int(11) NOT NULL DEFAULT 0,
			replacements longtext,
			applied_at datetime DEFAULT NULL,
			error_message text DEFAULT NULL,
			PRIMARY KEY (id),
			KEY run_id (run_id),
			KEY post_id (post_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_runs );
		dbDelta( $sql_items );

		$this->migrate_drop_history_columns();
	}

	/**
	 * One-time migration: drop the unused `posts_updated` and `error_count`
	 * columns from `wp_image_kit_upgrader_runs`. They existed only to feed a
	 * never-built History UI and bloated every update_run() call site.
	 * dbDelta won't drop columns on its own; do it explicitly, idempotently.
	 */
	private function migrate_drop_history_columns() {
		global $wpdb;
		$schema_version = (int) get_option( 'image_kit_run_log_schema_version', 0 );
		if ( $schema_version >= 2 ) {
			return;
		}
		// Table name is a trusted constant built from `$wpdb->prefix` in the
		// constructor; no user input. SHOW COLUMNS / ALTER TABLE can't be
		// prepared() (DDL).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$this->runs_table}", 0 );
		if ( in_array( 'posts_updated', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$this->runs_table} DROP COLUMN posts_updated" );
		}
		if ( in_array( 'error_count', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$this->runs_table} DROP COLUMN error_count" );
		}
		// phpcs:enable
		update_option( 'image_kit_run_log_schema_version', 2, true );
	}

	/**
	 * Create a new run record.
	 *
	 * @param array $args Run data (post_types, mode, status).
	 * @return int|false Run ID on success, false on failure.
	 */
	public function create_run( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'started_at'  => current_time( 'mysql', true ),
			'mode'        => 'scan',
			'status'      => 'running',
			'post_types'  => '',
		);
		$data = wp_parse_args( $args, $defaults );

		$result = $wpdb->insert( $this->runs_table, $data );
		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update a run record.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $data   Columns to update.
	 * @return bool
	 */
	public function update_run( $run_id, $data ) {
		global $wpdb;

		return false !== $wpdb->update(
			$this->runs_table,
			$data,
			array( 'id' => absint( $run_id ) )
		);
	}

	/**
	 * Get a single run by ID.
	 *
	 * @param int $run_id Run ID.
	 * @return object|null
	 */
	public function get_run( $run_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->runs_table} WHERE id = %d", absint( $run_id ) )
		);
	}

	/**
	 * Get paginated list of runs, most recent first.
	 *
	 * @param int $page     Page number (1-based).
	 * @param int $per_page Items per page.
	 * @return array { 'runs' => array, 'total' => int }
	 */
	public function get_runs( $page = 1, $per_page = 20 ) {
		global $wpdb;

		$offset = ( absint( $page ) - 1 ) * absint( $per_page );

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->runs_table}" );

		$runs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->runs_table} ORDER BY id DESC LIMIT %d OFFSET %d",
				absint( $per_page ),
				$offset
			)
		);

		return array(
			'runs'  => $runs,
			'total' => $total,
		);
	}

	/**
	 * Insert a run item (one processed post).
	 *
	 * @param array $data Item data.
	 * @return int|false Item ID on success, false on failure.
	 */
	public function insert_item( $data ) {
		global $wpdb;

		if ( isset( $data['replacements'] ) && is_array( $data['replacements'] ) ) {
			$data['replacements'] = wp_json_encode( $data['replacements'] );
		}

		$result = $wpdb->insert( $this->items_table, $data );
		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get a single run item by ID.
	 *
	 * @param int $item_id Item ID.
	 * @return object|null
	 */
	public function get_item( $item_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->items_table} WHERE id = %d", absint( $item_id ) )
		);
	}

	/**
	 * Get all items for a given run.
	 *
	 * @param int $run_id Run ID.
	 * @return array
	 */
	public function get_items_for_run( $run_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->items_table} WHERE run_id = %d ORDER BY id ASC", absint( $run_id ) )
		);
	}

	/**
	 * Get a paginated slice of items for a run.
	 *
	 * @param int $run_id Run ID.
	 * @param int $offset Offset.
	 * @param int $limit  Limit.
	 * @return array { 'items' => array, 'total' => int }
	 */
	public function get_actionable_items_paginated( $run_id, $offset, $limit ) {
		global $wpdb;

		$run_id = absint( $run_id );

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->items_table} WHERE run_id = %d", $run_id )
		);

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->items_table} WHERE run_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$run_id,
				absint( $limit ),
				absint( $offset )
			)
		);

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Mark a run item as applied.
	 *
	 * @param int $item_id Run item ID.
	 * @return bool
	 */
	public function update_item_applied( $item_id ) {
		global $wpdb;
		return false !== $wpdb->update(
			$this->items_table,
			array( 'applied_at' => current_time( 'mysql', true ) ),
			array( 'id' => absint( $item_id ) )
		);
	}

	/**
	 * Update exclusion flags on specific replacements within a run item.
	 *
	 * @param int   $item_id          Run item ID.
	 * @param array $excluded_indices Array of 0-based indices to mark as excluded.
	 * @return bool
	 */
	public function update_item_exclusions( $item_id, $excluded_indices ) {
		global $wpdb;

		$item = $wpdb->get_row(
			$wpdb->prepare( "SELECT replacements FROM {$this->items_table} WHERE id = %d", absint( $item_id ) )
		);

		if ( ! $item || empty( $item->replacements ) ) {
			return false;
		}

		$replacements = json_decode( $item->replacements, true );
		if ( ! is_array( $replacements ) ) {
			return false;
		}

		foreach ( $replacements as $i => &$replacement ) {
			$replacement['excluded'] = in_array( $i, $excluded_indices, true );
		}
		unset( $replacement );

		return false !== $wpdb->update(
			$this->items_table,
			array( 'replacements' => wp_json_encode( $replacements ) ),
			array( 'id' => absint( $item_id ) )
		);
	}

	/**
	 * Update the replacements JSON for a run item.
	 *
	 * @param int   $item_id      Run item ID.
	 * @param array $replacements Replacement data array.
	 * @return bool
	 */
	public function update_item_replacements( $item_id, $replacements, $counts = array() ) {
		global $wpdb;
		$data = array( 'replacements' => wp_json_encode( $replacements ) );
		if ( isset( $counts['images_replaced'] ) ) {
			$data['images_replaced'] = (int) $counts['images_replaced'];
		}
		if ( isset( $counts['images_skipped'] ) ) {
			$data['images_skipped'] = (int) $counts['images_skipped'];
		}
		return false !== $wpdb->update(
			$this->items_table,
			$data,
			array( 'id' => absint( $item_id ) )
		);
	}

	/**
	 * Discard pending reviews older than 24 hours.
	 *
	 * @return int Number of runs discarded.
	 */
	public function discard_stale_reviews() {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->runs_table} SET status = 'discarded', completed_at = %s WHERE status IN ('pending_review', 'pending_resolution') AND started_at < %s",
				current_time( 'mysql', true ),
				$cutoff
			)
		);
	}

	/**
	 * Check if there is an active run started within the last 2 hours.
	 *
	 * @return object|null The active run row, or null if none.
	 */
	public function get_active_run() {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 2 * HOUR_IN_SECONDS ) );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->runs_table} WHERE status IN ('running', 'applying') AND started_at > %s ORDER BY id DESC LIMIT 1",
				$cutoff
			)
		);
	}

	/**
	 * Mark any 'running' / 'applying' run older than $max_age_seconds as
	 * 'failed'. Self-heal for abandoned scans (closed browser, JS error
	 * before the cancel AJAX could fire).
	 *
	 * @param int            $max_age_seconds Grace period before a run is considered stale. Default 5 minutes.
	 * @param string|string[] $modes          Optional mode filter — only clear runs whose `mode` is in this list.
	 *                                        Pass an empty array (default) to clear regardless of mode.
	 * @return int Number of runs cleared.
	 */
	public function clear_stale_active_runs( int $max_age_seconds = 300, $modes = array() ) {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $max_age_seconds );

		if ( ! empty( $modes ) ) {
			$modes        = array_map( 'sanitize_key', (array) $modes );
			$placeholders = implode( ',', array_fill( 0, count( $modes ), '%s' ) );
			$sql          = "UPDATE {$this->runs_table} SET status = 'failed', completed_at = %s WHERE status IN ('running', 'applying') AND mode IN ($placeholders) AND started_at < %s";
			$params       = array_merge( array( current_time( 'mysql', true ) ), $modes, array( $cutoff ) );
			return (int) $wpdb->query( $wpdb->prepare( $sql, $params ) );
		}

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->runs_table} SET status = 'failed', completed_at = %s WHERE status IN ('running', 'applying') AND started_at < %s",
				current_time( 'mysql', true ),
				$cutoff
			)
		);
	}

	/**
	 * Get the most recent pending review (for auto-restore on page load).
	 *
	 * @return object|null
	 */
	public function get_pending_review( $modes = array() ) {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		if ( ! empty( $modes ) ) {
			$modes        = array_map( 'sanitize_key', (array) $modes );
			$placeholders = implode( ',', array_fill( 0, count( $modes ), '%s' ) );
			$sql          = "SELECT * FROM {$this->runs_table} WHERE status IN ('pending_review', 'pending_resolution') AND mode IN ($placeholders) AND started_at > %s ORDER BY id DESC LIMIT 1";
			$params       = array_merge( $modes, array( $cutoff ) );
			return $wpdb->get_row( $wpdb->prepare( $sql, $params ) );
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->runs_table} WHERE status IN ('pending_review', 'pending_resolution') AND started_at > %s ORDER BY id DESC LIMIT 1",
				$cutoff
			)
		);
	}

	/**
	 * Get the runs table name.
	 *
	 * @return string
	 */
	public function get_runs_table() {
		return $this->runs_table;
	}

	/**
	 * Get the items table name.
	 *
	 * @return string
	 */
	public function get_items_table() {
		return $this->items_table;
	}
}
