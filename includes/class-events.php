<?php
/**
 * Event recorder.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Karetaker_Events {

	const SEVERITY_LOG       = 0;
	const SEVERITY_ATTENTION = 1;
	const SEVERITY_ACT       = 2;

	const TRIM_EVERY = 100;

	const CONTEXT_MAX_KEYS   = 20;
	const CONTEXT_MAX_LENGTH = 500;

	private static $booted = false;

	public static function init() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;
	}

	public static function codes() {
		return array(
			'admin_user_added'      => self::SEVERITY_ACT,
			'role_escalated'        => self::SEVERITY_ACT,
			'registration_opened'   => self::SEVERITY_ACT,
			'muplugin_changed'      => self::SEVERITY_ACT,
			'uploads_php_found'     => self::SEVERITY_ACT,
			'file_hash_mismatch'    => self::SEVERITY_ACT,
			'guard_tripped'         => self::SEVERITY_ACT,
			'orphan_cron_found'     => self::SEVERITY_ATTENTION,
			'option_changed'        => self::SEVERITY_ATTENTION,
			'file_editor_used'      => self::SEVERITY_ATTENTION,
			'admin_email_changed'   => self::SEVERITY_ATTENTION,
			'plugin_activated'      => self::SEVERITY_LOG,
			'plugin_deactivated'    => self::SEVERITY_LOG,
			'theme_switched'        => self::SEVERITY_LOG,
			'user_login'            => self::SEVERITY_LOG,
			'login_failure_burst'   => self::SEVERITY_ATTENTION,
			'user_created'          => self::SEVERITY_LOG,
			'user_deleted'          => self::SEVERITY_LOG,
			'scan_ran'              => self::SEVERITY_LOG,
			'setting_changed'       => self::SEVERITY_LOG,
		);
	}

	public static function severity_for( $code ) {
		$codes = self::codes();

		return isset( $codes[ $code ] ) ? $codes[ $code ] : self::SEVERITY_LOG;
	}

	public static function is_known_code( $code ) {
		return array_key_exists( $code, self::codes() );
	}

	public static function sanitize_context( $context ) {
		if ( ! is_array( $context ) ) {
			return array();
		}

		$clean = array();
		$count = 0;

		foreach ( $context as $key => $value ) {
			if ( $count >= self::CONTEXT_MAX_KEYS ) {
				break;
			}

			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
				++$count;
				continue;
			}

			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', array_slice( $value, 0, 10 ) ) );
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) $value );

			if ( strlen( $value ) > self::CONTEXT_MAX_LENGTH ) {
				$value = substr( $value, 0, self::CONTEXT_MAX_LENGTH ) . '…';
			}

			$clean[ $key ] = $value;
			++$count;
		}

		return $clean;
	}

	public static function record( $code, array $context = array(), $user_id = null ) {
		global $wpdb;

		$code = sanitize_key( $code );

		if ( ! self::is_known_code( $code ) ) {
			return 0;
		}

		if ( karetaker_is_disabled() ) {
			return 0;
		}

		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		$clean_context = self::sanitize_context( $context );
		$severity      = self::severity_for( $code );

		$ip     = self::client_ip();
		$packed = ( '' === $ip ) ? null : inet_pton( $ip );

		$inserted = $wpdb->insert(
			Karetaker_Schema::table(),
			array(
				'event_time' => current_time( 'mysql', true ),
				'event_code' => $code,
				'severity'   => $severity,
				'user_id'    => (int) $user_id,
				'ip'         => $packed ? $packed : null,
				'context'    => wp_json_encode( $clean_context ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;

		if ( 0 === $id % self::TRIM_EVERY ) {
			Karetaker_Schema::trim( $id );
		}

		do_action( 'karetaker_event_recorded', $id, $code, $severity, $clean_context );

		return $id;
	}

	public static function ip_in_cidr( $ip, $cidr ) {
		if ( false === strpos( $cidr, '/' ) ) {
			return $ip === $cidr;
		}

		list( $subnet, $bits ) = explode( '/', $cidr, 2 );

		$packed_ip     = inet_pton( $ip );
		$packed_subnet = inet_pton( $subnet );
		$bits          = (int) $bits;

		if ( false === $packed_ip || false === $packed_subnet ) {
			return false;
		}

		if ( strlen( $packed_ip ) !== strlen( $packed_subnet ) ) {
			return false;
		}

		if ( $bits < 0 || $bits > strlen( $packed_ip ) * 8 ) {
			return false;
		}

		$whole = intdiv( $bits, 8 );
		$rest  = $bits % 8;

		if ( 0 !== strncmp( $packed_ip, $packed_subnet, $whole ) ) {
			return false;
		}

		if ( 0 === $rest ) {
			return true;
		}

		$mask = chr( 0xFF << ( 8 - $rest ) & 0xFF );

		return ( $packed_ip[ $whole ] & $mask ) === ( $packed_subnet[ $whole ] & $mask );
	}

	public static function client_ip() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		$remote = filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP );

		if ( ! $remote ) {
			return '';
		}

		$header  = (string) Karetaker_Settings::get( 'forwarded_header' );
		$proxies = Karetaker_Settings::get( 'trusted_proxies' );

		if ( '' === $header || ! is_array( $proxies ) || ! $proxies ) {
			return $remote;
		}

		$trusted = false;

		foreach ( $proxies as $cidr ) {
			if ( self::ip_in_cidr( $remote, (string) $cidr ) ) {
				$trusted = true;
				break;
			}
		}

		if ( ! $trusted ) {
			return $remote;
		}

		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) );

		if ( empty( $_SERVER[ $key ] ) ) {
			return $remote;
		}

		$value     = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
		$candidate = filter_var( trim( strtok( $value, ',' ) ), FILTER_VALIDATE_IP );

		return $candidate ? $candidate : $remote;
	}

	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'limit'        => 50,
				'offset'       => 0,
				'min_severity' => null,
				'code'         => '',
				'since'        => '',
			)
		);

		$table  = Karetaker_Schema::table();
		$where  = array( '1=1' );
		$params = array();

		if ( null !== $args['min_severity'] ) {
			$where[]  = 'severity >= %d';
			$params[] = (int) $args['min_severity'];
		}

		if ( '' !== $args['code'] ) {
			$where[]  = 'event_code = %s';
			$params[] = sanitize_key( $args['code'] );
		}

		if ( '' !== $args['since'] ) {
			$where[]  = 'event_time >= %s';
			$params[] = $args['since'];
		}

		$params[] = max( 1, min( 500, (int) $args['limit'] ) );
		$params[] = max( 0, (int) $args['offset'] );

		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
	}

	public static function hydrate( $row ) {
		$row->severity   = (int) $row->severity;
		$row->user_id    = (int) $row->user_id;
		$row->context    = $row->context ? json_decode( $row->context, true ) : array();
		$row->ip_display = $row->ip ? inet_ntop( $row->ip ) : '';

		if ( ! is_array( $row->context ) ) {
			$row->context = array();
		}

		return $row;
	}
}
