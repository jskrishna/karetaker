<?php
/**
 * Email alerts for events that need the owner.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends one email per new problem, following the routing rules and repeat window.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Alerts {

	/**
	 * Listens for recorded events.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_action( 'karetaker_event_recorded', array( __CLASS__, 'maybe_send' ), 10, 4 );
	}

	/**
	 * Sends an alert email for a newly recorded event when routing allows it.
	 *
	 * @since 1.0.0
	 * @param int    $id       Event row ID.
	 * @param string $code     Event code.
	 * @param int    $severity Severity constant.
	 * @param array  $context  Event context.
	 * @return bool
	 */
	public static function maybe_send( $id, $code, $severity, $context ) {
		if ( ! Karetaker_Routing::should_send( 'email', (int) $severity ) ) {
			return false;
		}

		$code    = sanitize_key( $code );
		$context = is_array( $context ) ? $context : array();

		// Mail is already broken; another email would recurse on wp_mail_failed.
		if ( 'guard_tripped' === $code && isset( $context['check'] ) && 'mail_failed' === $context['check'] ) {
			return false;
		}

		$key = self::dedupe_key( $code, $context );
		$ttl = Karetaker_Routing::repeat_ttl();
		if ( $ttl && get_transient( $key ) ) {
			return false;
		}

		$level = Karetaker_Events::SEVERITY_ACT === (int) $severity ? 'act' : 'review';
		$sent  = Karetaker_Email::send(
			Karetaker_Settings::alert_email(),
			self::subject_for( $code, $context, $level ),
			self::email_args( $code, $context, (int) $id, $level )
		);

		if ( $sent && $ttl ) {
			set_transient( $key, 1, $ttl );
		}

		return $sent;
	}

	/**
	 * Sends a test email to the alert address, ignoring routing and repeat rules.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function send_test() {
		return Karetaker_Email::send(
			Karetaker_Settings::alert_email(),
			Karetaker_Email::subject( '', __( 'Test alert', 'karetaker' ) ),
			self::test_args()
		);
	}

	/**
	 * Template arguments for the test email.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public static function test_args() {
		$host = Karetaker_Email::host();
		return array(
			'level'   => 'safe',
			'eyebrow' => __( 'Test alert', 'karetaker' ),
			'title'   => __( 'Alerts will reach you here', 'karetaker' ),
			/* translators: %s: site host */
			'lead'    => sprintf( __( 'This is a test from Karetaker on %s. When something needs you, the email will look like this, with what happened and what to do.', 'karetaker' ), $host ),
			'meta'    => array(
				__( 'Site', 'karetaker' ) => $host,
				__( 'Sent', 'karetaker' ) => wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ) ),
			),
			'buttons' => array( array( __( 'Open Karetaker', 'karetaker' ), admin_url( 'admin.php?page=karetaker' ) ) ),
			'reason'  => self::reason( 'safe' ),
		);
	}

	/**
	 * Template arguments for an event alert.
	 *
	 * @since 1.0.0
	 * @param string $code    Event code.
	 * @param array  $context Event context.
	 * @param int    $id      Event ID.
	 * @param string $level   act or review.
	 * @return array<string, mixed>
	 */
	public static function email_args( $code, array $context, $id, $level ) {
		$guidance = Karetaker_Guidance::for_event( $code, $context );
		$home     = admin_url( 'admin.php?page=karetaker' );
		$buttons  = array();
		if ( ! empty( $guidance['link'] ) ) {
			$buttons[] = array( ! empty( $guidance['link_label'] ) ? (string) $guidance['link_label'] : __( 'Open the related screen', 'karetaker' ), (string) $guidance['link'] );
		}
		$buttons[] = array( __( 'Open in Karetaker', 'karetaker' ), $home );

		$details = array();
		foreach ( Karetaker_Admin_Data::details( $context ) as $row ) {
			$value     = (string) $row[1];
			$details[] = array( (string) $row[0], strlen( $value ) > 400 ? substr( $value, 0, 400 ) . '…' : $value );
		}
		$details[] = array( __( 'Event', 'karetaker' ), $code . ' #' . (int) $id );

		return array(
			'level'   => $level,
			'title'   => self::headline_for( $code, $context ),
			'lead'    => (string) $guidance['summary'],
			'meta'    => array(
				__( 'Site', 'karetaker' )     => Karetaker_Email::host(),
				__( 'Detected', 'karetaker' ) => wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ) ),
			),
			'steps'   => array_values( array_map( 'strval', (array) $guidance['steps'] ) ),
			'buttons' => $buttons,
			'details' => $details,
			'reason'  => self::reason( $level ),
		);
	}

	/**
	 * Why the recipient gets the email.
	 *
	 * @since 1.0.0
	 * @param string $level act, review or safe.
	 * @return string
	 */
	private static function reason( $level ) {
		$to = Karetaker_Settings::alert_email();
		if ( 'act' === $level ) {
			/* translators: 1: email address, 2: site host */
			return sprintf( __( 'You get this because %1$s receives Act now alerts for %2$s.', 'karetaker' ), $to, Karetaker_Email::host() );
		}
		if ( 'review' === $level ) {
			/* translators: 1: email address, 2: site host */
			return sprintf( __( 'You get this because %1$s receives Check this alerts for %2$s.', 'karetaker' ), $to, Karetaker_Email::host() );
		}
		/* translators: 1: email address, 2: site host */
		return sprintf( __( 'You get this because %1$s is set to receive Karetaker email for %2$s.', 'karetaker' ), $to, Karetaker_Email::host() );
	}

	/**
	 * Builds a transient key for alert deduplication.
	 *
	 * @since 1.0.0
	 * @param string $code    Event code.
	 * @param array  $context Event context.
	 * @return string
	 */
	public static function dedupe_key( $code, array $context ) {
		ksort( $context );
		return 'karetaker_alert_' . md5( $code . '|' . wp_json_encode( $context ) );
	}

	/**
	 * Subject line, for example "Act now: A must-use plugin file changed · example.com".
	 *
	 * @since 1.0.0
	 * @param string $code    Event code.
	 * @param array  $context Event context.
	 * @param string $level   act or review.
	 * @return string
	 */
	public static function subject_for( $code, array $context, $level = 'act' ) {
		return Karetaker_Email::subject( $level, self::headline_for( $code, $context ) );
	}

	/**
	 * Plain headline for an event.
	 *
	 * @since 1.0.0
	 * @param string $code    Event code.
	 * @param array  $context Event context.
	 * @return string
	 */
	public static function headline_for( $code, array $context ) {
		$guidance = Karetaker_Guidance::for_event( $code, $context );
		if ( ! empty( $guidance['title'] ) ) {
			return (string) $guidance['title'];
		}
		return __( 'Something needs your attention', 'karetaker' );
	}
}
