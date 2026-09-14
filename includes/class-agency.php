<?php
/**
 * Agency REST status channel.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional signed read-only REST status channel.
 *
 * @since 0.1.0
 * @package Karetaker
 */
class Karetaker_Agency {

	const TOKEN_MAX_LEN = 256;

	/**
	 * Registers the agency REST routes.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers GET /karetaker/v1/status.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'karetaker/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_status' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
			)
		);
	}

	/**
	 * Allows the status route when the request Bearer token matches settings.
	 *
	 * @since 0.1.0
	 * @return bool
	 */
	public static function permission_check() {
		$token = (string) Karetaker_Settings::get( 'agency_token' );
		if ( '' === $token ) {
			return false;
		}

		$provided = self::request_token();
		if ( '' === $provided || ! hash_equals( $token, $provided ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Extracts the agency token from the Authorization Bearer header.
	 *
	 * Query-string tokens are not accepted (avoid leaking secrets in logs/referrers).
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public static function request_token() {
		$header = self::authorization_header();
		if ( '' !== $header && preg_match( '/^Bearer\s+(\S+)$/i', $header, $matches ) ) {
			return self::normalize_token( $matches[1] );
		}

		return '';
	}

	/**
	 * Reads the Authorization request header when present.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	private static function authorization_header() {
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $name => $value ) {
					if ( 0 === strcasecmp( (string) $name, 'Authorization' ) ) {
						return sanitize_text_field( (string) $value );
					}
				}
			}
		}

		return '';
	}

	/**
	 * Sanitizes and truncates a raw token string.
	 *
	 * @since 0.1.0
	 * @param string $raw Raw token input.
	 * @return string
	 */
	private static function normalize_token( $raw ) {
		$token = sanitize_text_field( (string) $raw );
		if ( strlen( $token ) > self::TOKEN_MAX_LEN ) {
			$token = substr( $token, 0, self::TOKEN_MAX_LEN );
		}

		return $token;
	}

	/**
	 * Returns the signed status snapshot for agency monitoring.
	 *
	 * @since 0.1.0
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_status() {
		$payload        = Karetaker_Status::snapshot();
		$token          = (string) Karetaker_Settings::get( 'agency_token' );
		$payload['sig'] = self::sign( $payload, $token );

		return rest_ensure_response( $payload );
	}

	/**
	 * Recursively ksorts an array for canonical JSON.
	 *
	 * @since 0.1.0
	 * @param array &$arr Array sorted by reference.
	 * @return void
	 */
	public static function ksort_recursive( &$arr ) {
		if ( ! is_array( $arr ) ) {
			return;
		}

		ksort( $arr );
		foreach ( $arr as &$v ) {
			if ( is_array( $v ) ) {
				self::ksort_recursive( $v );
			}
		}
		unset( $v );
	}

	/**
	 * JSON-encodes a payload with recursively sorted keys.
	 *
	 * @since 0.1.0
	 * @param array $data Payload without signature.
	 * @return string|false
	 */
	public static function canonical_json( array $data ) {
		self::ksort_recursive( $data );

		return wp_json_encode( $data );
	}

	/**
	 * HMAC-SHA256 signature over the canonical JSON payload.
	 *
	 * @since 0.1.0
	 * @param array  $payload Unsigned status payload.
	 * @param string $token Agency token.
	 * @return string
	 */
	public static function sign( array $payload, $token ) {
		return hash_hmac( 'sha256', self::canonical_json( $payload ), (string) $token );
	}

	/**
	 * Generate a new agency token.
	 *
	 * @return string
	 *
	 * @since 0.1.0
	 */
	public static function generate_token() {
		return wp_generate_password( 48, false, false );
	}

	/**
	 * Mask a token for display (•••• + last 4).
	 *
	 * @param string $token Raw token.
	 * @return string
	 *
	 * @since 0.1.0
	 */
	public static function mask_token( $token ) {
		$token = (string) $token;
		if ( strlen( $token ) < 4 ) {
			return '••••';
		}

		return '••••' . substr( $token, -4 );
	}
}
