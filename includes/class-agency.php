<?php
/**
 * Agency REST status channel.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Karetaker_Agency {

	const TOKEN_MAX_LEN = 256;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

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

	public static function request_token() {
		$header = self::authorization_header();
		if ( '' !== $header && preg_match( '/^Bearer\s+(\S+)$/i', $header, $matches ) ) {
			return self::normalize_token( $matches[1] );
		}

		if ( isset( $_GET['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return self::normalize_token( wp_unslash( $_GET['token'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
		}

		return '';
	}

	private static function authorization_header() {
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return trim( (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return trim( (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $name => $value ) {
					if ( 0 === strcasecmp( (string) $name, 'Authorization' ) ) {
						return trim( (string) $value );
					}
				}
			}
		}

		return '';
	}

	private static function normalize_token( $raw ) {
		$token = sanitize_text_field( (string) $raw );
		if ( strlen( $token ) > self::TOKEN_MAX_LEN ) {
			$token = substr( $token, 0, self::TOKEN_MAX_LEN );
		}

		return $token;
	}

	public static function get_status() {
		$payload = Karetaker_Status::snapshot();
		$token   = (string) Karetaker_Settings::get( 'agency_token' );
		$payload['sig'] = self::sign( $payload, $token );

		return rest_ensure_response( $payload );
	}

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

	public static function canonical_json( array $data ) {
		self::ksort_recursive( $data );

		return wp_json_encode( $data );
	}

	public static function sign( array $payload, $token ) {
		return hash_hmac( 'sha256', self::canonical_json( $payload ), (string) $token );
	}

	/**
	 * Generate a new agency token.
	 *
	 * @return string
	 */
	public static function generate_token() {
		return wp_generate_password( 48, false, false );
	}

	/**
	 * Mask a token for display (•••• + last 4).
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	public static function mask_token( $token ) {
		$token = (string) $token;
		if ( strlen( $token ) < 4 ) {
			return '••••';
		}

		return '••••' . substr( $token, -4 );
	}
}
