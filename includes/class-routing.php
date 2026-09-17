<?php
/**
 * Alert routing: which channel gets which alerts, and when alerts are held.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether a channel should receive an alert, how often repeats are allowed,
 * and whether a pause holds it back. Add-ons adjust this through filters.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Routing {

	const CHANNELS = array( 'email', 'telegram', 'slack', 'discord', 'teams', 'webhook' );

	/**
	 * Kept for add-ons that call it; routing needs no hooks of its own.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {}

	/**
	 * Channel labels.
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	public static function labels() {
		return array(
			'email'    => __( 'Email', 'karetaker' ),
			'telegram' => __( 'Telegram', 'karetaker' ),
			'slack'    => __( 'Slack', 'karetaker' ),
			'discord'  => __( 'Discord', 'karetaker' ),
			'teams'    => __( 'Microsoft Teams', 'karetaker' ),
			'webhook'  => __( 'Webhook', 'karetaker' ),
		);
	}

	/**
	 * Whether a channel has the details it needs to send.
	 *
	 * @since 1.0.0
	 * @param string $channel Channel key.
	 * @return bool
	 */
	public static function connected( $channel ) {
		$s = Karetaker_Settings::all();
		switch ( $channel ) {
			case 'email':
				return is_email( Karetaker_Settings::alert_email() );
			case 'telegram':
				return '' !== (string) $s['telegram_bot_token'] && '' !== (string) $s['telegram_chat_id'];
			case 'slack':
				return '' !== (string) $s['slack_webhook_url'];
			case 'discord':
				return '' !== (string) $s['discord_webhook_url'];
			case 'teams':
				return '' !== (string) $s['teams_webhook_url'];
			case 'webhook':
				return '' !== (string) $s['webhook_url'];
		}
		return false;
	}

	/**
	 * Routing rules per channel: act, review, weekly.
	 *
	 * Falls back to the older per-channel on/off settings so existing sites keep behaving the same.
	 *
	 * @since 1.0.0
	 * @return array<string, array{act: bool, review: bool, weekly: bool}>
	 */
	public static function routes() {
		$s      = Karetaker_Settings::all();
		$stored = isset( $s['routes'] ) && is_array( $s['routes'] ) ? $s['routes'] : array();
		$legacy = array(
			'email'    => ! empty( $s['alerts_enabled'] ),
			'telegram' => ! empty( $s['telegram_enabled'] ),
			'slack'    => ! empty( $s['slack_enabled'] ),
			'discord'  => ! empty( $s['discord_enabled'] ),
			'teams'    => ! empty( $s['teams_enabled'] ),
			'webhook'  => ! empty( $s['webhook_enabled'] ),
		);
		$out    = array();
		foreach ( self::CHANNELS as $channel ) {
			$row             = isset( $stored[ $channel ] ) && is_array( $stored[ $channel ] ) ? $stored[ $channel ] : array();
			$out[ $channel ] = array(
				'act'    => isset( $row['act'] ) ? (bool) $row['act'] : $legacy[ $channel ],
				'review' => ! empty( $row['review'] ),
				'weekly' => isset( $row['weekly'] ) ? (bool) $row['weekly'] : ( 'email' === $channel && ! empty( $s['weekly_summary_enabled'] ) ),
			);
		}
		return $out;
	}

	/**
	 * Saves routing rules and keeps the older on/off flags in step.
	 *
	 * @since 1.0.0
	 * @param array<string, array<string, bool>> $routes Rules per channel.
	 * @return void
	 */
	public static function save_routes( array $routes ) {
		$clean = array();
		foreach ( self::CHANNELS as $channel ) {
			$row               = isset( $routes[ $channel ] ) && is_array( $routes[ $channel ] ) ? $routes[ $channel ] : array();
			$clean[ $channel ] = array(
				'act'    => ! empty( $row['act'] ),
				'review' => ! empty( $row['review'] ),
				'weekly' => 'webhook' === $channel ? false : ! empty( $row['weekly'] ),
			);
		}
		Karetaker_Settings::update(
			array(
				'routes'                 => $clean,
				'alerts_enabled'         => $clean['email']['act'],
				'telegram_enabled'       => $clean['telegram']['act'],
				'slack_enabled'          => $clean['slack']['act'],
				'discord_enabled'        => $clean['discord']['act'],
				'teams_enabled'          => $clean['teams']['act'],
				'webhook_enabled'        => $clean['webhook']['act'],
				'weekly_summary_enabled' => $clean['email']['weekly'],
			)
		);
		Karetaker_Summary::sync_schedule();
	}

	/**
	 * Updates one rule for one channel.
	 *
	 * @since 1.0.0
	 * @param string $channel Channel key.
	 * @param string $kind    act, review, or weekly.
	 * @param bool   $on      New value.
	 * @return void
	 */
	public static function set( $channel, $kind, $on ) {
		$routes = self::routes();
		if ( isset( $routes[ $channel ] ) && in_array( $kind, array( 'act', 'review', 'weekly' ), true ) ) {
			$routes[ $channel ][ $kind ] = (bool) $on;
			self::save_routes( $routes );
		}
	}

	/**
	 * Seconds before the same alert may be sent again (0 = every time).
	 *
	 * @since 1.0.0
	 * @return int
	 */
	public static function repeat_ttl() {
		$ttl = DAY_IN_SECONDS;

		/**
		 * Filters how long the same alert is held back before it may repeat.
		 *
		 * @since 1.0.2
		 * @param int $ttl Seconds (0 = every time).
		 */
		return (int) apply_filters( 'karetaker_repeat_ttl', $ttl );
	}

	/**
	 * Whether alerts are held right now by a pause.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function held() {
		$held = Karetaker_Settings::alerts_paused_until() > 0;

		/**
		 * Filters whether alerts are held right now.
		 *
		 * @since 1.0.2
		 * @param bool $held Held by a pause.
		 */
		return (bool) apply_filters( 'karetaker_alerts_held', $held );
	}

	/**
	 * Whether a channel should get an alert of this severity now.
	 *
	 * @since 1.0.0
	 * @param string $channel  Channel key.
	 * @param int    $severity Severity constant.
	 * @return bool
	 */
	public static function should_send( $channel, $severity ) {
		if ( karetaker_is_disabled() || self::held() || ! self::connected( $channel ) ) {
			return false;
		}
		$routes = self::routes();
		$send   = false;
		if ( Karetaker_Events::SEVERITY_ACT === (int) $severity ) {
			$send = ! empty( $routes[ $channel ]['act'] );
		} elseif ( Karetaker_Events::SEVERITY_ATTENTION === (int) $severity ) {
			$send = ! empty( $routes[ $channel ]['review'] );
		}

		/**
		 * Filters whether a channel gets an alert of this severity now.
		 *
		 * @since 1.0.2
		 * @param bool   $send     Decision so far.
		 * @param string $channel  Channel key.
		 * @param int    $severity Severity constant.
		 */
		return (bool) apply_filters( 'karetaker_should_send', $send, $channel, (int) $severity );
	}
}
