<?php
/**
 * Settings storage.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Karetaker_Settings {

	const OPTION = 'karetaker_settings';

	const ROW_CAP_MIN = 500;
	const ROW_CAP_MAX = 50000;

	private static $cache = null;

	public static function defaults() {
		return array(
			'row_cap'          => 5000,
			'alert_email'      => '',
			'alerts_enabled'   => true,
			'trusted_proxies'  => array(),
			'forwarded_header' => '',
			'harden'           => array(
				'headers'       => false,
				'xmlrpc'        => false,
				'file_editor'   => false,
				'user_enum'     => false,
				'version'       => false,
				'registration'  => false,
				'app_passwords' => false,
			),
			'agency_token'     => '',
		);
	}

	public static function agency_token() {
		return (string) self::get( 'agency_token' );
	}

	public static function harden() {
		$h = self::get( 'harden' );

		if ( ! is_array( $h ) ) {
			$h = array();
		}

		return wp_parse_args( $h, Karetaker_Harden::defaults() );
	}

	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		self::$cache = wp_parse_args( $stored, self::defaults() );

		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $changes ) {
		$all = array_merge( self::all(), $changes );

		self::$cache = $all;

		update_option( self::OPTION, $all, false );
	}

	public static function row_cap() {
		$cap = (int) self::get( 'row_cap' );

		return max( self::ROW_CAP_MIN, min( self::ROW_CAP_MAX, $cap ) );
	}

	public static function alert_email() {
		$email = (string) self::get( 'alert_email' );

		if ( '' === $email || ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email' );
		}

		return $email;
	}

	public static function uninstall() {
		delete_option( self::OPTION );
	}
}
