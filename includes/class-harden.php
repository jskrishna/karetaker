<?php
/**
 * Opt-in hardening toggles.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Karetaker_Harden {

	const KEYS = array(
		'headers',
		'xmlrpc',
		'file_editor',
		'user_enum',
		'version',
		'registration',
		'app_passwords',
	);

	private static $booted = false;

	public static function defaults() {
		return array(
			'headers'       => false,
			'xmlrpc'        => false,
			'file_editor'   => false,
			'user_enum'     => false,
			'version'       => false,
			'registration'  => false,
			'app_passwords' => false,
		);
	}

	public static function desired() {
		$h = Karetaker_Settings::harden();
		$out = array();

		foreach ( self::KEYS as $key ) {
			$out[ $key ] = ! empty( $h[ $key ] );
		}

		return $out;
	}

	public static function is_on( $key ) {
		$key = sanitize_key( $key );

		if ( ! in_array( $key, self::KEYS, true ) ) {
			return false;
		}

		$d = self::desired();

		return ! empty( $d[ $key ] );
	}

	public static function init() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		if ( self::is_on( 'headers' ) ) {
			add_action( 'send_headers', array( __CLASS__, 'send_headers' ) );
			add_action( 'login_init', array( __CLASS__, 'send_headers' ) );
			add_action( 'admin_init', array( __CLASS__, 'send_headers' ) );
		}

		if ( self::is_on( 'xmlrpc' ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', array( __CLASS__, 'filter_xmlrpc_methods' ) );
			add_filter( 'wp_headers', array( __CLASS__, 'filter_wp_headers' ) );
		}

		if ( self::is_on( 'file_editor' ) ) {
			if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
				define( 'DISALLOW_FILE_EDIT', true );
			}
		}

		if ( self::is_on( 'user_enum' ) ) {
			self::apply_user_enum();
		}

		if ( self::is_on( 'version' ) ) {
			self::apply_version();
		}

		if ( self::is_on( 'registration' ) ) {
			self::apply_registration();
		}

		if ( self::is_on( 'app_passwords' ) ) {
			self::apply_app_passwords();
		}
	}

	public static function send_headers() {
		if ( headers_sent() ) {
			return;
		}

		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()' );

		if ( is_ssl() && 'local' !== wp_get_environment_type() ) {
			header( 'Strict-Transport-Security: max-age=15552000' );
		}
	}

	public static function filter_xmlrpc_methods( $methods ) {
		unset(
			$methods['pingback.ping'],
			$methods['pingback.extensions.getPingbacks']
		);

		return $methods;
	}

	public static function filter_wp_headers( $headers ) {
		unset( $headers['X-Pingback'] );

		return $headers;
	}

	private static function apply_user_enum() {
		add_filter( 'rest_endpoints', array( __CLASS__, 'filter_rest_endpoints' ) );
		add_action( 'parse_request', array( __CLASS__, 'block_author_enum' ) );
	}

	public static function filter_rest_endpoints( $endpoints ) {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}

		if ( ! is_array( $endpoints ) ) {
			return $endpoints;
		}

		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( (string) $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}

		return $endpoints;
	}

	public static function block_author_enum( $wp ) {
		unset( $wp );

		if ( is_admin() ) {
			return;
		}

		if ( ! isset( $_GET['author'] ) ) {
			return;
		}

		$author = sanitize_text_field( wp_unslash( $_GET['author'] ) );

		if ( '' === $author || ! ctype_digit( $author ) ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}

	private static function apply_version() {
		add_filter( 'the_generator', array( __CLASS__, 'empty_generator' ) );
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'style_loader_src', array( __CLASS__, 'strip_ver_query' ) );
		add_filter( 'script_loader_src', array( __CLASS__, 'strip_ver_query' ) );
	}

	public static function empty_generator() {
		return '';
	}

	public static function strip_ver_query( $src ) {
		if ( is_string( $src ) && false !== strpos( $src, 'ver=' ) ) {
			$src = remove_query_arg( 'ver', $src );
		}

		return $src;
	}

	private static function apply_registration() {
		add_filter( 'pre_option_users_can_register', array( __CLASS__, 'force_registration_closed' ) );
	}

	public static function force_registration_closed( $pre ) {
		unset( $pre );

		return '0';
	}

	private static function apply_app_passwords() {
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'filter_app_passwords_user' ), 10, 2 );
	}

	public static function filter_app_passwords_user( $available, $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return false;
		}

		if ( user_can( $user, 'manage_options' ) ) {
			return $available;
		}

		return false;
	}

	public static function probe( $key ) {
		$key = sanitize_key( $key );

		if ( ! in_array( $key, self::KEYS, true ) ) {
			return 'Unknown';
		}

		$on = self::is_on( $key );

		switch ( $key ) {
			case 'headers':
				if ( ! $on ) {
					return 'Off';
				}

				return self::$booted ? 'Enabled for responses' : 'Off';

			case 'xmlrpc':
				if ( ! $on ) {
					return 'Off';
				}

				return apply_filters( 'xmlrpc_enabled', true ) ? 'Enabled' : 'Disabled';

			case 'file_editor':
				if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
					return $on ? 'Blocked' : 'Blocked by host/wp-config';
				}

				if ( ! $on ) {
					return 'Off';
				}

				return 'Editor allowed';

			case 'user_enum':
				if ( ! $on ) {
					return 'Off';
				}

				return has_filter( 'rest_endpoints', array( __CLASS__, 'filter_rest_endpoints' ) )
					? 'REST users hidden for guests'
					: 'Off';

			case 'version':
				if ( ! $on ) {
					return 'Off';
				}

				return has_filter( 'the_generator', array( __CLASS__, 'empty_generator' ) )
					? 'Generator stripped'
					: 'Off';

			case 'registration':
				if ( ! $on ) {
					return 'Off';
				}

				return (string) get_option( 'users_can_register' ) === '0' ? 'Closed' : 'Open';

			case 'app_passwords':
				if ( ! $on ) {
					return 'Off';
				}

				return has_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'filter_app_passwords_user' ) )
					? 'Limited to admins'
					: 'Off';

			default:
				return 'Unknown';
		}
	}
}
