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
 * Registers Site Health tests hosts and owners can verify without opening Karetaker.
 *
 * @since 1.0.0
 */
class Karetaker_Site_Health {

	const STALE_SECONDS = 129600;

	/**
	 * Hooks Site Health filters.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register_test' ) );
	}

	/**
	 * Adds Karetaker direct tests.
	 *
	 * @since 1.0.0
	 * @param array<string, array<string, callable>> $tests Existing tests.
	 * @return array<string, array<string, callable>>
	 */
	public static function register_test( $tests ) {
		$tests['direct']['karetaker_scan_fresh'] = array(
			'label' => __( 'Karetaker scan schedule', 'karetaker' ),
			'test'  => array( __CLASS__, 'test_scan_fresh' ),
		);
		$tests['direct']['karetaker_guard']      = array(
			'label' => __( 'Karetaker Guard flags', 'karetaker' ),
			'test'  => array( __CLASS__, 'test_guard' ),
		);
		$tests['direct']['karetaker_host_safe']  = array(
			'label' => __( 'Karetaker host safety profile', 'karetaker' ),
			'test'  => array( __CLASS__, 'test_host_safe' ),
		);

		return $tests;
	}

	/**
	 * Overview / Site Health deep-link to Karetaker.
	 *
	 * @since 1.0.0
	 * @param string $tab Optional tab.
	 * @return string
	 */
	private static function open_link( $tab = 'overview' ) {
		return sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url(
				add_query_arg(
					array(
						'page' => 'karetaker',
						'tab'  => sanitize_key( $tab ),
					),
					admin_url( 'admin.php' )
				)
			),
			esc_html__( 'Open Karetaker', 'karetaker' )
		);
	}

	/**
	 * Site Health callback: last scan age and next cron.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public static function test_scan_fresh() {
		$state    = Karetaker_Scanner::state();
		$last_run = isset( $state['last_run'] ) ? (string) $state['last_run'] : '';
		$next     = wp_next_scheduled( Karetaker_Scanner::CRON_HOOK );
		$label    = __( 'Karetaker scan schedule', 'karetaker' );

		if ( karetaker_is_disabled() ) {
			return array(
				'label'       => $label,
				'status'      => 'critical',
				'badge'       => array(
					'label' => __( 'Security', 'karetaker' ),
					'color' => 'red',
				),
				'description' => sprintf(
					'<p>%s</p>',
					esc_html__( 'Karetaker is disabled via the kill switch (KARETAKER_DISABLE or wp-content/karetaker-disable). Scans are not running.', 'karetaker' )
				),
				'actions'     => self::open_link(),
				'test'        => 'karetaker_scan_fresh',
			);
		}

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
				'actions'     => self::open_link(),
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
		} else {
			$desc .= ' ' . esc_html__( 'Warning: the scan cron is not scheduled.', 'karetaker' );
			$stale = true;
		}

		return array(
			'label'       => $label,
			'status'      => $stale ? 'recommended' : 'good',
			'badge'       => array(
				'label' => __( 'Security', 'karetaker' ),
				'color' => $stale ? 'orange' : 'blue',
			),
			'description' => '<p>' . $desc . '</p>',
			'actions'     => self::open_link(),
			'test'        => 'karetaker_scan_fresh',
		);
	}

	/**
	 * Site Health callback: open Guard flags.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public static function test_guard() {
		$label = __( 'Karetaker Guard flags', 'karetaker' );
		$state = Karetaker_Scanner::state();
		$guard = isset( $state['guard'] ) && is_array( $state['guard'] ) ? $state['guard'] : array();
		$bad   = array();

		foreach ( Karetaker_Guard::CHECKS as $check ) {
			$entry = isset( $guard[ $check ] ) && is_array( $guard[ $check ] ) ? $guard[ $check ] : array();
			if ( ! empty( $entry['bad'] ) ) {
				$bad[] = $check;
			}
		}

		if ( $bad ) {
			return array(
				'label'       => $label,
				'status'      => 'critical',
				'badge'       => array(
					'label' => __( 'Security', 'karetaker' ),
					'color' => 'red',
				),
				'description' => sprintf(
					'<p>%s</p><p><code>%s</code></p>',
					esc_html__( 'One or more Guard checks need a human. These are one-checkbox business failures, not malware signatures.', 'karetaker' ),
					esc_html( implode( ', ', $bad ) )
				),
				'actions'     => self::open_link( 'overview' ),
				'test'        => 'karetaker_guard',
			);
		}

		return array(
			'label'       => $label,
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'karetaker' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				esc_html__( 'Guard is clear: search visibility, mail, admin email, and administrator presence look OK.', 'karetaker' )
			),
			'actions'     => self::open_link( 'overview' ),
			'test'        => 'karetaker_guard',
		);
	}

	/**
	 * Site Health callback: host-safe product profile (informational).
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public static function test_host_safe() {
		$profile = Karetaker_Status::host_profile();
		$lines   = array(
			esc_html__( 'No WAF / request firewall', 'karetaker' ),
			esc_html__( 'No login lockout by default', 'karetaker' ),
			esc_html__( 'Does not write wp-config.php, .htaccess, or server config', 'karetaker' ),
			esc_html__( 'No phone-home to Team Krikir', 'karetaker' ),
			esc_html__( 'Kill switch: KARETAKER_DISABLE or wp-content/karetaker-disable', 'karetaker' ),
		);

		$agency = ! empty( $profile['agency_status_enabled'] )
			? esc_html__( 'Agency status endpoint is enabled (token set).', 'karetaker' )
			: esc_html__( 'Agency status endpoint is off until a token is generated.', 'karetaker' );

		return array(
			'label'       => __( 'Karetaker host safety profile', 'karetaker' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'karetaker' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Karetaker is built as a watchtower that hosting providers can recommend without fighting their stack.', 'karetaker' ) . '</p><ul><li>' . implode( '</li><li>', $lines ) . '</li></ul><p>' . $agency . '</p>',
			'actions'     => self::open_link( 'settings' ),
			'test'        => 'karetaker_host_safe',
		);
	}
}
