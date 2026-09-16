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
 * @since 0.1.0
 */
class Karetaker_Webhook {

	/**
	 * Listens for recorded ACT events.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function init() {
		add_action( 'karetaker_event_recorded', array( __CLASS__, 'maybe_deliver' ), 20, 4 );
	}

	/**
	 * Delivers one ACT payload when webhook settings are enabled.
	 *
	 * @since 0.1.0
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

		if ( Karetaker_Events::SEVERITY_ACT !== (int) $severity ) {
			return false;
		}

		if ( ! Karetaker_Settings::get( 'webhook_enabled' ) ) {
			return false;
		}

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
				'timeout' => 5,
				'body'    => $body,
				'headers' => $headers,
			)
		);

		return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) >= 200 && wp_remote_retrieve_response_code( $response ) < 300;
	}
}
