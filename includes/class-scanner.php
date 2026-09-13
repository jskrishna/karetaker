<?php
/**
 * Scheduled scans.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduled integrity and Guard scan runner.
 *
 * @since 0.1.0
 * @package Karetaker
 */
class Karetaker_Scanner {

	const STATE_OPTION = 'karetaker_scan_state';
	const CRON_HOOK    = 'karetaker_scan';

	const DEFAULT_BUDGET   = 15;
	const UPLOADS_PER_RUN  = 20000;
	const EXECUTABLE_REGEX = '/\.(php|php\d|phtml|phps|phar|pht|phtm|cgi|pl|py|sh|shtml)$/i';

	private static $started_at = 0;
	private static $budget     = self::DEFAULT_BUDGET;

	/**
	 * Registers the twice-daily scan cron callback.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Schedules the scan cron if it is not already scheduled.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'twicedaily', self::CRON_HOOK );
		}
	}

	/**
	 * Removes all scheduled scan cron events.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Returns the persisted scan state option.
	 *
	 * @since 0.1.0
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persists scan state without autoloading.
	 *
	 * @since 0.1.0
	 * @param array $state Scan state payload.
	 * @return void
	 */
	public static function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Whether the current run has exceeded its time budget.
	 *
	 * @since 0.1.0
	 * @return bool
	 */
	private static function out_of_time() {
		return ( microtime( true ) - self::$started_at ) > self::$budget;
	}

	/**
	 * Runs all scan slices within the time budget and records scan_ran.
	 *
	 * @since 0.1.0
	 * @param int|null $budget Optional seconds budget; defaults to DEFAULT_BUDGET.
	 * @return array
	 */
	public static function run( $budget = null ) {
		self::$started_at = microtime( true );
		self::$budget     = null === $budget ? self::DEFAULT_BUDGET : max( 1, (int) $budget );

		$state   = self::state();
		$results = array();

		foreach ( array( 'muplugins', 'uploads', 'cron', 'options', 'guard', 'checksums' ) as $scan ) {
			if ( self::out_of_time() ) {
				$results[ $scan ] = 'skipped_no_time';
				continue;
			}

			$method            = 'scan_' . $scan;
			$results[ $scan ]  = self::$method( $state );
		}

		$state['last_run']     = current_time( 'mysql', true );
		$state['last_results'] = $results;

		self::save_state( $state );

		Karetaker_Events::record(
			'scan_ran',
			array(
				'seconds' => round( microtime( true ) - self::$started_at, 2 ),
				'results' => wp_json_encode( $results ),
			),
			0
		);

		return $results;
	}

	/**
	 * Builds a relative-path => md5 map for every file under a directory.
	 *
	 * @since 0.1.0
	 * @param string $dir Absolute directory path.
	 * @return array<string, string>
	 */
	public static function hash_dir( $dir ) {
		$map = array();

		if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return $map;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $items as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}

			$path = $item->getPathname();
			$rel  = ltrim( str_replace( $dir, '', $path ), '/\\' );

			$map[ $rel ] = md5_file( $path );
		}

		ksort( $map );

		return $map;
	}

	/**
	 * Diffs must-use plugin file hashes against the previous baseline.
	 *
	 * @since 0.1.0
	 * @param array &$state Scan state (updated by reference).
	 * @return string
	 */
	public static function scan_muplugins( &$state ) {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			return 'no_dir';
		}

		$current  = self::hash_dir( WPMU_PLUGIN_DIR );
		$previous = isset( $state['muplugins'] ) && is_array( $state['muplugins'] ) ? $state['muplugins'] : null;

		$state['muplugins'] = $current;

		if ( null === $previous ) {
			return 'baseline_taken:' . count( $current );
		}

		$added   = array_diff_key( $current, $previous );
		$removed = array_diff_key( $previous, $current );
		$changed = array();

		foreach ( $current as $rel => $hash ) {
			if ( isset( $previous[ $rel ] ) && $previous[ $rel ] !== $hash ) {
				$changed[] = $rel;
			}
		}

		if ( ! $added && ! $removed && ! $changed ) {
			return 'clean:' . count( $current );
		}

		Karetaker_Events::record(
			'muplugin_changed',
			array(
				'added'   => array_keys( $added ),
				'removed' => array_keys( $removed ),
				'changed' => $changed,
			),
			0
		);

		return 'changed';
	}

	/**
	 * Walks uploads for executable extensions in budgeted chunks.
	 *
	 * @since 0.1.0
	 * @param array &$state Scan state (updated by reference).
	 * @return string
	 */
	public static function scan_uploads( &$state ) {
		$uploads = wp_get_upload_dir();
		$dir     = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return 'no_dir';
		}

		$offset  = isset( $state['uploads_offset'] ) ? (int) $state['uploads_offset'] : 0;
		$index   = 0;
		$checked = 0;
		$found   = array();
		$done    = true;

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $items as $item ) {
			++$index;

			if ( $index <= $offset ) {
				continue;
			}

			if ( $checked >= self::UPLOADS_PER_RUN || self::out_of_time() ) {
				$done = false;
				break;
			}

			++$checked;

			if ( ! $item->isFile() ) {
				continue;
			}

			$name = $item->getFilename();

			if ( preg_match( self::EXECUTABLE_REGEX, $name ) ) {
				$found[] = ltrim( str_replace( $dir, '', $item->getPathname() ), '/\\' );
			}
		}

		$state['uploads_offset'] = $done ? 0 : ( $offset + $checked );

		$known = isset( $state['uploads_found'] ) && is_array( $state['uploads_found'] ) ? $state['uploads_found'] : array();
		$fresh = array_values( array_diff( $found, $known ) );

		if ( $done ) {
			$state['uploads_found'] = $found;
		} else {
			$state['uploads_found'] = array_values( array_unique( array_merge( $known, $found ) ) );
		}

		if ( $fresh ) {
			Karetaker_Events::record(
				'uploads_php_found',
				array(
					'files' => array_slice( $fresh, 0, 10 ),
					'count' => count( $fresh ),
				),
				0
			);

			return 'found:' . count( $fresh );
		}

		return ( $done ? 'clean:' : 'partial:' ) . $checked;
	}

	/**
	 * Returns known core and plugin cron hook names treated as non-orphan.
	 *
	 * @since 0.1.0
	 * @return string[]
	 */
	public static function core_cron_hooks() {
		return array(
			'wp_version_check',
			'wp_update_plugins',
			'wp_update_themes',
			'wp_scheduled_delete',
			'wp_scheduled_auto_draft_delete',
			'delete_expired_transients',
			'wp_privacy_delete_old_export_files',
			'recovery_mode_clean_expired_keys',
			'wp_site_health_scheduled_check',
			'wp_https_detection',
			'wp_update_user_counts',
			'do_pings',
			'publish_future_post',
			'wp_delete_temp_updater_backups',
			'wp_attachment_delete_temp_files',
			self::CRON_HOOK,
		);
	}

	/**
	 * Detects orphan cron hooks confirmed across consecutive runs.
	 *
	 * @since 0.1.0
	 * @param array &$state Scan state (updated by reference).
	 * @return string
	 */
	public static function scan_cron( &$state ) {
		if ( ! function_exists( '_get_cron_array' ) ) {
			return 'unavailable';
		}

		$cron = _get_cron_array();

		if ( ! is_array( $cron ) ) {
			return 'unavailable';
		}

		$core    = self::core_cron_hooks();
		$orphans = array();

		foreach ( $cron as $events ) {
			if ( ! is_array( $events ) ) {
				continue;
			}

			foreach ( array_keys( $events ) as $hook ) {
				$hook = (string) $hook;

				if ( in_array( $hook, $core, true ) || has_action( $hook ) ) {
					continue;
				}

				$orphans[ $hook ] = true;
			}
		}

		$orphans  = array_keys( $orphans );
		$previous = isset( $state['cron_orphans'] ) && is_array( $state['cron_orphans'] ) ? $state['cron_orphans'] : array();

		$state['cron_orphans'] = $orphans;

		$confirmed = array_values( array_intersect( $orphans, $previous ) );
		$reported  = isset( $state['cron_reported'] ) && is_array( $state['cron_reported'] ) ? $state['cron_reported'] : array();
		$fresh     = array_values( array_diff( $confirmed, $reported ) );

		$state['cron_reported'] = $confirmed;

		if ( ! $fresh ) {
			return 'clean:' . count( $orphans ) . '_pending';
		}

		Karetaker_Events::record(
			'orphan_cron_found',
			array(
				'hooks' => array_slice( $fresh, 0, 10 ),
				'count' => count( $fresh ),
			),
			0
		);

		return 'found:' . count( $fresh );
	}

	/**
	 * Verifies WordPress.org checksums for core and active plugins within budget.
	 *
	 * @since 0.1.0
	 * @param array &$state Scan state (updated by reference).
	 * @return string
	 */
	public static function scan_checksums( &$state ) {
		$deadline = self::$started_at + self::$budget;
		$out      = array();

		$last_core = isset( $state['core_verified'] ) ? (int) $state['core_verified'] : 0;

		$core_offset = isset( $state['core_offset'] ) ? (int) $state['core_offset'] : 0;
		$core_carry  = isset( $state['core_carry'] ) && is_array( $state['core_carry'] ) ? $state['core_carry'] : array();
		$core_seen   = isset( $state['core_checked'] ) ? (int) $state['core_checked'] : 0;

		if ( $core_offset > 0 || ( time() - $last_core ) > DAY_IN_SECONDS ) {
			$result = Karetaker_Checksums::verify_core( $deadline, $core_offset, $core_carry, $core_seen );
			$out[]  = 'core:' . $result['status'] . ( 'partial' === $result['status'] ? ':' . $result['offset'] . '/' . $result['total'] : '' );

			if ( 'partial' === $result['status'] ) {
				$state['core_offset']  = (int) $result['offset'];
				$state['core_carry']   = $result['modified'];
				$state['core_checked'] = (int) $result['checked'];
			} elseif ( 'complete' === $result['status'] ) {
				$state['core_offset']   = 0;
				$state['core_carry']    = array();
				$state['core_checked']  = 0;
				$state['core_verified'] = time();
				Karetaker_Checksums::report( 'WordPress core', $result );
			}
		} else {
			$out[] = 'core:cached';
		}

		if ( ! function_exists( 'get_option' ) ) {
			return implode( ' ', $out );
		}

		$active = (array) get_option( 'active_plugins', array() );
		sort( $active );

		if ( ! $active ) {
			return implode( ' ', $out ) . ' plugins:none';
		}

		$cursor = isset( $state['plugin_cursor'] ) ? (int) $state['plugin_cursor'] : 0;
		$done   = 0;

		for ( $i = 0; $i < count( $active ) && $done < Karetaker_Checksums::PLUGINS_PER_RUN; $i++ ) {
			if ( self::out_of_time() ) {
				break;
			}

			$index  = ( $cursor + $i ) % count( $active );
			$plugin = $active[ $index ];

			$result = Karetaker_Checksums::verify_plugin( $plugin, $deadline );
			++$done;

			if ( 'complete' === $result['status'] ) {
				Karetaker_Checksums::report( $result['slug'], $result );
			}

			$out[] = Karetaker_Checksums::plugin_slug( $plugin ) . ':' . $result['status'];
		}

		$state['plugin_cursor'] = ( $cursor + $done ) % max( 1, count( $active ) );

		return implode( ' ', $out );
	}

	/**
	 * Option names monitored for unexpected value changes.
	 *
	 * @since 0.1.0
	 * @return string[]
	 */
	public static function watched_options() {
		return array( 'users_can_register', 'default_role', 'siteurl', 'home' );
	}

	/**
	 * Compares watched option effective values to the previous baseline.
	 *
	 * @since 0.1.0
	 * @param array &$state Scan state (updated by reference).
	 * @return string
	 */
	public static function scan_options( &$state ) {
		global $wpdb;

		$previous = isset( $state['options'] ) && is_array( $state['options'] ) ? $state['options'] : null;
		$current  = array();
		$masked   = array();

		foreach ( self::watched_options() as $name ) {
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$effective = get_option( $name );

			$current[ $name ] = (string) $effective;

			if ( null !== $raw && (string) $raw !== (string) $effective ) {
				$masked[ $name ] = array(
					'stored'    => (string) $raw,
					'effective' => (string) $effective,
				);
			}
		}

		$state['options']        = $current;
		$state['options_masked'] = $masked;

		if ( null === $previous ) {
			return 'baseline_taken';
		}

		$changed = array();

		foreach ( $current as $name => $value ) {
			if ( isset( $previous[ $name ] ) && $previous[ $name ] !== $value ) {
				$changed[ $name ] = array(
					'from' => $previous[ $name ],
					'to'   => $value,
				);
			}
		}

		if ( ! $changed ) {
			return 'clean';
		}

		Karetaker_Events::record(
			'option_changed',
			array(
				'options' => implode( ', ', array_keys( $changed ) ),
				'detail'  => wp_json_encode( $changed ),
			),
			0
		);

		return 'changed:' . count( $changed );
	}

	/**
	 * Runs Guard catastrophe checks as a scan slice.
	 *
	 * @since 0.1.0
	 * @param array &$state Scan state (updated by reference).
	 * @return string
	 */
	public static function scan_guard( &$state ) {
		return Karetaker_Guard::scan( $state );
	}
}
