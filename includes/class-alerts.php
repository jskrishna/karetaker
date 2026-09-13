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
	 * @param int $id Event row ID.
	 * @param string $code Event code.
	 * @param int $severity Severity constant.
	 * @param array $context Event context.
	 * @return bool
	 */
	public static function maybe_send( $id, $code, $severity, $context ) {
		if ( karetaker_is_disabled() ) {
			return false;
		}

		if ( (int) $severity !== Karetaker_Events::SEVERITY_ACT ) {
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
	 * @param array $context Event context.
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
	 * @param array $context Event context.
	 * @return string
	 */
	public static function subject_for( $code, array $context ) {
		$check = isset( $context['check'] ) ? (string) $context['check'] : '';

		$map = array(
			'admin_user_added'    => 'A new administrator account appeared',
			'role_escalated'      => 'A user was made administrator',
			'registration_opened' => 'Anyone can register on your site',
			'muplugin_changed'    => 'A must-use plugin file changed',
			'uploads_php_found'   => 'Executable PHP appeared under uploads',
			'file_hash_mismatch'  => 'A plugin or theme no longer matches wordpress.org',
			'guard_tripped'       => self::guard_subject( $check ),
		);

		$verdict = isset( $map[ $code ] ) ? $map[ $code ] : 'Something needs your attention';

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
			'blog_public'          => 'Search engines were discouraged',
			'mail_failed'          => 'WordPress could not send email',
			'admin_email_invalid'  => 'The admin email address is invalid',
			'no_administrator'     => 'No administrator remains on the site',
		);
		return isset( $map[ $check ] ) ? $map[ $check ] : 'A site setting needs attention';
	}

	/**
	 * Plain-text email body for an ACT alert.
	 *
	 * @since 0.1.0
	 * @param string $code Event code.
	 * @param array $context Event context.
	 * @param int $id Event row ID.
	 * @return string
	 */
	public static function body_for( $code, array $context, $id ) {
		$home  = home_url( '/' );
		$tools = admin_url( 'tools.php?page=karetaker' );

		$lines   = array();
		$lines[] = self::subject_for( $code, $context );
		$lines[] = '';
		$lines[] = 'Site: ' . $home;
		$lines[] = 'Event: ' . $code . ' (#' . (int) $id . ')';
		if ( $context ) {
			$lines[] = 'Details: ' . wp_json_encode( $context );
		}
		$lines[] = '';
		$lines[] = 'Open Karetaker: ' . $tools;
		$lines[] = '';
		$lines[] = 'To stop these emails, open that page → Settings and turn off Enable alerts.';

		return implode( "\n", $lines );
	}
}
