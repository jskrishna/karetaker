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
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Scanner {

	const STATE_OPTION = 'karetaker_scan_state';
	const CRON_HOOK    = 'karetaker_scan';

	const DEFAULT_BUDGET   = 15;
	const UPLOADS_PER_RUN  = 20000;
	const EXECUTABLE_REGEX = '/\.(php|php\d+|phtml|phps|phar|pht|phtm|cgi|pl|py|sh|shtml)$/i';
	const UPLOADS_DOTFILES = array( '.htaccess', '.user.ini' );

	/**
	 * Microtime when the current run started.
	 *
	 * @var float
	 */
	private static $started_at = 0;

	/**
	 * Seconds budget for the current run.
	 *
	 * @var int
	 */
	private static $budget = self::DEFAULT_BUDGET;

	/**
	 * Registers the twice-daily scan cron callback.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		self::schedule();
	}

	/**
	 * Schedules the scan cron if it is not already scheduled.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persists scan state without autoloading.
	 *
	 * @since 1.0.0
	 * @param array $state Scan state payload.
	 * @return void
	 */
	public static function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Whether the current run has exceeded its time budget.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private static function out_of_time() {
		return ( microtime( true ) - self::$started_at ) > self::$budget;
	}

	/**
	 * Runs all scan slices within the time budget and records scan_ran.
	 *
	 * @since 1.0.0
	 * @param int|null $budget Optional seconds budget; defaults to DEFAULT_BUDGET.
	 * @return array
	 */
	public static function run( $budget = null ) {
		self::$started_at = microtime( true );
		self::$budget     = null === $budget ? self::DEFAULT_BUDGET : max( 1, (int) $budget );

		$state   = self::state();
		$results = array();

		foreach ( array( 'muplugins', 'uploads', 'cron', 'options', 'guard', 'checksums', 'critical_files', 'admin_roster', 'plugins', 'db_scripts', 'option_names', 'plugin_directory', 'vulns', 'scripts', 'cloaking' ) as $scan ) {
			if ( self::out_of_time() ) {
				$results[ $scan ] = 'skipped_no_time';
				continue;
			}

			$method           = 'scan_' . $scan;
			$results[ $scan ] = self::$method( $state );
		}

		$state['last_run']     = current_time( 'mysql', true );
		$state['last_results'] = $results;

		// Re-merge Guard flags written by concurrent requests during this long run.
		$latest = self::state();
		if ( isset( $latest['guard'] ) && is_array( $latest['guard'] ) ) {
			if ( ! isset( $state['guard'] ) || ! is_array( $state['guard'] ) ) {
				$state['guard'] = array();
			}
			foreach ( $latest['guard'] as $check => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				// Prefer live mail_failed (and any check we did not evaluate this run).
				if ( 'mail_failed' === $check || ! isset( $state['guard'][ $check ] ) ) {
					$state['guard'][ $check ] = $entry;
				}
			}
		}

		self::save_state( $state );

		Karetaker_Events::record(
			'scan_ran',
			array(
				'seconds' => round( microtime( true ) - self::$started_at, 2 ),
				'results' => wp_json_encode( $results ),
			)
		);

		return $results;
	}

	/**
	 * Builds a relative-path => md5 map for every file under a directory.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * Accumulates findings across chunks for one full cycle, then replaces the
	 * baseline so deleted files do not stick forever. Resume uses relative path
	 * order rather than a fragile file index.
	 *
	 * @since 1.0.0
	 * @param array &$state Scan state (updated by reference).
	 * @return string
	 */
	public static function scan_uploads( &$state ) {
		$uploads = wp_get_upload_dir();
		$dir     = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return 'no_dir';
		}

		$resume = isset( $state['uploads_resume'] ) ? (string) $state['uploads_resume'] : '';
		$cycle  = isset( $state['uploads_cycle'] ) && is_array( $state['uploads_cycle'] ) ? $state['uploads_cycle'] : array();

		if ( '' === $resume ) {
			$cycle = array();
		}

		$known   = isset( $state['uploads_found'] ) && is_array( $state['uploads_found'] ) ? $state['uploads_found'] : array();
		$checked = 0;
		$found   = array();
		$done    = true;
		$last    = $resume;

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $items as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}

			$rel = ltrim( str_replace( $dir, '', $item->getPathname() ), '/\\' );

			if ( '' !== $resume && strcmp( $rel, $resume ) <= 0 ) {
				continue;
			}

			if ( $checked >= self::UPLOADS_PER_RUN || self::out_of_time() ) {
				$done = false;
				break;
			}

			++$checked;
			$last = $rel;

			$name = $item->getFilename();
			if ( self::is_uploads_risk_file( $name ) ) {
				$found[] = $rel;
			}
		}

		$cycle = array_values( array_unique( array_merge( $cycle, $found ) ) );
		$fresh = array_values( array_diff( $found, $known ) );

		$cycle_checked = ( '' === $resume ? 0 : (int) ( isset( $state['uploads_cycle_checked'] ) ? $state['uploads_cycle_checked'] : 0 ) ) + $checked;

		if ( $done ) {
			$state['uploads_checked']       = $cycle_checked;
			$state['uploads_cycle_checked'] = 0;
			$state['uploads_found']         = $cycle;
			$state['uploads_cycle']         = array();
			$state['uploads_resume']        = '';
			$state['uploads_offset']        = 0;
		} else {
			$state['uploads_cycle_checked'] = $cycle_checked;
			$state['uploads_cycle']         = $cycle;
			$state['uploads_resume']        = $last;
			$state['uploads_offset']        = 0;
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
	 * Whether an uploads basename looks executable or enables PHP execution.
	 *
	 * @since 1.0.0
	 * @param string $name Basename.
	 * @return bool
	 */
	public static function is_uploads_risk_file( $name ) {
		$name = (string) $name;
		if ( in_array( strtolower( $name ), self::UPLOADS_DOTFILES, true ) ) {
			return true;
		}

		return (bool) preg_match( self::EXECUTABLE_REGEX, $name );
	}

	/**
	 * Returns known core and plugin cron hook names treated as non-orphan.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
				$state['core_offset']             = 0;
				$state['core_carry']              = array();
				$state['core_checked']            = 0;
				$state['core_verified']           = time();
				list( $alertable, $host_managed ) = Karetaker_Checksums::split_modified( 'WordPress core', $result['modified'], $state );
				$state['core_stats']              = array(
					'checked'      => (int) $result['checked'],
					'modified'     => count( array_diff( $alertable, (array) $result['unknown'] ) ),
					'unknown'      => count( array_intersect( $alertable, (array) $result['unknown'] ) ),
					'host_managed' => count( $host_managed ),
				);
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

		$cursor       = isset( $state['plugin_cursor'] ) ? (int) $state['plugin_cursor'] : 0;
		$done         = 0;
		$active_count = count( $active );

		for ( $i = 0; $i < $active_count && $done < Karetaker_Checksums::PLUGINS_PER_RUN; $i++ ) {
			if ( self::out_of_time() ) {
				break;
			}

			$index  = ( $cursor + $i ) % $active_count;
			$plugin = $active[ $index ];

			$result = Karetaker_Checksums::verify_plugin( $plugin, $deadline );
			++$done;

			if ( ! isset( $state['plugin_verified'] ) || ! is_array( $state['plugin_verified'] ) ) {
				$state['plugin_verified'] = array();
			}
			$verified_slug = Karetaker_Checksums::plugin_slug( $plugin );
			if ( 'complete' === $result['status'] ) {
				list( $alertable )                          = Karetaker_Checksums::split_modified( $result['slug'], $result['modified'], $state );
				$state['plugin_verified'][ $verified_slug ] = array(
					'status'  => $alertable ? 'modified' : 'verified',
					'version' => isset( $result['version'] ) ? (string) $result['version'] : '',
					'at'      => time(),
				);
				Karetaker_Checksums::report( $result['slug'], $result );
			} elseif ( 'unavailable' === $result['status'] ) {
				$state['plugin_verified'][ $verified_slug ] = array(
					'status' => 'unavailable',
					'at'     => time(),
				);
			}

			$out[] = Karetaker_Checksums::plugin_slug( $plugin ) . ':' . $result['status'];
		}

		$state['plugin_cursor'] = ( $cursor + $done ) % max( 1, count( $active ) );

		return implode( ' ', $out );
	}

	/**
	 * Option names monitored for unexpected value changes.
	 *
	 * @since 1.0.0
	 * @return string[]
	 */
	public static function watched_options() {
		return array( 'users_can_register', 'default_role', 'siteurl', 'home' );
	}

	/**
	 * Compares watched option effective values to the previous baseline.
	 *
	 * @since 1.0.0
	 * @param array &$state Scan state (updated by reference).
	 * @return string
	 */
	public static function scan_options( &$state ) {
		global $wpdb;

		$previous = isset( $state['options'] ) && is_array( $state['options'] ) ? $state['options'] : null;
		$current  = array();
		$masked   = array();

		foreach ( self::watched_options() as $name ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the stored option value straight from the database on purpose: get_option() returns the filtered value, and the difference is what this check looks for. Caching it would hide a change.
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

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
	 * @since 1.0.0
	 * @param array &$state Scan state (updated by reference).
	 * @return string
	 */
	public static function scan_guard( &$state ) {
		return Karetaker_Guard::scan( $state );
	}

	/**
	 * Relative paths watched for critical-file fingerprint changes.
	 *
	 * @since 1.0.0
	 * @return string[] Paths relative to ABSPATH, plus virtual keys for wp-config candidates.
	 */
	public static function critical_file_paths() {
		$paths = array(
			'index.php',
			'wp-settings.php',
			'.htaccess',
			'.user.ini',
		);

		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			$paths[] = 'wp-config.php';
		} elseif ( file_exists( dirname( ABSPATH ) . '/wp-config.php' ) ) {
			$paths[] = '../wp-config.php';
		}

		return $paths;
	}

	/**
	 * Absolute path for a relative critical-file key.
	 *
	 * @since 1.0.0
	 * @param string $rel Relative path key.
	 * @return string Absolute path or empty.
	 */
	public static function absolute_from_relative( $rel ) {
		$rel = str_replace( '\\', '/', (string) $rel );
		if ( '' === $rel || false !== strpos( $rel, "\0" ) ) {
			return '';
		}
		if ( 0 === strpos( $rel, '../' ) ) {
			return dirname( ABSPATH ) . '/' . substr( $rel, 3 );
		}
		return ABSPATH . ltrim( $rel, '/' );
	}

	/**
	 * Fingerprint for a relative path: size|mtime|md5 (content never stored).
	 *
	 * @since 1.0.0
	 * @param string $rel Relative path.
	 * @return string|null Null when missing/unreadable.
	 */
	public static function fingerprint_relative( $rel ) {
		$abs = self::absolute_from_relative( $rel );
		if ( '' === $abs || ! is_file( $abs ) || ! is_readable( $abs ) ) {
			return null;
		}

		$size  = filesize( $abs );
		$mtime = filemtime( $abs );
		$hash  = md5_file( $abs );
		if ( false === $size || false === $mtime || false === $hash ) {
			return null;
		}

		return (string) $size . '|' . (string) $mtime . '|' . $hash;
	}

	/**
	 * Watches critical root files and uploads .htaccess / .user.ini fingerprints.
	 *
	 * @since 1.0.0
	 * @param array &$state Scan state.
	 * @return string
	 */
	public static function scan_critical_files( &$state ) {
		$current = array();

		foreach ( self::critical_file_paths() as $rel ) {
			$fp = self::fingerprint_relative( $rel );
			if ( null !== $fp ) {
				$current[ $rel ] = $fp;
			}
		}

		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';
		if ( '' !== $base && is_dir( $base ) ) {
			$items = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $items as $item ) {
				if ( ! $item->isFile() ) {
					continue;
				}
				$name = strtolower( $item->getFilename() );
				if ( ! in_array( $name, self::UPLOADS_DOTFILES, true ) ) {
					continue;
				}
				$rel   = 'uploads:' . ltrim( str_replace( $base, '', $item->getPathname() ), '/\\' );
				$size  = $item->getSize();
				$mtime = $item->getMTime();
				$hash  = md5_file( $item->getPathname() );
				if ( false === $hash ) {
					continue;
				}
				$current[ $rel ] = (string) $size . '|' . (string) $mtime . '|' . $hash;
				if ( self::out_of_time() ) {
					break;
				}
			}
		}

		$previous                = isset( $state['critical_files'] ) && is_array( $state['critical_files'] ) ? $state['critical_files'] : null;
		$state['critical_files'] = $current;

		if ( null === $previous ) {
			return 'baseline_taken:' . count( $current );
		}

		$changed = array();
		foreach ( $current as $rel => $fp ) {
			if ( ! isset( $previous[ $rel ] ) || $previous[ $rel ] !== $fp ) {
				$changed[] = $rel;
			}
		}
		foreach ( $previous as $rel => $fp ) {
			if ( ! isset( $current[ $rel ] ) ) {
				$changed[] = $rel;
			}
		}
		$changed = array_values( array_unique( $changed ) );

		if ( ! $changed ) {
			return 'clean:' . count( $current );
		}

		$uploads_changed = array();
		$root_changed    = array();
		foreach ( $changed as $rel ) {
			if ( 0 === strpos( $rel, 'uploads:' ) ) {
				$uploads_changed[] = substr( $rel, 8 );
			} else {
				$root_changed[] = $rel;
			}
		}

		if ( $root_changed ) {
			Karetaker_Events::record(
				'critical_file_changed',
				array(
					'paths' => array_slice( $root_changed, 0, 10 ),
					'count' => count( $root_changed ),
				),
				0
			);
		}
		if ( $uploads_changed ) {
			Karetaker_Events::record(
				'uploads_config_changed',
				array(
					'files' => array_slice( $uploads_changed, 0, 10 ),
					'count' => count( $uploads_changed ),
					'paths' => array_map(
						static function ( $p ) {
							return 'uploads:' . $p;
						},
						array_slice( $uploads_changed, 0, 10 )
					),
				),
				0
			);
		}

		return 'changed:' . count( $changed );
	}

	/**
	 * SQL admin roster vs WP user list (hidden admins) and roster diffs.
	 *
	 * @since 1.0.0
	 * @param array &$state Scan state.
	 * @return string
	 */
	public static function scan_admin_roster( &$state ) {
		global $wpdb;

		$cap_key = $wpdb->get_blog_prefix() . 'capabilities';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- product reads usermeta roster.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.user_login, um.meta_value FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id WHERE um.meta_key = %s",
				$cap_key
			)
		);

		$sql_logins = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$caps = maybe_unserialize( $row->meta_value );
				if ( is_array( $caps ) && ! empty( $caps['administrator'] ) ) {
					$sql_logins[] = (string) $row->user_login;
				}
			}
		}
		sort( $sql_logins );
		$sql_logins = array_values( array_unique( $sql_logins ) );

		$wp_users  = get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'user_login' ),
			)
		);
		$wp_logins = array();
		foreach ( $wp_users as $user ) {
			$wp_logins[] = (string) $user->user_login;
		}
		sort( $wp_logins );
		$wp_logins = array_values( array_unique( $wp_logins ) );

		$hidden                = array_values( array_diff( $sql_logins, $wp_logins ) );
		$previous              = isset( $state['admin_roster'] ) && is_array( $state['admin_roster'] ) ? $state['admin_roster'] : null;
		$state['admin_roster'] = $sql_logins;

		if ( $hidden ) {
			Karetaker_Events::record(
				'hidden_admin_found',
				array(
					'logins' => array_slice( $hidden, 0, 10 ),
					'count'  => count( $hidden ),
					'roster' => $sql_logins,
				),
				0
			);
			return 'hidden:' . count( $hidden );
		}

		if ( null === $previous ) {
			return 'baseline_taken:' . count( $sql_logins );
		}

		$added   = array_values( array_diff( $sql_logins, $previous ) );
		$removed = array_values( array_diff( $previous, $sql_logins ) );
		if ( ! $added && ! $removed ) {
			return 'clean:' . count( $sql_logins );
		}

		Karetaker_Events::record(
			'admin_roster_changed',
			array(
				'added'   => $added,
				'removed' => $removed,
				'roster'  => $sql_logins,
			),
			0
		);

		return 'changed';
	}

	/**
	 * Hidden plugin folders vs get_plugins(), and silent new plugin dirs.
	 *
	 * @since 1.0.0
	 * @param array &$state Scan state.
	 * @return string
	 */
	public static function scan_plugins( &$state ) {
		if ( ! defined( 'WP_PLUGIN_DIR' ) || ! is_dir( WP_PLUGIN_DIR ) ) {
			return 'no_dir';
		}

		$listed       = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$listed_roots = array();
		foreach ( array_keys( $listed ) as $file ) {
			$parts                     = explode( '/', str_replace( '\\', '/', $file ) );
			$listed_roots[ $parts[0] ] = true;
		}

		$dirs    = array();
		$entries = scandir( WP_PLUGIN_DIR );
		if ( is_array( $entries ) ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = WP_PLUGIN_DIR . '/' . $entry;
				if ( is_dir( $path ) ) {
					$dirs[] = $entry;
				}
			}
		}
		sort( $dirs );

		$expected_hidden = isset( $state['plugin_expected_hidden'] ) && is_array( $state['plugin_expected_hidden'] ) ? $state['plugin_expected_hidden'] : array();
		$hidden          = array();
		foreach ( $dirs as $dir ) {
			if ( isset( $listed_roots[ $dir ] ) ) {
				continue;
			}
			if ( in_array( $dir, $expected_hidden, true ) ) {
				continue;
			}
			$hidden[] = $dir;
		}

		$previous             = isset( $state['plugin_dirs'] ) && is_array( $state['plugin_dirs'] ) ? $state['plugin_dirs'] : null;
		$state['plugin_dirs'] = $dirs;

		$status = 'clean:' . count( $dirs );

		if ( $hidden ) {
			Karetaker_Events::record(
				'hidden_plugin_found',
				array(
					'dirs'  => array_slice( $hidden, 0, 10 ),
					'count' => count( $hidden ),
				),
				0
			);
			$status = 'hidden:' . count( $hidden );
		}

		if ( null === $previous ) {
			return 'baseline_taken:' . count( $dirs );
		}

		$fresh  = array_values( array_diff( $dirs, $previous ) );
		$silent = array();
		foreach ( $fresh as $dir ) {
			if ( isset( $listed_roots[ $dir ] ) || in_array( $dir, $expected_hidden, true ) ) {
				// Still silent if folder appeared without going through WP install UX.
				$silent[] = $dir;
			} elseif ( ! in_array( $dir, $hidden, true ) ) {
				$silent[] = $dir;
			} else {
				$silent[] = $dir;
			}
		}
		// Silent = new directory this scan that was not in previous baseline.
		$silent = array_values( array_unique( $fresh ) );
		if ( $silent ) {
			Karetaker_Events::record(
				'silent_plugin_found',
				array(
					'dirs'  => array_slice( $silent, 0, 10 ),
					'count' => count( $silent ),
				),
				0
			);
			$status = 'silent:' . count( $silent );
		}

		return $status;
	}

	/**
	 * High-signal patterns that often mean PHP executed from option/widget storage.
	 *
	 * Not a malware signature feed: fixed, local heuristics only.
	 *
	 * @since 1.0.0
	 * @return string Regex (without delimiters).
	 */
	public static function db_script_pattern() {
		return '(<\?php|eval\s*\(|assert\s*\(|base64_decode\s*\(|gzinflate\s*\(|str_rot13\s*\(|create_function\s*\(|shell_exec\s*\(|passthru\s*\(|preg_replace\s*\([^\)]*\/e|\\$_(?:GET|POST|REQUEST|COOKIE)\s*\[)';
	}

	/**
	 * Whether an option name is in the high-risk storage families we inspect.
	 *
	 * @since 1.0.0
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_db_script_option_name( $name ) {
		$name = (string) $name;
		if ( 0 === strpos( $name, 'widget_' ) ) {
			return true;
		}
		if ( 0 === strpos( $name, 'theme_mods_' ) ) {
			return true;
		}
		$needles = array( 'snippet', 'wpcode', 'code_snippets', 'as_pb_', 'css_js_', 'custom_css', 'custom_html', 'fluid_checkout', 'checkout' );
		$lower   = strtolower( $name );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $lower, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Scans selected options for script-like payloads (widgets, snippets, checkout-related).
	 *
	 * @since 1.0.0
	 * @param array &$state Scan state.
	 * @return string
	 */
	public static function scan_db_scripts( &$state ) {
		global $wpdb;

		$pattern = '/' . self::db_script_pattern() . '/i';
		$found   = array();
		$checked = 0;
		$limit   = 400;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- product integrity scan.
		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on') OR option_name LIKE 'widget\\_%' OR option_name LIKE 'theme_mods\\_%' OR option_name LIKE '%snippet%' OR option_name LIKE '%wpcode%' OR option_name LIKE '%checkout%' LIMIT 2000"
		);

		if ( ! is_array( $rows ) ) {
			return 'unavailable';
		}

		foreach ( $rows as $row ) {
			if ( $checked >= $limit || self::out_of_time() ) {
				break;
			}
			$name = (string) $row->option_name;
			$val  = (string) $row->option_value;
			++$checked;

			$focus = self::is_db_script_option_name( $name );
			if ( $focus ) {
				if ( ! preg_match( $pattern, $val ) ) {
					continue;
				}
			} elseif ( ! preg_match( '/(<\?php|eval\s*\(|assert\s*\(|shell_exec\s*\(|passthru\s*\(|create_function\s*\(|preg_replace\s*\([^\)]*\/e)/i', $val ) ) {
				continue;
			}

			$id           = md5( $name . '|' . substr( $val, 0, 2000 ) );
			$found[ $id ] = array(
				'option' => $name,
				'hint'   => self::db_script_hint( $val ),
			);
		}

		$previous            = isset( $state['db_scripts'] ) && is_array( $state['db_scripts'] ) ? $state['db_scripts'] : null;
		$state['db_scripts'] = $found;

		if ( null === $previous ) {
			return 'baseline_taken:' . count( $found );
		}

		$fresh_ids = array_values( array_diff( array_keys( $found ), array_keys( $previous ) ) );
		if ( ! $fresh_ids ) {
			return 'clean:' . count( $found );
		}

		$fresh = array();
		foreach ( array_slice( $fresh_ids, 0, 10 ) as $id ) {
			$fresh[] = $found[ $id ];
		}

		Karetaker_Events::record(
			'db_script_found',
			array(
				'count'   => count( $fresh_ids ),
				'options' => wp_json_encode( $fresh ),
				'ids'     => array_slice( $fresh_ids, 0, 10 ),
			),
			0
		);

		return 'found:' . count( $fresh_ids );
	}

	/**
	 * Exact option names associated with known DB malware campaigns.
	 *
	 * @since 1.0.0
	 * @return string[]
	 */
	public static function option_denylist() {
		return array(
			'_hdra_core',               // Documented campaign implant option.
			'global_wordpress_setting', // Documented campaign implant option.
		);
	}

	/**
	 * Whether an option name is framework noise (never heuristic-suspect).
	 *
	 * @since 1.0.0
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function option_name_is_framework_noise( $name ) {
		$name = (string) $name;
		if ( 0 === strpos( $name, '_transient_' ) || 0 === strpos( $name, '_site_transient_' ) ) {
			return true;
		}
		if ( 0 === strpos( $name, 'karetaker_' ) ) {
			return true;
		}
		if ( 'cron' === $name ) {
			return true;
		}
		return false;
	}

	/**
	 * Finite core / WP option names that are never heuristic-suspect when new.
	 *
	 * @since 1.0.0
	 * @return string[]
	 */
	public static function option_name_core_allowlist() {
		return array(
			'siteurl',
			'home',
			'blogname',
			'blogdescription',
			'users_can_register',
			'admin_email',
			'start_of_week',
			'use_balanceTags',
			'default_role',
			'WPLANG',
			'timezone_string',
			'gmt_offset',
			'date_format',
			'time_format',
			'links_updated_date_format',
			'permalink_structure',
			'category_base',
			'tag_base',
			'rewrite_rules',
			'active_plugins',
			'uninstall_plugins',
			'template',
			'stylesheet',
			'current_theme',
			'theme_switched',
			'sidebars_widgets',
			'cron',
			'default_category',
			'default_comment_status',
			'default_ping_status',
			'default_pingback_flag',
			'comment_moderation',
			'moderation_notify',
			'site_icon',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'blog_public',
			'uploads_use_yearmonth_folders',
			'upload_path',
			'upload_url_path',
			'blog_charset',
			'recently_edited',
			'can_compress_scripts',
			'auto_core_update_notified',
			'finished_updating_core_assets',
			'finished_updating_plugin_assets',
			'finished_updating_theme_assets',
		);
	}

	/**
	 * True if name equals or is prefixed by an active plugin dir or theme slug.
	 *
	 * @since 1.0.0
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function option_name_has_active_prefix( $name ) {
		$name     = (string) $name;
		$prefixes = array();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			$plugin = (string) $plugin;
			$dir    = false !== strpos( $plugin, '/' ) ? dirname( $plugin ) : preg_replace( '/\.php$/', '', $plugin );
			$dir    = sanitize_title( (string) $dir );
			if ( '' !== $dir ) {
				$prefixes[ $dir ] = true;
			}
		}

		foreach ( array( 'stylesheet', 'template' ) as $key ) {
			$style = get_option( $key );
			if ( is_string( $style ) && '' !== $style ) {
				$prefixes[ sanitize_title( $style ) ] = true;
			}
		}

		foreach ( array_keys( $prefixes ) as $prefix ) {
			if ( $name === $prefix || 0 === strpos( $name, $prefix . '_' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Denylist hits (ACT) plus new suspect autoload option names (Watch).
	 *
	 * @since 1.0.0
	 * @param array $state Scan state (updated by reference).
	 * @return string
	 */
	public static function scan_option_names( &$state ) {
		global $wpdb;

		$parts = array();

		$denylist  = self::option_denylist();
		$expected  = isset( $state['option_denylist_expected'] ) && is_array( $state['option_denylist_expected'] ) ? $state['option_denylist_expected'] : array();
		$reported  = isset( $state['option_denylist_reported'] ) && is_array( $state['option_denylist_reported'] ) ? $state['option_denylist_reported'] : array();
		$found_bad = array();

		if ( $denylist ) {
			$placeholders = implode( ',', array_fill( 0, count( $denylist ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders from count().
			$sql = "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ($placeholders)";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above. Option names are read straight from the database so a hidden or filtered option still shows up.
			$rows = $wpdb->get_col( $wpdb->prepare( $sql, $denylist ) );
			foreach ( (array) $rows as $hit ) {
				$hit = (string) $hit;
				if ( in_array( $hit, $expected, true ) || in_array( $hit, $reported, true ) ) {
					continue;
				}
				$found_bad[] = $hit;
			}
		}

		if ( $found_bad ) {
			$state['option_denylist_reported'] = array_values( array_unique( array_merge( $reported, $found_bad ) ) );
			Karetaker_Events::record(
				'option_denylist_hit',
				array(
					'names' => array_slice( $found_bad, 0, 10 ),
					'count' => count( $found_bad ),
				),
				0
			);
			$parts[] = 'denylist:' . count( $found_bad );
		} else {
			$parts[] = 'denylist:0';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- scan slice; names only.
		$names = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on') LIMIT 2000"
		);
		$names = array_map( 'strval', (array) $names );

		$baseline = isset( $state['option_names_baseline'] ) && is_array( $state['option_names_baseline'] ) ? $state['option_names_baseline'] : null;
		if ( null === $baseline ) {
			$state['option_names_baseline'] = $names;
			$state['option_names_pending']  = array();
			$state['option_names_reported'] = array();
			$parts[]                        = 'baseline_taken:' . count( $names );
			return implode( ' ', $parts );
		}

		$core       = array_fill_keys( self::option_name_core_allowlist(), true );
		$denylist_m = array_fill_keys( $denylist, true );
		$pending    = isset( $state['option_names_pending'] ) && is_array( $state['option_names_pending'] ) ? $state['option_names_pending'] : array();
		$reported_h = isset( $state['option_names_reported'] ) && is_array( $state['option_names_reported'] ) ? $state['option_names_reported'] : array();
		$candidates = array();
		$absorb     = array();

		foreach ( $names as $name ) {
			if ( in_array( $name, $baseline, true ) ) {
				continue;
			}
			if ( self::option_name_is_framework_noise( $name ) || isset( $core[ $name ] ) || isset( $denylist_m[ $name ] ) ) {
				$absorb[] = $name;
				continue;
			}
			if ( self::option_name_has_active_prefix( $name ) ) {
				$absorb[] = $name;
				continue;
			}
			$candidates[] = $name;
		}

		if ( $absorb ) {
			$state['option_names_baseline'] = array_values( array_unique( array_merge( $baseline, $absorb ) ) );
			$baseline                       = $state['option_names_baseline'];
		}

		$confirmed                     = array_values( array_intersect( $candidates, $pending ) );
		$fresh                         = array_values( array_diff( $confirmed, $reported_h ) );
		$state['option_names_pending'] = $candidates;

		if ( $fresh ) {
			$state['option_names_reported'] = array_values( array_unique( array_merge( $reported_h, $fresh ) ) );
			Karetaker_Events::record(
				'option_name_suspect',
				array(
					'names' => array_slice( $fresh, 0, 10 ),
					'count' => count( $fresh ),
				),
				0
			);
			$parts[] = 'suspect:' . count( $fresh );
		} else {
			$parts[] = 'suspect:0_pending:' . count( $candidates );
		}

		unset( $baseline );

		return implode( ' ', $parts );
	}

	/**
	 * Short, non-payload hint naming which pattern matched.
	 *
	 * @since 1.0.0
	 * @param string $value Option value.
	 * @return string
	 */
	private static function db_script_hint( $value ) {
		$checks = array(
			'php_tag'       => '/<\?php/i',
			'eval'          => '/eval\s*\(/i',
			'base64_decode' => '/base64_decode\s*\(/i',
			'gzinflate'     => '/gzinflate\s*\(/i',
			'superglobal'   => '/\$_(?:GET|POST|REQUEST|COOKIE)\s*\[/i',
			'shell_exec'    => '/shell_exec\s*\(/i',
		);
		foreach ( $checks as $label => $re ) {
			if ( preg_match( $re, $value ) ) {
				return $label;
			}
		}
		return 'pattern';
	}

	/**
	 * Checks active plugins against the WordPress.org directory (closed / stale).
	 *
	 * Custom (non-.org) plugins often return not_found; those are ignored after first sight.
	 * "Sold" transfers are not reliably detectable via the public API, so they are out of scope.
	 *
	 * @since 1.0.0
	 * @param array &$state Scan state.
	 * @return string
	 */
	public static function scan_plugin_directory( &$state ) {
		$active = (array) get_option( 'active_plugins', array() );
		sort( $active );
		if ( ! $active ) {
			return 'none';
		}

		$cursor = isset( $state['directory_cursor'] ) ? (int) $state['directory_cursor'] : 0;
		$known  = isset( $state['plugin_directory'] ) && is_array( $state['plugin_directory'] ) ? $state['plugin_directory'] : array();
		$done   = 0;
		$count  = count( $active );
		$out    = array();

		for ( $i = 0; $i < $count && $done < Karetaker_Checksums::DIRECTORY_PER_RUN; $i++ ) {
			if ( self::out_of_time() ) {
				break;
			}

			$index  = ( $cursor + $i ) % $count;
			$file   = $active[ $index ];
			$slug   = Karetaker_Checksums::plugin_slug( $file );
			$result = Karetaker_Checksums::directory_status( $slug );
			++$done;

			if ( is_wp_error( $result ) ) {
				$out[] = $slug . ':error';
				continue;
			}

			$status         = isset( $result['status'] ) ? (string) $result['status'] : 'listed';
			$prev           = isset( $known[ $slug ]['status'] ) ? (string) $known[ $slug ]['status'] : '';
			$prev_author    = isset( $known[ $slug ]['author'] ) ? (string) $known[ $slug ]['author'] : '';
			$author         = isset( $result['author'] ) ? (string) $result['author'] : '';
			$known[ $slug ] = array(
				'status'       => $status,
				'author'       => '' !== $author ? $author : $prev_author,
				'at'           => time(),
				'last_updated' => isset( $result['last_updated'] ) ? (string) $result['last_updated'] : '',
				'closed_date'  => isset( $result['closed_date'] ) ? (string) $result['closed_date'] : '',
				'reason'       => isset( $result['reason'] ) ? (string) $result['reason'] : '',
				'days'         => isset( $result['days'] ) ? (int) $result['days'] : 0,
			);

			if ( '' !== $prev_author && '' !== $author && $prev_author !== $author ) {
				$known[ $slug ]['owner_changed'] = time();
				Karetaker_Events::record(
					'plugin_owner_changed',
					array(
						'slug' => $slug,
						'name' => isset( $result['name'] ) ? (string) $result['name'] : $slug,
						'from' => $prev_author,
						'to'   => $author,
					),
					0
				);
			} elseif ( isset( $state['plugin_directory'][ $slug ]['owner_changed'] ) && ( time() - (int) $state['plugin_directory'][ $slug ]['owner_changed'] ) < 90 * DAY_IN_SECONDS ) {
				$known[ $slug ]['owner_changed'] = (int) $state['plugin_directory'][ $slug ]['owner_changed'];
			}

			if ( 'closed' === $status && 'closed' !== $prev ) {
				Karetaker_Events::record(
					'plugin_directory_closed',
					array(
						'slug'        => $slug,
						'name'        => isset( $result['name'] ) ? (string) $result['name'] : $slug,
						'reason'      => isset( $result['reason'] ) ? (string) $result['reason'] : '',
						'closed_date' => isset( $result['closed_date'] ) ? (string) $result['closed_date'] : '',
					),
					0
				);
			} elseif ( 'abandoned' === $status && 'abandoned' !== $prev && 'closed' !== $prev ) {
				Karetaker_Events::record(
					'plugin_directory_abandoned',
					array(
						'slug'         => $slug,
						'name'         => isset( $result['name'] ) ? (string) $result['name'] : $slug,
						'days'         => isset( $result['days'] ) ? (int) $result['days'] : 0,
						'last_updated' => isset( $result['last_updated'] ) ? (string) $result['last_updated'] : '',
					),
					0
				);
			}

			$out[] = $slug . ':' . $status;
		}

		$state['plugin_directory'] = $known;
		$state['directory_cursor'] = ( $cursor + $done ) % max( 1, $count );

		return $out ? implode( ' ', $out ) : 'idle';
	}

	/**
	 * Opt-in vulnerability lookup against WPVulnerability (active plugins).
	 *
	 * @since 1.0.0
	 * @param array $state Scan state (by ref).
	 * @return string Summary.
	 */
	public static function scan_vulns( &$state ) {
		if ( ! Karetaker_Vulns::is_enabled() ) {
			return 'disabled';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );
		sort( $active );
		if ( ! $active ) {
			return 'idle';
		}

		$cursor = isset( $state['vuln_cursor'] ) ? (int) $state['vuln_cursor'] : 0;
		$known  = isset( $state['vulns'] ) && is_array( $state['vulns'] ) ? $state['vulns'] : array();
		$count  = count( $active );
		$out    = array();
		$done   = 0;

		for ( $i = 0; $i < $count && $done < Karetaker_Vulns::PLUGINS_PER_RUN; $i++ ) {
			if ( self::out_of_time() ) {
				break;
			}

			$index = ( $cursor + $i ) % $count;
			$file  = $active[ $index ];
			++$done;

			if ( ! isset( $all[ $file ] ) ) {
				$out[] = 'missing';
				continue;
			}

			$slug    = Karetaker_Checksums::plugin_slug( $file );
			$version = isset( $all[ $file ]['Version'] ) ? (string) $all[ $file ]['Version'] : '';
			$name    = isset( $all[ $file ]['Name'] ) ? (string) $all[ $file ]['Name'] : $slug;
			$result  = Karetaker_Vulns::fetch_plugin( $slug );

			if ( is_wp_error( $result ) ) {
				$out[] = $slug . ':error';
				continue;
			}

			$status = isset( $result['status'] ) ? (string) $result['status'] : 'unavailable';
			if ( 'ok' !== $status ) {
				$out[] = $slug . ':' . $status;
				continue;
			}

			$matches = Karetaker_Vulns::matching_vulns( $version, $result );
			$sig     = $matches ? md5( (string) wp_json_encode( $matches ) ) : '';

			if ( ! $matches ) {
				unset( $known[ $slug ] );
				$out[] = $slug . ':clean';
				continue;
			}

			$prev = isset( $known[ $slug ] ) ? (string) $known[ $slug ] : '';
			if ( $sig === $prev ) {
				$out[] = $slug . ':known';
				continue;
			}

			$known[ $slug ] = $sig;
			$first          = $matches[0];

			Karetaker_Events::record(
				'plugin_vuln_found',
				array(
					'slug'         => $slug,
					'plugin_file'  => $file,
					'name'         => $name,
					'version'      => $version,
					'vuln_uuid'    => $first['uuid'],
					'vuln_name'    => $first['name'],
					'cve'          => $first['cve'],
					'fixed_before' => $first['fixed'],
					'match_count'  => count( $matches ),
					'baseline'     => $sig,
					'source'       => 'wpvulnerability.net',
				),
				0
			);

			$out[] = $slug . ':vuln';
		}

		$state['vulns']       = $known;
		$state['vuln_cursor'] = ( $cursor + $done ) % max( 1, $count );

		return $out ? implode( ' ', $out ) : 'idle';
	}

	/**
	 * Baselines external script hosts from the front end and script-bearing options.
	 *
	 * First complete pass stores domains with no alert. Later new hosts are Watch
	 * (Worth a look) so checkout skimmers and injected CDNs show up without flooding.
	 *
	 * @since 1.0.0
	 * @param array $state Scan state (updated by reference).
	 * @return string
	 */
	public static function scan_scripts( &$state ) {
		if ( self::out_of_time() ) {
			return 'skipped_no_time';
		}

		$found = self::collect_script_hosts();
		$hosts = array_keys( $found );

		$state['script_sources'] = array();
		$first_seen              = isset( $state['script_first_seen'] ) && is_array( $state['script_first_seen'] ) ? $state['script_first_seen'] : array();
		foreach ( array_slice( $found, 0, 100, true ) as $host => $sources ) {
			$state['script_sources'][ $host ] = array_slice( array_values( array_unique( (array) $sources ) ), 0, 5 );
			if ( ! isset( $first_seen[ $host ] ) ) {
				$first_seen[ $host ] = time();
			}
		}
		$state['script_first_seen'] = array_slice( $first_seen, 0, 200, true );

		$baseline = isset( $state['script_domains'] ) && is_array( $state['script_domains'] ) ? $state['script_domains'] : null;
		if ( null === $baseline ) {
			$state['script_domains']          = $hosts;
			$state['script_domains_reported'] = array();
			return 'baseline_taken:' . count( $hosts );
		}

		$reported = isset( $state['script_domains_reported'] ) && is_array( $state['script_domains_reported'] ) ? $state['script_domains_reported'] : array();
		$fresh    = array();

		foreach ( $found as $host => $sources ) {
			if ( in_array( $host, $baseline, true ) || in_array( $host, $reported, true ) ) {
				continue;
			}
			$fresh[ $host ] = $sources;
		}

		// Absorb hosts that disappeared from the live set back into baseline quietly.
		$state['script_domains'] = array_values( array_unique( array_merge( $baseline, $hosts ) ) );

		if ( ! $fresh ) {
			return 'clean:' . count( $hosts );
		}

		$names                            = array_keys( $fresh );
		$state['script_domains_reported'] = array_values( array_unique( array_merge( $reported, $names ) ) );

		$sample = array();
		foreach ( array_slice( $names, 0, 10 ) as $host ) {
			$sample[] = array(
				'host'    => $host,
				'sources' => isset( $fresh[ $host ] ) ? implode( ',', array_slice( (array) $fresh[ $host ], 0, 3 ) ) : '',
			);
		}

		Karetaker_Events::record(
			'script_domain_new',
			array(
				'count'   => count( $names ),
				'domains' => array_slice( $names, 0, 10 ),
				'sample'  => wp_json_encode( $sample ),
			),
			0
		);

		return 'found:' . count( $names );
	}

	/**
	 * Compares the home page as a normal visitor and as Googlebot, once a day.
	 *
	 * Spam-injection hacks often show extra links only to search engines. New external
	 * link domains in the Googlebot copy raise Act now; size-only drift is stored for the
	 * Watch tab but never alerts, because caches and A/B tools change page size too.
	 *
	 * @since 1.0.0
	 * @param array $state Scan state (updated by reference).
	 * @return string
	 */
	public static function scan_cloaking( &$state ) {
		$last = isset( $state['cloaking']['at'] ) ? (int) $state['cloaking']['at'] : 0;
		if ( ( time() - $last ) < DAY_IN_SECONDS ) {
			return 'cached';
		}
		if ( self::out_of_time() ) {
			return 'skipped_no_time';
		}

		$pages = array( 'home' => home_url( '/' ) );
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop = wc_get_page_permalink( 'shop' );
			if ( is_string( $shop ) && '' !== $shop && untrailingslashit( $shop ) !== untrailingslashit( $pages['home'] ) ) {
				$pages['shop'] = $shop;
			}
		}
		$expected = isset( $state['cloaking_expected'] ) && is_array( $state['cloaking_expected'] ) ? $state['cloaking_expected'] : array();
		$reported = isset( $state['cloaking']['reported'] ) && is_array( $state['cloaking']['reported'] ) ? $state['cloaking']['reported'] : array();
		$results  = array();
		$extra    = array();
		$failed   = false;

		foreach ( $pages as $page => $url ) {
			if ( self::out_of_time() ) {
				break;
			}
			$visitor = self::fetch_html_for_scripts( $url, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36' );
			$bot     = self::fetch_html_for_scripts( $url, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' );
			if ( '' === $visitor || '' === $bot ) {
				$failed           = true;
				$results[ $page ] = array( 'status' => 'unavailable' );
				continue;
			}
			$visitor_hosts    = self::external_link_hosts( $visitor );
			$bot_hosts        = self::external_link_hosts( $bot );
			$page_extra       = array_values( array_diff( $bot_hosts, $visitor_hosts, $expected ) );
			$extra            = array_merge( $extra, $page_extra );
			$results[ $page ] = array(
				'status'          => $page_extra ? 'different' : 'same',
				'visitor_links'   => self::link_count( $visitor ),
				'visitor_domains' => count( $visitor_hosts ),
				'bot_links'       => self::link_count( $bot ),
				'bot_domains'     => count( $bot_hosts ),
				'extra_hosts'     => array_slice( $page_extra, 0, 20 ),
			);
		}

		$extra             = array_values( array_unique( $extra ) );
		$state['cloaking'] = array(
			'at'          => time(),
			'status'      => $extra ? 'different' : ( $failed && ! array_filter( wp_list_pluck( $results, 'visitor_links' ) ) ? 'unavailable' : 'same' ),
			'extra_hosts' => array_slice( $extra, 0, 20 ),
			'pages'       => $results,
			'reported'    => $reported,
		);

		$fresh = array_values( array_diff( $extra, $reported ) );
		if ( ! $fresh ) {
			return $extra ? 'different_known' : $state['cloaking']['status'];
		}

		$state['cloaking']['reported'] = array_values( array_unique( array_merge( $reported, $fresh ) ) );
		Karetaker_Events::record(
			'cloaking_suspected',
			array(
				'domains' => array_slice( $fresh, 0, 10 ),
				'count'   => count( $fresh ),
				'pages'   => implode( ', ', array_keys( $results ) ),
			),
			0
		);

		return 'different:' . count( $fresh );
	}

	/**
	 * External hostnames linked with <a href> in a document.
	 *
	 * @since 1.0.0
	 * @param string $html Markup.
	 * @return string[]
	 */
	public static function external_link_hosts( $html ) {
		$own   = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$hosts = array();
		if ( preg_match_all( '#<a\s[^>]*href\s*=\s*["\']([^"\']+)["\']#i', (string) $html, $m ) ) {
			foreach ( $m[1] as $href ) {
				$host = strtolower( (string) wp_parse_url( html_entity_decode( $href ), PHP_URL_HOST ) );
				if ( '' === $host || $host === $own || ( '' !== $own && substr( $host, -strlen( '.' . $own ) ) === '.' . $own ) ) {
					continue;
				}
				$hosts[ $host ] = true;
			}
		}
		$hosts = array_keys( $hosts );
		sort( $hosts );
		return $hosts;
	}

	/**
	 * Number of <a href> links in a document.
	 *
	 * @since 1.0.0
	 * @param string $html Markup.
	 * @return int
	 */
	public static function link_count( $html ) {
		return (int) preg_match_all( '#<a\s[^>]*href\s*=#i', (string) $html );
	}

	/**
	 * Collects external script hostnames from homepage/checkout HTML and options.
	 *
	 * @since 1.0.0
	 * @return array<string, string[]> Map of host => source labels.
	 */
	public static function collect_script_hosts() {
		$hosts = array();

		foreach ( self::script_probe_urls() as $label => $url ) {
			if ( self::out_of_time() ) {
				break;
			}
			$html = self::fetch_html_for_scripts( $url );
			if ( '' === $html ) {
				continue;
			}
			foreach ( self::hosts_from_script_markup( $html ) as $host ) {
				if ( ! isset( $hosts[ $host ] ) ) {
					$hosts[ $host ] = array();
				}
				$hosts[ $host ][] = $label;
			}
		}

		foreach ( self::hosts_from_script_options() as $host => $sources ) {
			if ( ! isset( $hosts[ $host ] ) ) {
				$hosts[ $host ] = array();
			}
			$hosts[ $host ] = array_values( array_unique( array_merge( $hosts[ $host ], $sources ) ) );
		}

		ksort( $hosts );
		return $hosts;
	}

	/**
	 * Front-end URLs to fetch for script tags (home + WooCommerce cart/checkout when present).
	 *
	 * @since 1.0.0
	 * @return array<string, string> Label => URL.
	 */
	public static function script_probe_urls() {
		$urls = array(
			'home' => home_url( '/' ),
		);

		if ( in_array( (string) Karetaker_Settings::get( 'site_type' ), array( 'blog', 'business' ), true ) ) {
			return $urls;
		}

		if ( function_exists( 'wc_get_cart_url' ) ) {
			$cart = wc_get_cart_url();
			if ( is_string( $cart ) && '' !== $cart ) {
				$urls['cart'] = $cart;
			}
		}
		if ( function_exists( 'wc_get_checkout_url' ) ) {
			$checkout = wc_get_checkout_url();
			if ( is_string( $checkout ) && '' !== $checkout ) {
				$urls['checkout'] = $checkout;
			}
		}

		return $urls;
	}

	/**
	 * Fetches HTML via wp_safe_remote_get for script extraction.
	 *
	 * @since 1.0.0
	 * @param string $url Absolute URL.
	 * @param string $user_agent Optional User-Agent; defaults to the Karetaker agent.
	 * @return string Body or empty.
	 */
	public static function fetch_html_for_scripts( $url, $user_agent = '' ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 2,
				'user-agent'  => '' !== $user_agent ? $user_agent : 'Karetaker/' . KARETAKER_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			return '';
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > 1500000 ) {
			$body = substr( $body, 0, 1500000 );
		}

		return $body;
	}

	/**
	 * Extracts external hosts from script src attributes in HTML or stored markup.
	 *
	 * @since 1.0.0
	 * @param string $html Markup.
	 * @return string[] Hostnames.
	 */
	public static function hosts_from_script_markup( $html ) {
		$html  = (string) $html;
		$hosts = array();

		if ( preg_match_all( '/<script\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $matches ) ) {
			foreach ( $matches[1] as $src ) {
				$host = self::script_host_from_url( $src );
				if ( '' !== $host ) {
					$hosts[ $host ] = true;
				}
			}
		}

		return array_keys( $hosts );
	}

	/**
	 * Reads widget / theme-mod / snippet-style options for script src hosts.
	 *
	 * @since 1.0.0
	 * @return array<string, string[]> Host => sources.
	 */
	public static function hosts_from_script_options() {
		global $wpdb;

		$hosts = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scan slice; values not stored in events.
		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'widget\\_%' OR option_name LIKE 'theme_mods\\_%' OR option_name LIKE '%snippet%' OR option_name LIKE '%wpcode%' OR option_name LIKE '%header%' OR option_name LIKE '%footer%' LIMIT 800"
		);

		if ( ! is_array( $rows ) ) {
			return $hosts;
		}

		$checked = 0;
		foreach ( $rows as $row ) {
			if ( $checked >= 200 || self::out_of_time() ) {
				break;
			}
			++$checked;
			$name = (string) $row->option_name;
			$val  = (string) $row->option_value;
			if ( '' === $val || false === stripos( $val, '<script' ) ) {
				continue;
			}
			foreach ( self::hosts_from_script_markup( $val ) as $host ) {
				if ( ! isset( $hosts[ $host ] ) ) {
					$hosts[ $host ] = array();
				}
				$hosts[ $host ][] = 'option:' . $name;
			}
		}

		return $hosts;
	}

	/**
	 * Normalizes a script URL to an external hostname, or empty for same-site / relative.
	 *
	 * @since 1.0.0
	 * @param string $url Script src.
	 * @return string Lowercase host or empty.
	 */
	public static function script_host_from_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || 0 === stripos( $url, 'data:' ) || 0 === strpos( $url, '#' ) ) {
			return '';
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}

		if ( 0 === strpos( $url, '/' ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		$host = rtrim( $host, '.' );

		foreach ( array( 'home', 'siteurl' ) as $opt ) {
			$site_host = wp_parse_url( (string) get_option( $opt ), PHP_URL_HOST );
			if ( is_string( $site_host ) && '' !== $site_host && strtolower( $site_host ) === $host ) {
				return '';
			}
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return '';
		}

		return $host;
	}
}
