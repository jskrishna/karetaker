<?php
/**
 * Issues: actionable events grouped into things a person can resolve.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds issues from Act now / Worth a look events and stores their status and owner.
 *
 * An issue is the newest event for a grouping key (usually the event code). Acknowledging or
 * resolving remembers the newest event id at that moment; a newer event with the same key
 * reopens the issue. Expected comes from Karetaker_Expected marks.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Issues {

	const OPTION        = 'karetaker_issue_state';
	const LOOKBACK_DAYS = 90;

	/**
	 * Area for an event code: files, users, plugins, settings, login, or scan.
	 *
	 * @since 1.0.0
	 * @param string $code Event code.
	 * @return string
	 */
	public static function area( $code ) {
		$map = array(
			'files'   => array( 'critical_file_changed', 'file_hash_mismatch', 'uploads_php_found', 'uploads_config_changed', 'muplugin_changed', 'db_script_found', 'script_domain_new', 'cloaking_suspected' ),
			'users'   => array( 'admin_user_added', 'role_escalated', 'admin_roster_changed', 'hidden_admin_found', 'user_created', 'user_deleted', 'registration_opened' ),
			'login'   => array( 'admin_login_new_ip', 'admin_login_new_device', 'login_failure_burst', 'user_enum_probe', 'user_login' ),
			'plugins' => array( 'hidden_plugin_found', 'silent_plugin_found', 'plugin_directory_closed', 'plugin_directory_abandoned', 'plugin_vuln_found', 'plugin_owner_changed', 'plugin_update_flagged', 'plugin_updated', 'plugin_activated', 'plugin_deactivated', 'theme_switched' ),
			'scan'    => array( 'scan_ran', 'incident_check_ran', 'alerts_summary_sent' ),
		);
		foreach ( $map as $area => $codes ) {
			if ( in_array( (string) $code, $codes, true ) ) {
				return $area;
			}
		}
		return 'settings';
	}

	/**
	 * Translated area label.
	 *
	 * @since 1.0.0
	 * @param string $area Area key.
	 * @return string
	 */
	public static function area_label( $area ) {
		$labels = array(
			'files'    => __( 'Files', 'karetaker' ),
			'users'    => __( 'Users', 'karetaker' ),
			'login'    => __( 'Login', 'karetaker' ),
			'plugins'  => __( 'Plugins', 'karetaker' ),
			'settings' => __( 'Settings', 'karetaker' ),
			'scan'     => __( 'Scan', 'karetaker' ),
		);
		return isset( $labels[ $area ] ) ? $labels[ $area ] : $labels['settings'];
	}

	/**
	 * Icon name used by the UI for an area.
	 *
	 * @since 1.0.0
	 * @param string $area Area key.
	 * @return string alert, user, file, plug, key, or check.
	 */
	public static function icon( $area ) {
		$map = array(
			'files'    => 'file',
			'users'    => 'user',
			'login'    => 'key',
			'plugins'  => 'plug',
			'scan'     => 'check',
			'settings' => 'alert',
		);
		return isset( $map[ $area ] ) ? $map[ $area ] : 'alert';
	}

	/**
	 * Plain-language "why it matters" for an event code.
	 *
	 * @since 1.0.0
	 * @param string $code  Event code.
	 * @param string $check Guard check for guard_tripped.
	 * @return string
	 */
	public static function why( $code, $check = '' ) {
		$map   = array(
			'admin_user_added'           => __( 'An extra administrator can do anything on your site, including creating more accounts and hiding them.', 'karetaker' ),
			'role_escalated'             => __( 'Raising an existing account to administrator is a quiet way to take over a site.', 'karetaker' ),
			'registration_opened'        => __( 'Open sign-ups let bots create accounts in bulk, and a wrong default role can hand them control.', 'karetaker' ),
			'muplugin_changed'           => __( 'Must-use plugins run on every page and cannot be switched off from the Plugins screen, so attackers like to hide code there.', 'karetaker' ),
			'uploads_php_found'          => __( 'The uploads folder should only hold media. Code there can often be run straight from the browser.', 'karetaker' ),
			'uploads_config_changed'     => __( 'An .htaccess or .user.ini file in uploads can switch on code execution for files that should be harmless.', 'karetaker' ),
			'file_hash_mismatch'         => __( 'Files that no longer match the official copy may contain added code that runs on every visit.', 'karetaker' ),
			'critical_file_changed'      => __( 'This file holds your database password and runs first. Attackers add hidden code here because most scanners skip it.', 'karetaker' ),
			'admin_roster_changed'       => __( 'Administrators added outside the dashboard usually mean someone wrote to the database directly.', 'karetaker' ),
			'hidden_admin_found'         => __( 'Attackers create hidden admins so they can come back later, even after you change your password.', 'karetaker' ),
			'hidden_plugin_found'        => __( 'A plugin that hides itself from the Plugins screen has no honest reason to do so.', 'karetaker' ),
			'silent_plugin_found'        => __( 'A plugin folder that appeared without being installed from the dashboard is a common way to plant a backdoor.', 'karetaker' ),
			'db_script_found'            => __( 'Scripts stored in widgets or settings run on every page, including checkout, and survive plugin updates.', 'karetaker' ),
			'option_denylist_hit'        => __( 'This setting name is used by known malware campaigns to store their code in the database.', 'karetaker' ),
			'option_name_suspect'        => __( 'Unusual autoloaded settings can be where injected code hides, though many are harmless.', 'karetaker' ),
			'script_domain_new'          => __( 'A new script source can read everything visitors type, including card details on checkout.', 'karetaker' ),
			'cloaking_suspected'         => __( 'Showing spam links only to search engines can get your site penalised or removed from search results.', 'karetaker' ),
			'plugin_update_flagged'      => __( 'Harmful code is often shipped inside a normal-looking update, so new outside connections deserve a look.', 'karetaker' ),
			'plugin_owner_changed'       => __( 'Plugins are sometimes bought to push harmful updates to every site that has them installed.', 'karetaker' ),
			'plugin_directory_closed'    => __( 'Closed plugins never get security updates, so any weakness stays open.', 'karetaker' ),
			'plugin_directory_abandoned' => __( 'Plugins nobody maintains stop getting fixes when new weaknesses are found.', 'karetaker' ),
			'plugin_vuln_found'          => __( 'Published weaknesses are scanned for by bots within days, so unpatched versions get attacked first.', 'karetaker' ),
			'user_enum_probe'            => __( 'Bots list your usernames first, then try passwords against them.', 'karetaker' ),
			'admin_login_new_ip'         => __( 'A sign-in from somewhere new can be a stolen password in use.', 'karetaker' ),
			'admin_login_new_device'     => __( 'A sign-in from a new browser or computer can be a stolen password in use.', 'karetaker' ),
			'orphan_cron_found'          => __( 'Scheduled tasks left behind by removed code can keep running malicious jobs.', 'karetaker' ),
			'option_changed'             => __( 'This setting changes how your site behaves for every visitor.', 'karetaker' ),
			'file_editor_used'           => __( 'Editing code from the dashboard is a favourite trick once someone has an admin login.', 'karetaker' ),
			'admin_email_changed'        => __( 'Whoever controls the admin email can reset passwords and receive security notices.', 'karetaker' ),
			'login_failure_burst'        => __( 'Many failed logins in a short time usually means a bot is guessing passwords.', 'karetaker' ),
		);
		$guard = array(
			'blog_public'         => __( 'While search engines are blocked, your site quietly drops out of search results.', 'karetaker' ),
			'mail_failed'         => __( 'When email fails, password resets, orders and Karetaker alerts never arrive.', 'karetaker' ),
			'admin_email_invalid' => __( 'Security notices and password resets go to the admin email, so it must reach a person.', 'karetaker' ),
			'no_administrator'    => __( 'Without an administrator nobody can manage the site, update plugins or remove bad accounts.', 'karetaker' ),
		);
		if ( 'guard_tripped' === $code ) {
			return isset( $guard[ $check ] ) ? $guard[ $check ] : '';
		}
		return isset( $map[ $code ] ) ? $map[ $code ] : '';
	}

	/**
	 * Grouping key for an event row.
	 *
	 * @since 1.0.0
	 * @param object $row Hydrated event row.
	 * @return string
	 */
	public static function key( $row ) {
		$code    = (string) $row->event_code;
		$context = is_array( $row->context ) ? $row->context : array();
		if ( 'guard_tripped' === $code && ! empty( $context['check'] ) ) {
			return $code . ':' . sanitize_key( (string) $context['check'] );
		}
		if ( 0 === strpos( $code, 'plugin_' ) && ! empty( $context['slug'] ) ) {
			return $code . ':' . sanitize_title( (string) $context['slug'] );
		}
		return $code;
	}

	/**
	 * Stored status map.
	 *
	 * @since 1.0.0
	 * @return array<string, array<string, mixed>>
	 */
	private static function state() {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * All issues, newest first.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $args status (open|ack|resolved|expected|active|all), area, owner.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( array $args = array() ) {
		$args   = wp_parse_args(
			$args,
			array(
				'status' => 'all',
				'area'   => '',
				'owner'  => '',
			)
		);
		$rows   = Karetaker_Events::query(
			array(
				'min_severity' => Karetaker_Events::SEVERITY_ATTENTION,
				'since'        => gmdate( 'Y-m-d H:i:s', time() - self::LOOKBACK_DAYS * DAY_IN_SECONDS ),
				'limit'        => 500,
			)
		);
		$marked = Karetaker_Expected::marked_ids();
		$state  = self::state();
		$groups = array();
		foreach ( $rows as $row ) {
			$groups[ self::key( $row ) ][] = $row;
		}

		$out = array();
		foreach ( $groups as $key => $events ) {
			$issue = self::build( $key, $events, $state, $marked );
			if ( 'all' !== $args['status'] ) {
				if ( 'active' === $args['status'] && ! in_array( $issue['status'], array( 'open', 'ack' ), true ) ) {
					continue;
				}
				if ( 'active' !== $args['status'] && $issue['status'] !== $args['status'] ) {
					continue;
				}
			}
			if ( '' !== $args['area'] && $issue['area'] !== $args['area'] ) {
				continue;
			}
			if ( '' !== (string) $args['owner'] && (string) $issue['owner'] !== (string) $args['owner'] ) {
				continue;
			}
			$out[] = $issue;
		}

		usort(
			$out,
			static function ( $a, $b ) {
				$rank = array(
					'open'     => 0,
					'ack'      => 1,
					'expected' => 2,
					'resolved' => 3,
				);
				if ( $rank[ $a['status'] ] !== $rank[ $b['status'] ] ) {
					return $rank[ $a['status'] ] - $rank[ $b['status'] ];
				}
				if ( $a['sev'] !== $b['sev'] ) {
					return 'act' === $a['sev'] ? -1 : 1;
				}
				return $b['id'] - $a['id'];
			}
		);

		return $out;
	}

	/**
	 * One issue by its newest event id.
	 *
	 * @since 1.0.0
	 * @param int $event_id Event id.
	 * @return array<string, mixed>|null
	 */
	public static function find( $event_id ) {
		foreach ( self::all() as $issue ) {
			if ( (int) $issue['id'] === (int) $event_id || in_array( (int) $event_id, $issue['event_ids'], true ) ) {
				return $issue;
			}
		}
		return null;
	}

	/**
	 * Builds one issue from its events (newest first).
	 *
	 * @since 1.0.0
	 * @param string                              $key    Grouping key.
	 * @param object[]                            $events Events, newest first.
	 * @param array<string, array<string, mixed>> $state  Stored status map.
	 * @param array<int, true>                    $marked Marked-expected ids.
	 * @return array<string, mixed>
	 */
	private static function build( $key, array $events, array $state, array $marked ) {
		$newest  = $events[0];
		$context = is_array( $newest->context ) ? $newest->context : array();
		$code    = (string) $newest->event_code;
		$saved   = isset( $state[ $key ] ) && is_array( $state[ $key ] ) ? $state[ $key ] : array();
		$since   = isset( $saved['event_id'] ) ? (int) $saved['event_id'] : 0;

		if ( isset( $marked[ (int) $newest->id ] ) ) {
			$status = 'expected';
		} elseif ( $since >= (int) $newest->id && ! empty( $saved['status'] ) ) {
			$status = (string) $saved['status'];
		} else {
			$status = 'open';
		}

		$streak = array();
		foreach ( $events as $event ) {
			if ( 'open' === $status && $since && (int) $event->id <= $since ) {
				break;
			}
			$streak[] = $event;
		}
		if ( ! $streak ) {
			$streak = array( $newest );
		}
		$oldest   = $streak[ count( $streak ) - 1 ];
		$area     = self::area( $code );
		$guidance = Karetaker_Guidance::for_event( $code, $context );
		$owner    = isset( $saved['owner'] ) ? (int) $saved['owner'] : 0;
		$user     = $owner ? get_userdata( $owner ) : false;
		$view     = Karetaker_Admin_Data::event( $newest, $marked );

		return array(
			'id'         => (int) $newest->id,
			'key'        => $key,
			'code'       => $code,
			'sev'        => Karetaker_Events::SEVERITY_ACT === (int) $newest->severity ? 'act' : 'review',
			'status'     => $status,
			'owner'      => $owner,
			'owner_name' => $user ? (string) $user->user_login : '',
			'area'       => $area,
			'area_label' => self::area_label( $area ),
			'icon'       => self::icon( $area ),
			'title'      => '' !== $guidance['title'] ? $guidance['title'] : $code,
			'what'       => (string) $guidance['summary'],
			'why'        => self::why( $code, isset( $context['check'] ) ? (string) $context['check'] : '' ),
			'steps'      => array_values( (array) $guidance['steps'] ),
			'link'       => (string) $guidance['link'],
			'link_label' => (string) $guidance['link_label'],
			'opened'     => (string) $oldest->event_time,
			'opened_h'   => Karetaker_Admin_Data::when( (string) $oldest->event_time ),
			'last_h'     => Karetaker_Admin_Data::when( (string) $newest->event_time ),
			'count'      => count( $streak ),
			'event_ids'  => array_map( 'intval', wp_list_pluck( $events, 'id' ) ),
			'details'    => $view['details'],
			'who'        => $view['who'],
			'json'       => $view['json'],
			'can_mark'   => Karetaker_Expected::supports( $code ),
			'at'         => isset( $saved['at'] ) ? (int) $saved['at'] : 0,
		);
	}

	/**
	 * Related events for an issue's timeline: its own events plus other Act now events within 15 minutes.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $issue Issue.
	 * @return array<int, array{time: string, text: string, bad: bool}>
	 */
	public static function timeline( array $issue ) {
		$window = 15 * MINUTE_IN_SECONDS;
		$ts     = strtotime( $issue['opened'] . ' UTC' );
		$rows   = Karetaker_Events::query(
			array(
				'since' => gmdate( 'Y-m-d H:i:s', $ts - $window ),
				'until' => gmdate( 'Y-m-d H:i:s', $ts + $window ),
				'limit' => 50,
			)
		);
		$out    = array();
		foreach ( array_reverse( $rows ) as $row ) {
			$own = in_array( (int) $row->id, $issue['event_ids'], true );
			if ( ! $own && Karetaker_Events::SEVERITY_LOG === (int) $row->severity ) {
				continue;
			}
			$guide = Karetaker_Guidance::for_event( (string) $row->event_code, $row->context );
			$out[] = array(
				'time' => Karetaker_Admin_Data::when( (string) $row->event_time ),
				'text' => '' !== $guide['title'] ? $guide['title'] : (string) $row->event_code,
				'bad'  => Karetaker_Events::SEVERITY_ACT === (int) $row->severity,
			);
		}
		return $out;
	}

	/**
	 * Applies an action to an issue.
	 *
	 * @since 1.0.0
	 * @param int    $event_id Any event id belonging to the issue.
	 * @param string $action   ack, resolve, reopen, expected, or assign.
	 * @param int    $owner    Owner user id for assign.
	 * @return array<string, mixed>|WP_Error Updated issue.
	 */
	public static function act( $event_id, $action, $owner = 0 ) {
		$issue = self::find( $event_id );
		if ( ! $issue ) {
			return new WP_Error( 'karetaker_issue_missing', __( 'Issue not found.', 'karetaker' ) );
		}

		$state = self::state();
		$saved = isset( $state[ $issue['key'] ] ) && is_array( $state[ $issue['key'] ] ) ? $state[ $issue['key'] ] : array();

		switch ( $action ) {
			case 'expected':
				if ( ! $issue['can_mark'] ) {
					return new WP_Error( 'karetaker_issue_unsupported', __( 'This issue cannot be marked as expected.', 'karetaker' ) );
				}
				$result = Karetaker_Expected::mark( $issue['id'] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				break;
			case 'ack':
			case 'resolve':
				$saved['status']   = 'ack' === $action ? 'ack' : 'resolved';
				$saved['event_id'] = $issue['id'];
				$saved['at']       = time();
				break;
			case 'reopen':
				unset( $saved['status'], $saved['event_id'] );
				if ( 'expected' === $issue['status'] ) {
					Karetaker_Expected::unmark( $issue['id'] );
				}
				break;
			case 'assign':
				$saved['owner'] = (int) $owner;
				break;
			default:
				return new WP_Error( 'karetaker_issue_action', __( 'Unknown action.', 'karetaker' ) );
		}

		$state[ $issue['key'] ] = $saved;
		update_option( self::OPTION, $state, false );

		if ( 'expected' !== $action ) {
			Karetaker_Events::record(
				'issue_updated',
				array(
					'event_id' => $issue['id'],
					'code'     => $issue['code'],
					'action'   => $action,
					'owner'    => 'assign' === $action ? (int) $owner : null,
				)
			);
		}

		return self::find( $issue['id'] );
	}

	/**
	 * Counts of active issues by importance.
	 *
	 * @since 1.0.0
	 * @return array{act: int, review: int}
	 */
	public static function counts() {
		$counts = array(
			'act'    => 0,
			'review' => 0,
		);
		foreach ( self::all( array( 'status' => 'active' ) ) as $issue ) {
			++$counts[ $issue['sev'] ];
		}
		return $counts;
	}
}
