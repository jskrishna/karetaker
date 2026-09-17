<?php
/**
 * Settings storage.
 *
 * @package Karetaker
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the `karetaker_settings` option.
 *
 * @since 1.0.0
 */
class Karetaker_Settings {

	const OPTION = 'karetaker_settings';

	const ROW_CAP_MIN = 500;
	const ROW_CAP_MAX = 50000;

	/**
	 * In-request settings cache.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cache = null;

	/**
	 * Default settings array.
	 *
	 * @since 1.0.0
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'row_cap'                => 5000,
			'alert_email'            => '',
			'alerts_enabled'         => true,
			'trusted_proxies'        => array(),
			'forwarded_header'       => '',
			'harden'                 => array(
				'headers'       => false,
				'xmlrpc'        => false,
				'file_editor'   => false,
				'user_enum'     => false,
				'version'       => false,
				'registration'  => false,
				'app_passwords' => false,
			),
			'agency_token'           => '',
			'webhook_enabled'        => false,
			'webhook_url'            => '',
			'webhook_secret'         => '',
			'vuln_lookup_enabled'    => false,
			'slack_enabled'          => false,
			'slack_webhook_url'      => '',
			'telegram_enabled'       => false,
			'telegram_bot_token'     => '',
			'telegram_chat_id'       => '',
			'alerts_paused_until'    => 0,
			'report_agency'          => '',
			'report_client'          => '',
			'alerts_paused_from'     => 0,
			'weekly_summary_enabled' => false,
			'discord_enabled'        => false,
			'discord_webhook_url'    => '',
			'teams_enabled'          => false,
			'teams_webhook_url'      => '',
			'report_client_email'    => '',
			'report_logo_id'         => 0,
			'routes'                 => array(),
			'alert_repeat'           => '24h',
			'quiet_hours'            => '',
			'weekly_slot'            => 'mon09',
			'maintenance_windows'    => array(),
			'advanced_mode'          => false,
			'site_type'              => '',
			'admins_only'            => true,
			'role_caps'              => array(),
			'report_include'         => array( 'summary', 'issues', 'plugins', 'harden' ),
			'report_schedule'        => '',
		);
	}

	/**
	 * Agency status token (empty means the channel is off).
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function agency_token() {
		return (string) self::get( 'agency_token' );
	}

	/**
	 * Harden toggle map merged with defaults.
	 *
	 * @since 1.0.0
	 * @return array<string,bool>
	 */
	public static function harden() {
		$h = self::get( 'harden' );

		if ( ! is_array( $h ) ) {
			$h = array();
		}

		return wp_parse_args( $h, Karetaker_Harden::defaults() );
	}

	/**
	 * Full settings array with defaults applied.
	 *
	 * @since 1.0.0
	 * @return array<string,mixed>
	 */
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

	/**
	 * Single settings value.
	 *
	 * @since 1.0.0
	 * @param string $key Setting key.
	 * @return mixed|null
	 */
	public static function get( $key ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Merge and persist settings changes.
	 *
	 * @since 1.0.0
	 * @param array<string,mixed> $changes Partial settings.
	 * @return void
	 */
	public static function update( array $changes ) {
		// Merge into the stored value, not this request's cache, so a long request (a scan)
		// cannot overwrite a setting saved by another request in the meantime.
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		self::$cache = null;
		$all         = array_merge( self::all(), $changes );

		self::$cache = $all;

		update_option( self::OPTION, $all, false );
	}

	/**
	 * Event table row cap clamped to allowed bounds.
	 *
	 * @since 1.0.0
	 * @return int
	 */
	public static function row_cap() {
		$cap = (int) self::get( 'row_cap' );

		return max( self::ROW_CAP_MIN, min( self::ROW_CAP_MAX, $cap ) );
	}

	/**
	 * Alert recipient email, falling back to admin_email.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function alert_email() {
		$email = (string) self::get( 'alert_email' );

		if ( '' === $email || ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email' );
		}

		return $email;
	}

	/**
	 * Unix time alerts are paused until, or 0 when alerts are live.
	 *
	 * @since 1.0.0
	 * @return int
	 */
	public static function alerts_paused_until() {
		$until = (int) self::get( 'alerts_paused_until' );

		return $until > time() ? $until : 0;
	}

	/**
	 * Delete the settings option on uninstall.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function uninstall() {
		delete_option( self::OPTION );
	}
}
