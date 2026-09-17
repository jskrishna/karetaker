<?php
/**
 * Opt-in summary emails: weekly digest and end-of-pause catch-up.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends the weekly summary and the one summary after an alert pause ends.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Summary {

	const WEEKLY_HOOK = 'karetaker_digest';
	const PAUSE_HOOK  = 'karetaker_pause_end';

	/**
	 * Registers cron callbacks and keeps the weekly schedule in line with the setting.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_action( self::WEEKLY_HOOK, array( __CLASS__, 'send_weekly' ) );
		add_action( self::PAUSE_HOOK, array( __CLASS__, 'send_pause_summary' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'sync_schedule' ) );
	}

	/**
	 * Schedules the weekly summary for Monday 09:00 site time when on, and clears it when off.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function sync_schedule() {
		$wanted = false;
		foreach ( Karetaker_Routing::routes() as $channel => $route ) {
			if ( $route['weekly'] && Karetaker_Routing::connected( $channel ) ) {
				$wanted = true;
				break;
			}
		}
		$slot = 'fri17' === (string) Karetaker_Settings::get( 'weekly_slot' ) ? 'fri17' : 'mon09';
		$next = wp_next_scheduled( self::WEEKLY_HOOK );

		if ( $next && ( ! $wanted || get_option( 'karetaker_weekly_slot' ) !== $slot ) ) {
			wp_unschedule_hook( self::WEEKLY_HOOK );
			$next = false;
		}
		if ( ! $wanted || $next ) {
			return;
		}

		$when = new DateTimeImmutable( 'fri17' === $slot ? 'next friday 17:00' : 'next monday 09:00', wp_timezone() );
		wp_schedule_event( $when->getTimestamp(), 'weekly', self::WEEKLY_HOOK );
		update_option( 'karetaker_weekly_slot', $slot, false );
	}

	/**
	 * Sends the weekly summary to every channel whose weekly rule is on.
	 *
	 * @since 1.0.0
	 * @return bool True when at least one channel was sent to.
	 */
	public static function send_weekly() {
		if ( karetaker_is_disabled() && ! self::$capture ) {
			return false;
		}

		$routes = Karetaker_Routing::routes();
		$report = Karetaker_Report::build( 7 );
		$site   = (string) get_bloginfo( 'name' );
		$sent   = false;

		$grouped = array();
		foreach ( (array) $report['act_events'] as $event ) {
			$grouped[] = array( 'act', (string) $event['title'], (string) $event['time'], (string) $event['summary'] );
		}
		$grouped = self::group( $grouped );

		if ( 'act' === $report['verdict'] ) {
			$level = 'act';
			$n     = max( 1, count( $grouped ) );
			/* translators: %d: number of distinct problems */
			$title = sprintf( _n( '%d thing needs you', '%d things need you', $n, 'karetaker' ), $n );
			$lead  = __( 'Karetaker found problems on your site this week that should not wait. Start with the first one below.', 'karetaker' );
		} elseif ( 'attention' === $report['verdict'] ) {
			$level = 'review';
			$title = __( 'A few things are worth a look', 'karetaker' );
			$lead  = __( 'Nothing urgent this week, but a few changes deserve a minute when you have one.', 'karetaker' );
		} else {
			$level = 'safe';
			$title = __( 'All quiet this week', 'karetaker' );
			$lead  = __( 'Nothing needed you this week. Karetaker kept watching in the background.', 'karetaker' );
		}
		$subject = Karetaker_Email::subject( '', __( 'Weekly summary', 'karetaker' ) . ': ' . $title );

		$counts = sprintf(
			/* translators: 1: checks, 2: act now count, 3: worth a look count */
			__( 'Checks run: %1$d · Act now: %2$d · Worth a look: %3$d', 'karetaker' ),
			(int) $report['checks'],
			(int) $report['act_count'],
			(int) $report['watch_count']
		);

		if ( $routes['email']['weekly'] || self::$capture ) {
			$events = array_slice( $grouped, 0, 10 );
			$args   = array(
				'level'   => $level,
				'eyebrow' => __( 'Weekly summary', 'karetaker' ),
				'title'   => $title,
				'lead'    => $lead,
				'meta'    => array(
					__( 'Site', 'karetaker' ) => Karetaker_Email::host(),
					__( 'Week', 'karetaker' ) => wp_date( 'M j', (int) $report['since_ts'] ) . ' – ' . wp_date( 'M j', (int) $report['until_ts'] ),
				),
				'stats'   => array(
					array( number_format_i18n( (int) $report['checks'] ), __( 'checks run', 'karetaker' ) ),
					array( number_format_i18n( (int) $report['act_count'] ), __( 'act now alerts', 'karetaker' ) ),
					array( number_format_i18n( (int) $report['watch_count'] ), __( 'worth a look', 'karetaker' ) ),
					array( (int) $report['protections'] . '/' . (int) $report['protections_all'], __( 'protections on', 'karetaker' ) ),
				),
				'events'  => $events,
				'buttons' => array(
					array( __( 'Open Karetaker', 'karetaker' ), admin_url( 'admin.php?page=karetaker' ) ),
					array( __( 'See all activity', 'karetaker' ), admin_url( 'admin.php?page=karetaker&tab=activity' ) ),
				),
				/* translators: 1: email address, 2: site host */
				'reason'  => sprintf( __( 'You get this weekly summary because %1$s is set to receive it for %2$s.', 'karetaker' ), Karetaker_Settings::alert_email(), Karetaker_Email::host() ),
			);
			$sent = self::send( $subject, $args, 'weekly', count( $events ) ) || $sent;
		}
		foreach ( array( 'telegram', 'slack', 'discord', 'teams' ) as $channel ) {
			if ( ! self::$capture && $routes[ $channel ]['weekly'] && Karetaker_Routing::connected( $channel ) ) {
				$sent = Karetaker_Chat::send_text( $channel, $subject . "\n" . $counts . "\n" . admin_url( 'admin.php?page=karetaker' ) ) || $sent;
			}
		}

		return $sent;
	}

	/**
	 * Sends one summary of Act now events that happened while alerts were paused.
	 *
	 * @since 1.0.0
	 * @param int  $from    Pause start (Unix time).
	 * @param int  $until   Pause end (Unix time).
	 * @param bool $preview Build it for a preview, ignoring routing.
	 * @return bool
	 */
	public static function send_pause_summary( $from, $until, $preview = false ) {
		$routes = Karetaker_Routing::routes();
		if ( ! $preview && ( karetaker_is_disabled() || ! Karetaker_Routing::connected( 'email' ) || ! ( $routes['email']['act'] || $routes['email']['review'] ) ) ) {
			return false;
		}

		$rows = Karetaker_Events::query(
			array(
				'min_severity' => $preview || $routes['email']['review'] ? Karetaker_Events::SEVERITY_ATTENTION : Karetaker_Events::SEVERITY_ACT,
				'since'        => gmdate( 'Y-m-d H:i:s', (int) $from ),
				'until'        => gmdate( 'Y-m-d H:i:s', (int) $until ),
				'limit'        => 25,
			)
		);
		if ( ! $rows ) {
			return false;
		}

		$events = array();
		$act    = false;
		foreach ( $rows as $row ) {
			$guide    = Karetaker_Guidance::for_event( (string) $row->event_code, $row->context );
			$is_act   = Karetaker_Events::SEVERITY_ACT === (int) $row->severity;
			$act      = $act || $is_act;
			$events[] = array(
				$is_act ? 'act' : 'review',
				'' !== $guide['title'] ? $guide['title'] : (string) $row->event_code,
				(string) $row->event_time,
				(string) $guide['summary'],
			);
		}

		$events = self::group( $events );
		/* translators: %d: number of distinct things */
		$title   = sprintf( _n( '%d thing happened while alerts were paused', '%d things happened while alerts were paused', count( $events ), 'karetaker' ), count( $events ) );
		$subject = Karetaker_Email::subject( $act ? 'act' : 'review', $title );

		return self::send(
			$subject,
			array(
				'level'   => $act ? 'act' : 'review',
				'eyebrow' => __( 'Pause summary', 'karetaker' ),
				'title'   => $title,
				'lead'    => __( 'Alerts were held while you worked on the site. Here is what Karetaker noticed in that time.', 'karetaker' ),
				'meta'    => array(
					__( 'Site', 'karetaker' )   => Karetaker_Email::host(),
					__( 'Paused', 'karetaker' ) => wp_date( (string) get_option( 'time_format' ), (int) $from ) . ' – ' . wp_date( (string) get_option( 'time_format' ), (int) $until ),
				),
				'events'  => $events,
				'buttons' => array(
					array( __( 'Open Karetaker', 'karetaker' ), admin_url( 'admin.php?page=karetaker' ) ),
					array( __( 'See all activity', 'karetaker' ), admin_url( 'admin.php?page=karetaker&tab=activity' ) ),
				),
				/* translators: 1: email address, 2: site host */
				'reason'  => sprintf( __( 'You get this because alerts for %2$s were paused and %1$s receives them.', 'karetaker' ), Karetaker_Settings::alert_email(), Karetaker_Email::host() ),
			),
			'pause',
			count( $events )
		);
	}

	/**
	 * When true, send() keeps the email instead of sending it (used by previews).
	 *
	 * @var bool
	 */
	private static $capture = false;

	/**
	 * The email kept by a preview.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $captured = null;

	/**
	 * Builds the weekly or pause summary without sending it.
	 *
	 * @since 1.0.0
	 * @param string $type weekly or pause.
	 * @return array{subject: string, args: array<string, mixed>}|null
	 */
	public static function preview( $type ) {
		self::$capture  = true;
		self::$captured = null;
		if ( 'weekly' === $type ) {
			self::send_weekly();
		} else {
			self::send_pause_summary( time() - HOUR_IN_SECONDS, time(), true );
		}
		self::$capture = false;
		return self::$captured;
	}

	/**
	 * Sends a summary email with the shared template and records it.
	 *
	 * @since 1.0.0
	 * @param string               $subject Subject line.
	 * @param array<string, mixed> $args    Template arguments.
	 * @param string               $kind    weekly or pause.
	 * @param int                  $count   Events listed.
	 * @return bool
	 */
	private static function send( $subject, array $args, $kind, $count ) {
		if ( self::$capture ) {
			self::$captured = array(
				'subject' => $subject,
				'args'    => $args,
			);
			return true;
		}
		$sent = Karetaker_Email::send( Karetaker_Settings::alert_email(), $subject, $args );
		if ( $sent ) {
			Karetaker_Events::record(
				'alerts_summary_sent',
				array(
					'kind'   => $kind,
					'events' => (int) $count,
				),
				0
			);
		}
		return $sent;
	}

	/**
	 * Merges repeats of the same event into one row with a count, newest first.
	 *
	 * @since 1.0.0
	 * @param array<int, array{0: string, 1: string, 2: string, 3: string}> $events Rows of [level, title, UTC time, summary], newest first.
	 * @return array<int, array{0: string, 1: string, 2: string, 3: string}> Rows with local times.
	 */
	private static function group( array $events ) {
		$rows = array();
		foreach ( $events as $event ) {
			$key = $event[1];
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array(
					'row'   => $event,
					'count' => 0,
				);
			}
			++$rows[ $key ]['count'];
			if ( 'act' === $event[0] ) {
				$rows[ $key ]['row'][0] = 'act';
			}
		}
		$out = array();
		foreach ( $rows as $item ) {
			$row    = $item['row'];
			$row[2] = self::local_time( $row[2] );
			if ( $item['count'] > 1 ) {
				/* translators: 1: event title, 2: number of times */
				$row[1] = sprintf( __( '%1$s (%2$d times)', 'karetaker' ), $row[1], $item['count'] );
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Local date and time for a stored UTC time.
	 *
	 * @since 1.0.0
	 * @param string $mysql_utc UTC datetime.
	 * @return string
	 */
	private static function local_time( $mysql_utc ) {
		return wp_date( 'M j, ' . get_option( 'time_format' ), (int) strtotime( $mysql_utc . ' UTC' ) );
	}

	/**
	 * Starts or ends an alert pause and schedules the catch-up summary.
	 *
	 * @since 1.0.0
	 * @param int $hours Pause length in hours; 0 resumes now.
	 * @return int Pause end time, or 0 when resumed.
	 */
	public static function set_pause( $hours ) {
		$previous_from  = (int) Karetaker_Settings::get( 'alerts_paused_from' );
		$was_paused     = Karetaker_Settings::alerts_paused_until();
		$previous_until = (int) Karetaker_Settings::get( 'alerts_paused_until' );

		if ( $previous_until ) {
			wp_unschedule_event( $previous_until, self::PAUSE_HOOK, array( $previous_from, $previous_until ) );
		}

		if ( $hours < 1 ) {
			Karetaker_Settings::update(
				array(
					'alerts_paused_until' => 0,
					'alerts_paused_from'  => 0,
				)
			);
			if ( $was_paused && $previous_from ) {
				self::send_pause_summary( $previous_from, time() );
			}
			return 0;
		}

		$from  = $was_paused && $previous_from ? $previous_from : time();
		$until = time() + $hours * HOUR_IN_SECONDS;
		Karetaker_Settings::update(
			array(
				'alerts_paused_until' => $until,
				'alerts_paused_from'  => $from,
			)
		);
		wp_schedule_single_event( $until, self::PAUSE_HOOK, array( $from, $until ) );

		return $until;
	}
}
