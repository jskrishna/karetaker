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
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Agency {

	const TOKEN_MAX_LEN = 256;

	/**
	 * Registers the agency REST routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers GET /karetaker/v1/status.
	 *
	 * @since 1.0.0
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
		register_rest_route(
			'karetaker/v1',
			'/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_events' ),
				'permission_callback' => array( __CLASS__, 'events_permission_check' ),
				'args'                => array(
					'severity' => array(
						'type' => 'string',
						'enum' => array( 'all', 'act', 'review' ),
					),
					'since'    => array( 'type' => 'string' ),
					'limit'    => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 500,
					),
				),
			)
		);
	}

	/**
	 * Allows the events route for tokens with the events scope.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function events_permission_check() {
		return Karetaker_Access::verify( self::request_token(), 'events' );
	}

	/**
	 * Returns recent events for a token with the events scope, signed like /status.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_events( $request ) {
		$severity = (string) $request->get_param( 'severity' );
		$args     = array(
			'limit' => $request->get_param( 'limit' ) ? (int) $request->get_param( 'limit' ) : 100,
		);
		if ( 'act' === $severity ) {
			$args['severity'] = Karetaker_Events::SEVERITY_ACT;
		} elseif ( 'review' === $severity ) {
			$args['min_severity'] = Karetaker_Events::SEVERITY_ATTENTION;
		}
		$since = strtotime( (string) $request->get_param( 'since' ) );
		if ( $since ) {
			$args['since'] = gmdate( 'Y-m-d H:i:s', $since );
		}
		$events = array();
		foreach ( Karetaker_Events::query( $args ) as $row ) {
			$events[] = array(
				'id'       => (int) $row->id,
				'time_utc' => (string) $row->event_time,
				'severity' => (int) $row->severity,
				'code'     => (string) $row->event_code,
				'user_id'  => (int) $row->user_id,
				'context'  => $row->context,
			);
		}
		$payload        = array(
			'site'   => home_url( '/' ),
			'events' => $events,
		);
		$payload['sig'] = self::sign( $payload, self::request_token() );
		return rest_ensure_response( $payload );
	}

	/**
	 * Allows the status route when the request Bearer token matches settings.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function permission_check() {
		return Karetaker_Access::verify( self::request_token(), 'status' );
	}

	/**
	 * Extracts the agency token from the Authorization Bearer header.
	 *
	 * Query-string tokens are not accepted (avoid leaking secrets in logs/referrers).
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_status() {
		$payload        = Karetaker_Status::snapshot();
		$payload['sig'] = self::sign( $payload, self::request_token() );

		return rest_ensure_response( $payload );
	}

	/**
	 * Recursively ksorts an array for canonical JSON.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
	 */
	public static function mask_token( $token ) {
		$token = (string) $token;
		if ( strlen( $token ) < 4 ) {
			return '••••';
		}

		return '••••' . substr( $token, -4 );
	}
}
