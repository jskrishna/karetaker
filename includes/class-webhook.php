<?php
/**
 * Optional ACT-only outbound webhooks.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Posts ACT events to a configured webhook URL with HMAC signature.
 *
 * @since 1.0.0
 */
class Karetaker_Webhook {

	/**
	 * When true (test alerts), requests wait for the reply and only a 2xx counts as sent.
	 *
	 * @var bool
	 */
	public static $verify = false;

	/**
	 * Whether a request was accepted: dispatched for normal alerts, answered 2xx when verifying.
	 *
	 * @since 1.0.0
	 * @param array|WP_Error $response HTTP response.
	 * @return bool
	 */
	private static function accepted( $response ) {
		if ( is_wp_error( $response ) ) {
			return false;
		}
		if ( ! self::$verify ) {
			return true;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	/**
	 * Listens for recorded ACT events.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_action( 'karetaker_event_recorded', array( __CLASS__, 'maybe_deliver' ), 20, 4 );
	}

	/**
	 * Delivers one ACT payload when webhook settings are enabled.
	 *
	 * @since 1.0.0
	 * @param int    $id Event row ID.
	 * @param string $code Event code.
	 * @param int    $severity Severity constant.
	 * @param array  $context Event context.
	 * @return bool
	 */
	public static function maybe_deliver( $id, $code, $severity, $context ) {
		if ( karetaker_is_disabled() ) {
			return false;
		}

		if ( ! Karetaker_Routing::should_send( 'webhook', (int) $severity ) ) {
			return false;
		}

		return self::deliver( $id, $code, $severity, $context );
	}

	/**
	 * Sends a test payload when the webhook is turned on.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function send_test() {
		return self::deliver( 0, 'karetaker_test', Karetaker_Events::SEVERITY_ACT, array( 'test' => true ) );
	}

	/**
	 * Posts one signed payload to the configured webhook URL.
	 *
	 * @since 1.0.0
	 * @param int    $id Event row ID.
	 * @param string $code Event code.
	 * @param int    $severity Severity constant.
	 * @param array  $context Event context.
	 * @return bool
	 */
	private static function deliver( $id, $code, $severity, $context ) {

		$url = esc_url_raw( (string) Karetaker_Settings::get( 'webhook_url' ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return false;
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		$secret = (string) Karetaker_Settings::get( 'webhook_secret' );
		$body   = wp_json_encode(
			array(
				'id'       => (int) $id,
				'code'     => sanitize_key( $code ),
				'severity' => (int) $severity,
				'context'  => is_array( $context ) ? $context : array(),
				'site'     => home_url( '/' ),
				'time'     => gmdate( 'c' ),
			)
		);

		if ( false === $body ) {
			return false;
		}

		$headers = array(
			'Content-Type' => 'application/json; charset=utf-8',
		);

		if ( '' !== $secret ) {
			$headers['X-Karetaker-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'  => 5,
				'blocking' => ! self::$verify,
				'body'     => $body,
				'headers'  => $headers,
			)
		);

		return self::accepted( $response );
	}
}
