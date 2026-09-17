<?php
/**
 * Shared status snapshot builder.
 *
 * @package Karetaker
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the fact payload shared by CLI and the agency REST route.
 *
 * @since 1.0.0
 */
class Karetaker_Status {

	/**
	 * Build the fact payload shared by CLI and the agency REST route (without sig).
	 *
	 * @since 1.0.0
	 * @return array<string,mixed>
	 */
	public static function snapshot() {
		$state     = Karetaker_Scanner::state();
		$guard     = isset( $state['guard'] ) && is_array( $state['guard'] ) ? $state['guard'] : array();
		$guard_out = array();

		foreach ( $guard as $check => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$guard_out[ sanitize_key( $check ) ] = array(
				'bad'   => ! empty( $entry['bad'] ),
				'since' => ! empty( $entry['since'] ) ? (string) $entry['since'] : null,
			);
		}

		$since = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
		$act   = Karetaker_Events::query(
			array(
				'min_severity' => Karetaker_Events::SEVERITY_ACT,
				'since'        => $since,
				'limit'        => 500,
			)
		);

		return array(
			'version'       => KARETAKER_VERSION,
			'site_url'      => home_url( '/' ),
			'disabled'      => karetaker_is_disabled(),
			'generated_at'  => gmdate( 'c' ),
			'events'        => array(
				'count'       => Karetaker_Schema::count(),
				'row_cap'     => Karetaker_Settings::row_cap(),
				'table_bytes' => Karetaker_Schema::size_bytes(),
			),
			'last_scan'     => array(
				'at'      => isset( $state['last_run'] ) ? (string) $state['last_run'] : null,
				'results' => isset( $state['last_results'] ) && is_array( $state['last_results'] ) ? $state['last_results'] : array(),
			),
			'guard'         => $guard_out,
			'act_this_week' => count( $act ),
			'harden'        => array(
				'desired' => Karetaker_Settings::harden(),
			),
			'host_profile'  => self::host_profile(),
		);
	}

	/**
	 * Stable facts hosts can rely on when evaluating or bundling Karetaker.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public static function host_profile() {
		return array(
			'product'               => 'watchtower',
			'waf'                   => false,
			'login_lockout_default' => false,
			'writes_server_config'  => false,
			'phone_home'            => false,
			'agency_status_enabled' => (bool) Karetaker_Access::tokens(),
			'status_route'          => 'karetaker/v1/status',
			'cli'                   => 'wp karetaker status',
			'kill_switch_constant'  => 'KARETAKER_DISABLE',
			'kill_switch_file'      => 'wp-content/karetaker-disable',
		);
	}
}
