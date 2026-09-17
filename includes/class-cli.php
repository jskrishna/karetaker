<?php
/**
 * WP-CLI commands.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI command group for Karetaker.
 *
 * @since 1.0.0
 * @package Karetaker
 */
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
	 *
	 * @since 1.0.0
	 * @param array $args Positional arguments (unused).
	 * @param array $assoc Associative flags: limit, severity, code.
	 * @return void
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
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function status() {
		WP_CLI::line( wp_json_encode( Karetaker_Status::snapshot(), JSON_PRETTY_PRINT ) );
	}

	/**
	 * Run the scans now.
	 *
	 * ## OPTIONS
	 *
	 * [--budget=<seconds>]
	 * : Time budget. Default 15.
	 *
	 * @since 1.0.0
	 * @param array $args Positional arguments (unused).
	 * @param array $assoc Associative flags: budget.
	 * @return void
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
	 * Run the guided incident check (deeper multi-pass scan + checklist).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function incident() {
		$snapshot = Karetaker_Incident::run();
		WP_CLI::line( wp_json_encode( $snapshot, JSON_PRETTY_PRINT ) );
		if ( ! empty( $snapshot['needs_human'] ) ) {
			WP_CLI::warning( 'Human review recommended.' );
		} else {
			WP_CLI::success( 'Incident checklist window looks clear.' );
		}
	}

	/**
	 * Record a test event.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : The event code.
	 *
	 * @since 1.0.0
	 * @param array $args Positional arguments; [0] is the event code.
	 * @return void
	 */
	public function emit( $args ) {
		$id = Karetaker_Events::record( $args[0], array( 'source' => 'cli' ) );

		if ( ! $id ) {
			WP_CLI::error( 'Not recorded. Unknown event code?' );
		}

		WP_CLI::success( 'Recorded event ' . $id );
	}
}
