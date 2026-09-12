<?php
/**
 * WP-CLI commands.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Karetaker_CLI {

	/**
	 * Show the event log.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : How many rows. Default 20.
	 *
	 * [--severity=<number>]
	 * : Minimum severity, 0 to 2.
	 *
	 * [--code=<code>]
	 * : Only this event code.
	 */
	public function log( $args, $assoc ) {
		$rows = Karetaker_Events::query(
			array(
				'limit'        => isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 20,
				'min_severity' => isset( $assoc['severity'] ) ? (int) $assoc['severity'] : null,
				'code'         => isset( $assoc['code'] ) ? $assoc['code'] : '',
			)
		);

		if ( ! $rows ) {
			WP_CLI::success( 'No events.' );

			return;
		}

		$out = array();

		foreach ( $rows as $row ) {
			$out[] = array(
				'time'     => $row->event_time,
				'severity' => $row->severity,
				'code'     => $row->event_code,
				'user'     => $row->user_id,
				'ip'       => $row->ip_display,
				'context'  => wp_json_encode( $row->context ),
			);
		}

		WP_CLI\Utils\format_items( 'table', $out, array( 'time', 'severity', 'code', 'user', 'ip', 'context' ) );
	}

	/**
	 * Show plugin status as JSON.
	 */
	public function status() {
		WP_CLI::line(
			wp_json_encode(
				array(
					'version'    => KARETAKER_VERSION,
					'disabled'   => karetaker_is_disabled(),
					'events'     => Karetaker_Schema::count(),
					'row_cap'    => Karetaker_Settings::row_cap(),
					'table_size' => Karetaker_Schema::size_bytes(),
					'last_scan'  => get_option( 'karetaker_last_scan', null ),
				),
				JSON_PRETTY_PRINT
			)
		);
	}

	/**
	 * Run the scans now.
	 *
	 * ## OPTIONS
	 *
	 * [--budget=<seconds>]
	 * : Time budget. Default 15.
	 */
	public function scan( $args, $assoc ) {
		$budget  = isset( $assoc['budget'] ) ? (int) $assoc['budget'] : null;
		$results = Karetaker_Scanner::run( $budget );

		foreach ( $results as $scan => $result ) {
			WP_CLI::line( sprintf( '  %-12s %s', $scan, $result ) );
		}

		WP_CLI::success( 'Scan complete.' );
	}

	/**
	 * Record a test event.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : The event code.
	 */
	public function emit( $args ) {
		$id = Karetaker_Events::record( $args[0], array( 'source' => 'cli' ) );

		if ( ! $id ) {
			WP_CLI::error( 'Not recorded. Unknown event code?' );
		}

		WP_CLI::success( 'Recorded event ' . $id );
	}
}
