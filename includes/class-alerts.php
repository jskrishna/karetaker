<?php
/**
 * Scarce ACT-now email alerts.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scarce ACT-severity email alerts with daily dedupe.
 *
 * @since 0.1.0
 * @package Karetaker
 */
class Karetaker_Alerts {

	const DEDUPE_TTL = DAY_IN_SECONDS;

	/**
	 * Listens for recorded events and sends ACT emails when enabled.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function init() {
		add_action( 'karetaker_event_recorded', array( __CLASS__, 'maybe_send' ), 10, 4 );
	}

	/**
	 * Sends a deduped ACT alert email for a newly recorded event.
	 *
	 * @since 0.1.0
	 * @param int    $id Event row ID.
	 * @param string $code Event code.
	 * @param int    $severity Severity constant.
	 * @param array  $context Event context.
	 * @return bool
	 */
	public static function maybe_send( $id, $code, $severity, $context ) {
		if ( karetaker_is_disabled() ) {
			return false;
		}

		if ( Karetaker_Events::SEVERITY_ACT !== (int) $severity ) {
			return false;
		}

		if ( ! Karetaker_Settings::get( 'alerts_enabled' ) ) {
			return false;
		}

		$code    = sanitize_key( $code );
		$context = is_array( $context ) ? $context : array();
		$key     = self::dedupe_key( $code, $context );

		if ( get_transient( $key ) ) {
			return false;
		}

		$to = Karetaker_Settings::alert_email();
		if ( ! is_email( $to ) ) {
			return false;
		}

		$subject = self::subject_for( $code, $context );
		$body    = self::body_for( $code, $context, (int) $id );

		$sent = wp_mail( $to, $subject, $body );

		if ( $sent ) {
			set_transient( $key, 1, self::DEDUPE_TTL );
		}

		return (bool) $sent;
	}

	/**
	 * Builds a transient key for alert deduplication.
	 *
	 * @since 0.1.0
	 * @param string $code Event code.
	 * @param array  $context Event context.
	 * @return string
	 */
	public static function dedupe_key( $code, array $context ) {
		ksort( $context );
		return 'karetaker_alert_' . md5( $code . '|' . wp_json_encode( $context ) );
	}

	/**
	 * Human subject line for an event code.
	 *
	 * @since 0.1.0
	 * @param string $code Event code.
	 * @param array  $context Event context.
	 * @return string
	 */
	public static function subject_for( $code, array $context ) {
		$check = isset( $context['check'] ) ? (string) $context['check'] : '';

		$map = array(
			'admin_user_added'    => __( 'A new administrator account appeared', 'karetaker' ),
			'role_escalated'      => __( 'A user was made administrator', 'karetaker' ),
			'registration_opened' => __( 'Anyone can register on your site', 'karetaker' ),
			'muplugin_changed'    => __( 'A must-use plugin file changed', 'karetaker' ),
			'uploads_php_found'   => __( 'Executable PHP appeared under uploads', 'karetaker' ),
			'file_hash_mismatch'  => __( 'A plugin or theme no longer matches wordpress.org', 'karetaker' ),
			'guard_tripped'       => self::guard_subject( $check ),
		);

		$verdict = isset( $map[ $code ] ) ? $map[ $code ] : __( 'Something needs your attention', 'karetaker' );

		return '[Karetaker] ' . $verdict;
	}

	/**
	 * Subject fragment for a Guard check key.
	 *
	 * @since 0.1.0
	 * @param string $check Guard check key.
	 * @return string
	 */
	private static function guard_subject( $check ) {
		$map = array(
			'blog_public'         => __( 'Search engines were discouraged', 'karetaker' ),
			'mail_failed'         => __( 'WordPress could not send email', 'karetaker' ),
			'admin_email_invalid' => __( 'The admin email address is invalid', 'karetaker' ),
			'no_administrator'    => __( 'No administrator remains on the site', 'karetaker' ),
		);
		return isset( $map[ $check ] ) ? $map[ $check ] : __( 'A site setting needs attention', 'karetaker' );
	}

	/**
	 * Plain-text email body for an ACT alert.
	 *
	 * @since 0.1.0
	 * @param string $code Event code.
	 * @param array  $context Event context.
	 * @param int    $id Event row ID.
	 * @return string
	 */
	public static function body_for( $code, array $context, $id ) {
		$home     = home_url( '/' );
		$tools    = admin_url( 'admin.php?page=karetaker&tab=activity' );
		$guidance = Karetaker_Guidance::for_event( $code, $context );

		$lines   = array();
		$lines[] = self::subject_for( $code, $context );
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: site home URL */
			__( 'Site: %s', 'karetaker' ),
			$home
		);
		$lines[] = sprintf(
			/* translators: 1: event code, 2: event ID */
			__( 'Event: %1$s (#%2$d)', 'karetaker' ),
			$code,
			(int) $id
		);
		if ( ! empty( $guidance['summary'] ) ) {
			$lines[] = '';
			$lines[] = $guidance['summary'];
		}
		if ( ! empty( $guidance['steps'] ) ) {
			$lines[] = '';
			$lines[] = __( 'What you should do:', 'karetaker' );
			$step_n  = 1;
			foreach ( $guidance['steps'] as $step ) {
				$lines[] = $step_n . '. ' . $step;
				++$step_n;
			}
		}
		if ( ! empty( $guidance['link'] ) ) {
			$lines[] = '';
			$label   = ! empty( $guidance['link_label'] ) ? $guidance['link_label'] : __( 'Open related screen', 'karetaker' );
			$lines[] = $label . ': ' . $guidance['link'];
		}
		if ( $context ) {
			$lines[] = '';
			$lines[] = __( 'Technical details:', 'karetaker' ) . ' ' . wp_json_encode( $context );
		}
		$lines[] = '';
		$lines[] = __( 'Open Karetaker Activity:', 'karetaker' ) . ' ' . $tools;
		$lines[] = '';
		$lines[] = __( 'To stop these emails, open Karetaker → Settings and turn off Enable alerts.', 'karetaker' );

		return implode( "\n", $lines );
	}
}
