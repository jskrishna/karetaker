<?php
/**
 * Site Health integration.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a Site Health test for scheduled scans.
 *
 * @since 0.1.0
 */
class Karetaker_Site_Health {

	const STALE_SECONDS = 129600;

	/**
	 * Hooks Site Health filters.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register_test' ) );
	}

	/**
	 * Adds the Karetaker scan freshness test.
	 *
	 * @since 0.1.0
	 * @param array<string, array<string, callable>> $tests Existing tests.
	 * @return array<string, array<string, callable>>
	 */
	public static function register_test( $tests ) {
		$tests['direct']['karetaker_scan_fresh'] = array(
			'label' => __( 'Karetaker scan schedule', 'karetaker' ),
			'test'  => array( __CLASS__, 'test_scan_fresh' ),
		);

		return $tests;
	}

	/**
	 * Site Health callback: last scan age and next cron.
	 *
	 * @since 0.1.0
	 * @return array<string, mixed>
	 */
	public static function test_scan_fresh() {
		$state    = Karetaker_Scanner::state();
		$last_run = isset( $state['last_run'] ) ? (string) $state['last_run'] : '';
		$next     = wp_next_scheduled( Karetaker_Scanner::CRON_HOOK );
		$label    = __( 'Karetaker scan schedule', 'karetaker' );

		if ( '' === $last_run ) {
			return array(
				'label'       => $label,
				'status'      => 'recommended',
				'badge'       => array(
					'label' => __( 'Security', 'karetaker' ),
					'color' => 'orange',
				),
				'description' => sprintf(
					'<p>%s</p>',
					esc_html__( 'No scan has completed yet. Open Karetaker → Overview and run a scan, or wait for the twice-daily schedule.', 'karetaker' )
				),
				'actions'     => sprintf(
					'<p><a href="%s">%s</a></p>',
					esc_url(
						add_query_arg(
							array(
								'page' => 'karetaker',
								'tab'  => 'overview',
							),
							admin_url( 'admin.php' )
						)
					),
					esc_html__( 'Open Karetaker', 'karetaker' )
				),
				'test'        => 'karetaker_scan_fresh',
			);
		}

		$last_ts = strtotime( $last_run . ' UTC' );
		$age     = $last_ts ? ( time() - $last_ts ) : self::STALE_SECONDS + 1;
		$stale   = $age > self::STALE_SECONDS;

		$desc = sprintf(
			/* translators: 1: human time diff, 2: UTC datetime */
			esc_html__( 'Last scan ran %1$s ago (%2$s UTC).', 'karetaker' ),
			human_time_diff( $last_ts, time() ),
			$last_run
		);

		if ( $next ) {
			$desc .= ' ' . sprintf(
				/* translators: %s: localised datetime */
				esc_html__( 'Next scheduled scan: %s.', 'karetaker' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next )
			);
		}

		return array(
			'label'       => $label,
			'status'      => $stale ? 'recommended' : 'good',
			'badge'       => array(
				'label' => __( 'Security', 'karetaker' ),
				'color' => $stale ? 'orange' : 'blue',
			),
			'description' => '<p>' . $desc . '</p>',
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url(
					add_query_arg(
						array(
							'page' => 'karetaker',
							'tab'  => 'overview',
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html__( 'Open Karetaker', 'karetaker' )
			),
			'test'        => 'karetaker_scan_fresh',
		);
	}
}
