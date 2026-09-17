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
 * and whether a pause, maintenance window or quiet hours hold it back.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Routing {

	const CHANNELS    = array( 'email', 'telegram', 'slack', 'discord', 'teams', 'webhook' );
	const WINDOW_HOOK = 'karetaker_window_tick';

	/**
	 * Registers the hourly maintenance-window check.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_action( self::WINDOW_HOOK, array( __CLASS__, 'window_tick' ) );
		add_action( 'init', array( __CLASS__, 'sync_window_cron' ) );
	}

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
		$repeat = (string) Karetaker_Settings::get( 'alert_repeat' );
		if ( 'every' === $repeat ) {
			return 0;
		}
		return '6h' === $repeat ? 6 * HOUR_IN_SECONDS : DAY_IN_SECONDS;
	}

	/**
	 * Whether alerts are held right now by a pause or a maintenance window.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function held() {
		return Karetaker_Settings::alerts_paused_until() > 0 || null !== self::active_window();
	}

	/**
	 * Whether the current site time falls in quiet hours (Worth a look alerts only).
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function quiet_now() {
		$range = self::parse_range( (string) Karetaker_Settings::get( 'quiet_hours' ) );
		if ( ! $range ) {
			return false;
		}
		$now = (int) wp_date( 'G' ) * 60 + (int) wp_date( 'i' );
		return $range[0] <= $range[1] ? ( $now >= $range[0] && $now < $range[1] ) : ( $now >= $range[0] || $now < $range[1] );
	}

	/**
	 * Parses "HH:MM-HH:MM" into minutes after midnight.
	 *
	 * @since 1.0.0
	 * @param string $value Range text.
	 * @return array{0: int, 1: int}|null
	 */
	public static function parse_range( $value ) {
		if ( ! preg_match( '/^\s*(\d{1,2}):(\d{2})\s*[-–]\s*(\d{1,2}):(\d{2})\s*$/u', (string) $value, $m ) ) {
			return null;
		}
		if ( (int) $m[1] > 23 || (int) $m[3] > 23 || (int) $m[2] > 59 || (int) $m[4] > 59 ) {
			return null;
		}
		$start = (int) $m[1] * 60 + (int) $m[2];
		$end   = (int) $m[3] * 60 + (int) $m[4];
		return $start === $end ? null : array( $start, $end );
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
		if ( Karetaker_Events::SEVERITY_ACT === (int) $severity ) {
			return ! empty( $routes[ $channel ]['act'] );
		}
		if ( Karetaker_Events::SEVERITY_ATTENTION === (int) $severity ) {
			return ! empty( $routes[ $channel ]['review'] ) && ! self::quiet_now();
		}
		return false;
	}

	/**
	 * Recurring weekly maintenance windows.
	 *
	 * @since 1.0.0
	 * @return array<int, array{name: string, day: int, start: string, end: string, by: int}>
	 */
	public static function windows() {
		$windows = Karetaker_Settings::get( 'maintenance_windows' );
		return is_array( $windows ) ? array_values( $windows ) : array();
	}

	/**
	 * Adds or replaces a maintenance window.
	 *
	 * @since 1.0.0
	 * @param int    $index Existing index, or -1 to add.
	 * @param string $name  Label.
	 * @param int    $day   ISO weekday 1 (Monday) to 7.
	 * @param string $start HH:MM.
	 * @param string $end   HH:MM.
	 * @return bool
	 */
	public static function save_window( $index, $name, $day, $start, $end ) {
		if ( ! self::parse_range( $start . '-' . $end ) || $day < 1 || $day > 7 ) {
			return false;
		}
		$windows = self::windows();
		$row     = array(
			'name'  => sanitize_text_field( $name ),
			'day'   => (int) $day,
			'start' => $start,
			'end'   => $end,
			'by'    => get_current_user_id(),
		);
		if ( $index >= 0 && isset( $windows[ $index ] ) ) {
			$windows[ $index ] = $row;
		} else {
			$windows[] = $row;
		}
		Karetaker_Settings::update( array( 'maintenance_windows' => array_slice( $windows, 0, 20 ) ) );
		return true;
	}

	/**
	 * Removes a maintenance window.
	 *
	 * @since 1.0.0
	 * @param int $index Index.
	 * @return void
	 */
	public static function delete_window( $index ) {
		$windows = self::windows();
		unset( $windows[ $index ] );
		Karetaker_Settings::update( array( 'maintenance_windows' => array_values( $windows ) ) );
	}

	/**
	 * The window covering a moment, as start/end Unix times, or null.
	 *
	 * @since 1.0.0
	 * @param int|null $at Unix time (defaults to now).
	 * @return array{name: string, from: int, until: int}|null
	 */
	public static function active_window( $at = null ) {
		$at = null === $at ? time() : (int) $at;
		$tz = wp_timezone();
		foreach ( self::windows() as $window ) {
			$range = self::parse_range( $window['start'] . '-' . $window['end'] );
			if ( ! $range ) {
				continue;
			}
			foreach ( array( 0, -1 ) as $week_offset ) {
				$base  = ( new DateTimeImmutable( '@' . $at ) )->setTimezone( $tz )->setTime( 0, 0 );
				$delta = (int) $window['day'] - (int) $base->format( 'N' );
				$day   = $base->modify( ( $delta + 7 * $week_offset ) . ' days' );
				$from  = $day->getTimestamp() + $range[0] * 60;
				$until = $day->getTimestamp() + $range[1] * 60 + ( $range[1] <= $range[0] ? DAY_IN_SECONDS : 0 );
				if ( $at >= $from && $at < $until ) {
					return array(
						'name'  => (string) $window['name'],
						'from'  => $from,
						'until' => $until,
					);
				}
			}
		}
		return null;
	}

	/**
	 * Human text for a window, like "Tue 22:00–23:00".
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $window Window row.
	 * @return string
	 */
	public static function window_label( array $window ) {
		$day = ( new DateTimeImmutable( 'monday this week' ) )->modify( '+' . ( (int) $window['day'] - 1 ) . ' days' );
		return wp_date( 'D', $day->getTimestamp() ) . ' ' . $window['start'] . '–' . $window['end'];
	}

	/**
	 * Keeps the hourly window check scheduled only while windows exist.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function sync_window_cron() {
		$next = wp_next_scheduled( self::WINDOW_HOOK );
		if ( self::windows() && ! $next ) {
			wp_schedule_event( time() + 300, 'hourly', self::WINDOW_HOOK );
		} elseif ( ! self::windows() && $next ) {
			wp_unschedule_hook( self::WINDOW_HOOK );
		}
	}

	/**
	 * Sends the catch-up summary for any window that ended in the last hour.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function window_tick() {
		foreach ( array( 1, 10, 20, 30, 40, 50, 60, 70 ) as $minutes_ago ) {
			$window = self::active_window( time() - $minutes_ago * MINUTE_IN_SECONDS );
			if ( ! $window || $window['until'] > time() ) {
				continue;
			}
			$sent_key = 'karetaker_window_sent_' . $window['until'];
			if ( get_transient( $sent_key ) ) {
				continue;
			}
			set_transient( $sent_key, 1, DAY_IN_SECONDS );
			Karetaker_Summary::send_pause_summary( $window['from'], $window['until'] );
		}
	}
}
