<?php
/**
 * Event table schema and retention.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Karetaker_Schema {

	const SCHEMA_VERSION = 1;
	const VERSION_OPTION = 'karetaker_schema_version';

	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'karetaker_events';
	}

	public static function activate() {
		self::install();
	}

	public static function maybe_upgrade() {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) === self::SCHEMA_VERSION ) {
			return;
		}

		self::install();
	}

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

	public static function uninstall() {
		global $wpdb;

		$table = self::table();

		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		delete_option( self::VERSION_OPTION );
	}

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

	public static function count() {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

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
