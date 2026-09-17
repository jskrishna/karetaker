<?php
/**
 * Overview watchtower verdict (Safe / Check this / Act now).
 *
 * @package Karetaker
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the Overview tower model from recent events and live Guard flags.
 *
 * @since 1.0.0
 */
class Karetaker_Tower {

	/**
	 * Event codes that affect the Files tier.
	 *
	 * @since 1.0.0
	 * @return string[]
	 */
	public static function files_codes() {
		return array(
			'critical_file_changed',
			'file_hash_mismatch',
			'uploads_php_found',
			'uploads_config_changed',
			'muplugin_changed',
			'db_script_found',
			'cloaking_suspected',
		);
	}

	/**
	 * Event codes that affect the People tier.
	 *
	 * @since 1.0.0
	 * @return string[]
	 */
	public static function people_codes() {
		return array(
			'admin_user_added',
			'role_escalated',
			'registration_opened',
			'admin_roster_changed',
			'hidden_admin_found',
			'admin_login_new_ip',
			'admin_login_new_device',
			'login_failure_burst',
			'user_enum_probe',
		);
	}

	/**
	 * Event codes that affect the Plugins & settings tier.
	 *
	 * @since 1.0.0
	 * @return string[]
	 */
	public static function plugins_codes() {
		return array(
			'hidden_plugin_found',
			'silent_plugin_found',
			'plugin_directory_closed',
			'plugin_directory_abandoned',
			'plugin_vuln_found',
			'plugin_owner_changed',
			'plugin_update_flagged',
			'option_denylist_hit',
			'option_name_suspect',
			'script_domain_new',
			'option_changed',
			'orphan_cron_found',
			'file_editor_used',
			'admin_email_changed',
			'guard_tripped',
		);
	}

	/**
	 * Builds display model for the Overview tower.
	 *
	 * @since 1.0.0
	 * @param array  $act_rows   ACT events (7d).
	 * @param array  $watch_rows Watch/ATTENTION events (7d).
	 * @param int    $bad_guard  Count of live bad Guard flags.
	 * @param string $last_run   UTC mysql datetime of last scan, or empty.
	 * @return array{
	 *   state:string,
	 *   verdict:string,
	 *   sub:string,
	 *   dots:array{files:string,people:string,plugins:string},
	 *   tiers:array{0:bool,1:bool,2:bool},
	 *   act_count:int,
	 *   watch_count:int
	 * }
	 */
	public static function model( array $act_rows, array $watch_rows, $bad_guard, $last_run ) {
		$bad_guard   = (int) $bad_guard;
		$act_rows    = self::unique_codes( $act_rows );
		$watch_rows  = self::unique_codes( $watch_rows );
		$act_count   = count( $act_rows );
		$watch_count = count( $watch_rows );

		$files   = self::bucket_level( $act_rows, $watch_rows, self::files_codes() );
		$people  = self::bucket_level( $act_rows, $watch_rows, self::people_codes() );
		$plugins = self::bucket_level( $act_rows, $watch_rows, self::plugins_codes() );

		if ( $bad_guard > 0 && 'act' !== $plugins ) {
			$plugins = 'act';
		}

		if ( $act_count > 0 || $bad_guard > 0 ) {
			$state = 'act';
		} elseif ( $watch_count > 0 ) {
			$state = 'check';
		} else {
			$state = 'safe';
		}

		$verdict = self::verdict_label( $state, $act_count );
		$sub     = self::verdict_sub( $state, $act_rows, $watch_rows, $last_run, $bad_guard );

		return array(
			'state'       => $state,
			'verdict'     => $verdict,
			'sub'         => $sub,
			'dots'        => array(
				'files'   => $files,
				'people'  => $people,
				'plugins' => $plugins,
			),
			'tiers'       => array(
				'act' === $files || 'check' === $files,
				'act' === $people || 'check' === $people,
				'act' === $plugins || 'check' === $plugins,
			),
			'act_count'   => $act_count,
			'watch_count' => $watch_count,
		);
	}

	/**
	 * Keeps the newest row per event code so repeats count once.
	 *
	 * @since 1.0.0
	 * @param array $rows Event rows, newest first.
	 * @return array
	 */
	private static function unique_codes( array $rows ) {
		$seen = array();
		$out  = array();
		foreach ( $rows as $row ) {
			$code = isset( $row->event_code ) ? (string) $row->event_code : '';
			if ( isset( $seen[ $code ] ) ) {
				continue;
			}
			$seen[ $code ] = true;
			$out[]         = $row;
		}
		return $out;
	}

	/**
	 * Highest signal for a bucket: act, check, or empty string (safe).
	 *
	 * @since 1.0.0
	 * @param array    $act_rows   ACT rows.
	 * @param array    $watch_rows Watch rows.
	 * @param string[] $codes      Codes in this bucket.
	 * @return string
	 */
	private static function bucket_level( array $act_rows, array $watch_rows, array $codes ) {
		$set = array_fill_keys( $codes, true );
		foreach ( $act_rows as $row ) {
			$code = isset( $row->event_code ) ? (string) $row->event_code : '';
			if ( isset( $set[ $code ] ) ) {
				return 'act';
			}
		}
		foreach ( $watch_rows as $row ) {
			$code = isset( $row->event_code ) ? (string) $row->event_code : '';
			if ( isset( $set[ $code ] ) ) {
				return 'check';
			}
		}
		return '';
	}

	/**
	 * Human headline for the tower state.
	 *
	 * @since 1.0.0
	 * @param string $state     safe|check|act.
	 * @param int    $act_count ACT count.
	 * @return string
	 */
	private static function verdict_label( $state, $act_count ) {
		if ( 'act' === $state ) {
			$n = max( 1, (int) $act_count );
			return sprintf(
				/* translators: %d: number of act-now events */
				_n( 'Act now: %d thing needs you', 'Act now: %d things need you', $n, 'karetaker' ),
				$n
			);
		}
		if ( 'check' === $state ) {
			return __( 'Worth a look', 'karetaker' );
		}
		return __( 'All quiet', 'karetaker' );
	}

	/**
	 * Supporting sentence under the tower headline.
	 *
	 * @since 1.0.0
	 * @param string $state      safe|check|act.
	 * @param array  $act_rows   ACT rows.
	 * @param array  $watch_rows Watch rows.
	 * @param string $last_run   Last scan UTC.
	 * @param int    $bad_guard  Bad guard count.
	 * @return string
	 */
	private static function verdict_sub( $state, array $act_rows, array $watch_rows, $last_run, $bad_guard ) {
		if ( 'act' === $state ) {
			if ( $bad_guard > 0 && ! $act_rows ) {
				return __( 'A Guard flag needs a human check (search visibility, mail, admin email, or administrators).', 'karetaker' );
			}
			$bits = array();
			foreach ( array_slice( $act_rows, 0, 2 ) as $row ) {
				$code = isset( $row->event_code ) ? (string) $row->event_code : '';
				$g    = Karetaker_Guidance::for_event( $code, isset( $row->context ) && is_array( $row->context ) ? $row->context : array() );
				if ( ! empty( $g['title'] ) ) {
					$bits[] = (string) $g['title'];
				}
			}
			if ( $bits ) {
				return implode( ' · ', $bits );
			}
			return __( 'Open Activity for the act-now events from the last week.', 'karetaker' );
		}

		if ( 'check' === $state ) {
			$row = isset( $watch_rows[0] ) ? $watch_rows[0] : null;
			if ( $row ) {
				$code = isset( $row->event_code ) ? (string) $row->event_code : '';
				$g    = Karetaker_Guidance::for_event( $code, isset( $row->context ) && is_array( $row->context ) ? $row->context : array() );
				if ( ! empty( $g['summary'] ) ) {
					return (string) $g['summary'];
				}
				if ( ! empty( $g['title'] ) ) {
					return (string) $g['title'];
				}
			}
			return __( 'Nothing urgent, but something in the last week is worth a look.', 'karetaker' );
		}

		if ( '' === $last_run ) {
			return __( 'Nothing needs you right now. Run a scan to take the first baseline.', 'karetaker' );
		}
		$ago = human_time_diff( strtotime( $last_run . ' UTC' ), time() );
		return sprintf(
			/* translators: %s: human time diff */
			__( 'Nothing needs you right now. Karetaker last checked about %s ago.', 'karetaker' ),
			$ago
		);
	}
}
