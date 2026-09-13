<?php
/**
 * Event table schema and retention.
 *
 * @package Karetaker
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and maintains the `karetaker_events` table.
 *
 * @since 0.1.0
 */
class Karetaker_Schema {

	const SCHEMA_VERSION = 1;
	const VERSION_OPTION = 'karetaker_schema_version';

	/**
	 * Prefixed events table name.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'karetaker_events';
	}

	/**
	 * Run install on plugin activation.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function activate() {
		self::install();
	}

	/**
	 * Install or upgrade when the stored schema version lags.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) === self::SCHEMA_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Create or update the events table via dbDelta.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_time datetime NOT NULL,
			event_code varchar(64) NOT NULL DEFAULT '',
			severity tinyint(3) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip varbinary(16) DEFAULT NULL,
			context longtext,
			PRIMARY KEY  (id),
			KEY event_time (event_time),
			KEY event_code_time (event_code,event_time)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Drop the events table and schema version option.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		$table = self::table();

		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Delete rows older than the configured row cap relative to the latest id.
	 *
	 * @since 0.1.0
	 * @param int $latest_id Newest row id after insert.
	 * @return int Number of rows deleted.
	 */
	public static function trim( $latest_id ) {
		global $wpdb;

		$cap = Karetaker_Settings::row_cap();

		if ( $latest_id <= $cap ) {
			return 0;
		}

		$table  = self::table();
		$cutoff = $latest_id - $cap;

		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Count rows in the events table.
	 *
	 * @since 0.1.0
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Approximate on-disk size of the events table in bytes.
	 *
	 * @since 0.1.0
	 * @return int
	 */
	public static function size_bytes() {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT data_length + index_length AS bytes FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s',
				DB_NAME,
				self::table()
			)
		);

		return $row ? (int) $row->bytes : 0;
	}
}
