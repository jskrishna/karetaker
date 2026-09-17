<?php
/**
 * View models for the "What we watch" tab.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads scan state, users, plugins and sessions into rows for the Watch tab.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Admin_Watch {

	const VIEWS = array( 'files', 'people', 'plugins', 'scripts', 'sessions' );

	/**
	 * Newest unresolved event per code from the last week, keyed by code.
	 *
	 * @since 1.0.0
	 * @param string[] $codes Codes to look for.
	 * @return array<string, object[]> Code => rows, newest first.
	 */
	private static function open_events( array $codes ) {
		$marked = Karetaker_Expected::marked_ids();
		$rows   = Karetaker_Events::query(
			array(
				'min_severity' => Karetaker_Events::SEVERITY_ATTENTION,
				'since'        => gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ),
				'limit'        => 500,
			)
		);
		$out    = array();
		foreach ( $rows as $row ) {
			if ( isset( $marked[ (int) $row->id ] ) || ! in_array( (string) $row->event_code, $codes, true ) ) {
				continue;
			}
			$out[ (string) $row->event_code ][] = $row;
		}
		return $out;
	}

	/**
	 * Splits a stored context list ("a, b") into strings.
	 *
	 * @since 1.0.0
	 * @param mixed $value Context value.
	 * @return string[]
	 */
	private static function list_of( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'strval', $value );
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ), 'strlen' ) );
	}

	/**
	 * Human file size.
	 *
	 * @since 1.0.0
	 * @param int $bytes Size in bytes.
	 * @return string
	 */
	private static function size( $bytes ) {
		return size_format( max( 0, (int) $bytes ), $bytes >= 1024 ? 1 : 0 );
	}

	/**
	 * Files view: critical files, core checksums, uploads.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $state Scan state.
	 * @return array<string, mixed>
	 */
	public static function files( array $state ) {
		$open    = self::open_events( array( 'critical_file_changed', 'file_hash_mismatch', 'uploads_php_found', 'uploads_config_changed' ) );
		$changed = array();
		if ( ! empty( $open['critical_file_changed'] ) ) {
			foreach ( $open['critical_file_changed'] as $row ) {
				foreach ( self::list_of( isset( $row->context['paths'] ) ? $row->context['paths'] : '' ) as $rel ) {
					if ( ! isset( $changed[ $rel ] ) ) {
						$changed[ $rel ] = (int) $row->id;
					}
				}
			}
		}

		$known    = isset( $state['critical_files'] ) && is_array( $state['critical_files'] ) ? $state['critical_files'] : array();
		$critical = array();
		foreach ( Karetaker_Scanner::critical_file_paths() as $rel ) {
			$fp    = isset( $known[ $rel ] ) ? explode( '|', (string) $known[ $rel ] ) : array();
			$abs   = Karetaker_Scanner::absolute_from_relative( $rel );
			$exist = '' !== $abs && file_exists( $abs );
			if ( isset( $changed[ $rel ] ) ) {
				$status = array( 'act', __( 'Changed', 'karetaker' ) );
			} elseif ( ! $exist ) {
				$status = array( 'log', __( 'Not present', 'karetaker' ) );
			} elseif ( ! $fp ) {
				$status = array( 'log', __( 'Not checked yet', 'karetaker' ) );
			} else {
				$status = array( 'safe', __( 'Unchanged', 'karetaker' ) );
			}
			$critical[] = array(
				'path'     => 0 === strpos( $rel, '../' ) ? substr( $rel, 3 ) : $rel,
				'chip'     => $status[0],
				'status'   => $status[1],
				'size'     => $exist ? self::size( (int) filesize( $abs ) ) : '—',
				'modified' => isset( $fp[1] ) ? Karetaker_Admin_Data::when( gmdate( 'Y-m-d H:i:s', (int) $fp[1] ) ) : '—',
				'event_id' => isset( $changed[ $rel ] ) ? $changed[ $rel ] : 0,
			);
		}

		$mismatch = array();
		if ( ! empty( $open['file_hash_mismatch'] ) ) {
			foreach ( $open['file_hash_mismatch'] as $row ) {
				$subject = isset( $row->context['subject'] ) ? (string) $row->context['subject'] : 'core';
				foreach ( self::list_of( isset( $row->context['files'] ) ? $row->context['files'] : '' ) as $file ) {
					$mismatch[] = array(
						'subject'  => $subject,
						'file'     => $file,
						'event_id' => (int) $row->id,
					);
				}
			}
		}

		$uploads      = isset( $state['uploads_found'] ) && is_array( $state['uploads_found'] ) ? array_map( 'strval', $state['uploads_found'] ) : array();
		$uploads_open = ! empty( $open['uploads_php_found'] ) || ! empty( $open['uploads_config_changed'] );

		return array(
			'critical'      => $critical,
			'core_verified' => isset( $state['core_verified'] ) ? (int) $state['core_verified'] : 0,
			'wp_version'    => get_bloginfo( 'version' ),
			'mismatch'      => array_slice( $mismatch, 0, 50 ),
			'uploads'       => array_slice( $uploads, 0, 50 ),
			'uploads_count' => count( $uploads ),
			'uploads_open'  => $uploads_open,
			'uploads_seen'  => isset( $state['uploads_found'] ),
			'uploads_total' => isset( $state['uploads_checked'] ) ? (int) $state['uploads_checked'] : 0,
			'core_stats'    => isset( $state['core_stats'] ) && is_array( $state['core_stats'] ) ? $state['core_stats'] : array(),
		);
	}

	/**
	 * Administrators read straight from usermeta, so hidden accounts show too.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $state Scan state.
	 * @return array<int, array<string, mixed>>
	 */
	public static function people( array $state ) {
		global $wpdb;

		$cap_key = $wpdb->get_blog_prefix() . 'capabilities';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same roster read as Karetaker_Scanner::scan_admin_roster().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.user_login, u.user_email, u.user_registered, um.meta_value FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id WHERE um.meta_key = %s",
				$cap_key
			)
		);

		$visible = array();
		foreach ( get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'ID' ),
			)
		) as $user ) {
			$visible[ (int) $user->ID ] = true;
		}

		$baseline = isset( $state['admin_roster'] ) && is_array( $state['admin_roster'] ) ? array_map( 'strval', $state['admin_roster'] ) : null;
		$out      = array();
		$creators = array();
		foreach ( array( 'admin_user_added', 'user_created' ) as $code ) {
			foreach ( Karetaker_Events::query(
				array(
					'code'  => $code,
					'limit' => 200,
				)
			) as $event ) {
				$login = isset( $event->context['user_login'] ) ? (string) $event->context['user_login'] : '';
				if ( '' !== $login && ! isset( $creators[ $login ] ) ) {
					$actor              = (int) $event->user_id ? get_userdata( (int) $event->user_id ) : false;
					$creators[ $login ] = $actor ? (string) $actor->user_login : __( 'Sign-up or script', 'karetaker' );
				}
			}
		}
		$two_factor = class_exists( 'Two_Factor_Core' ) && method_exists( 'Two_Factor_Core', 'is_user_using_two_factor' );
		foreach ( (array) $rows as $row ) {
			$caps = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $caps ) || empty( $caps['administrator'] ) ) {
				continue;
			}
			$id     = (int) $row->ID;
			$hidden = ! isset( $visible[ $id ] );
			$new    = null !== $baseline && ! in_array( (string) $row->user_login, $baseline, true );
			$seen   = get_user_meta( $id, 'karetaker_login_seen', true );
			$last   = 0;
			if ( is_array( $seen ) && ! empty( $seen['ips'] ) && is_array( $seen['ips'] ) ) {
				$last = (int) max( $seen['ips'] );
			}

			if ( $hidden ) {
				$status = array( 'act', $new ? __( 'Hidden · new', 'karetaker' ) : __( 'Hidden', 'karetaker' ) );
			} elseif ( $new ) {
				$status = array( 'check', __( 'New', 'karetaker' ) );
			} elseif ( $last && ( time() - $last ) > 180 * DAY_IN_SECONDS ) {
				/* translators: %d: months */
				$status = array( 'check', sprintf( __( 'Inactive %d months', 'karetaker' ), (int) floor( ( time() - $last ) / ( 30 * DAY_IN_SECONDS ) ) ) );
			} else {
				$status = array( 'safe', __( 'Known', 'karetaker' ) );
			}

			if ( isset( $creators[ (string) $row->user_login ] ) ) {
				$created_by = $creators[ (string) $row->user_login ];
			} elseif ( $hidden || $new ) {
				$created_by = __( 'Database write', 'karetaker' );
			} else {
				$created_by = __( 'Before Karetaker', 'karetaker' );
			}
			if ( $two_factor ) {
				$twofa = Two_Factor_Core::is_user_using_two_factor( $id ) ? 'on' : 'off';
			} else {
				$twofa = 'unknown';
			}

			$out[] = array(
				'id'         => $id,
				'created_by' => $created_by,
				'twofa'      => $twofa,
				'login'      => (string) $row->user_login,
				'email'      => (string) $row->user_email,
				'hidden'     => $hidden,
				'chip'       => $status[0],
				'status'     => $status[1],
				'added'      => Karetaker_Admin_Data::when( (string) $row->user_registered ),
				'last'       => $last ? Karetaker_Admin_Data::when( gmdate( 'Y-m-d H:i:s', $last ) ) : __( 'Not seen', 'karetaker' ),
				'edit'       => $hidden ? '' : get_edit_user_link( $id ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				$rank = array(
					'act'   => 0,
					'check' => 1,
					'safe'  => 2,
				);
				return $rank[ $a['chip'] ] - $rank[ $b['chip'] ];
			}
		);

		return $out;
	}

	/**
	 * Installed plugins with WordPress.org directory status and known vulnerabilities.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $state Scan state.
	 * @return array{rows: array<int, array<string, mixed>>, update: array<string, string>|null}
	 */
	public static function plugins( array $state ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$directory = isset( $state['plugin_directory'] ) && is_array( $state['plugin_directory'] ) ? $state['plugin_directory'] : array();
		$vulns     = isset( $state['vulns'] ) && is_array( $state['vulns'] ) ? $state['vulns'] : array();
		$verified  = isset( $state['plugin_verified'] ) && is_array( $state['plugin_verified'] ) ? $state['plugin_verified'] : array();
		$active    = (array) get_option( 'active_plugins', array() );
		$out       = array();

		foreach ( get_plugins() as $file => $data ) {
			$slug   = Karetaker_Checksums::plugin_slug( $file );
			$status = isset( $directory[ $slug ]['status'] ) ? (string) $directory[ $slug ]['status'] : '';
			$is_on  = in_array( $file, $active, true );

			if ( isset( $vulns[ $slug ] ) ) {
				$risk = array( 'act', __( 'Known vulnerability', 'karetaker' ), __( 'A published vulnerability matches this version. Update it or replace it.', 'karetaker' ) );
			} elseif ( 'closed' === $status ) {
				$risk = array( 'act', __( 'Closed', 'karetaker' ), __( 'Removed from WordPress.org, so it gets no updates. Replace or remove it.', 'karetaker' ) );
			} elseif ( ! empty( $directory[ $slug ]['owner_changed'] ) && ( time() - (int) $directory[ $slug ]['owner_changed'] ) < 90 * DAY_IN_SECONDS ) {
				$risk = array( 'check', __( 'Owner changed', 'karetaker' ), __( 'A new author took over in the last 90 days. Watch its next updates.', 'karetaker' ) );
			} elseif ( 'abandoned' === $status ) {
				$risk = array( 'check', __( 'Not updated in 2+ years', 'karetaker' ), __( 'Unmaintained plugins do not get security fixes.', 'karetaker' ) );
			} elseif ( 'not_found' === $status ) {
				$risk = array( 'log', __( 'Can\'t verify', 'karetaker' ), __( 'Not on WordPress.org (premium or custom), so its files cannot be compared.', 'karetaker' ) );
			} elseif ( '' === $status ) {
				$risk = array( 'log', __( 'Not checked yet', 'karetaker' ), $is_on ? __( 'Active plugins are checked a few at a time on each scan.', 'karetaker' ) : __( 'Only active plugins are checked.', 'karetaker' ) );
			} else {
				$risk = array( 'safe', __( 'OK', 'karetaker' ), __( 'Listed and maintained on WordPress.org.', 'karetaker' ) );
			}

			$check = isset( $verified[ $slug ]['status'] ) ? (string) $verified[ $slug ]['status'] : '';
			if ( 'verified' === $check && isset( $verified[ $slug ]['version'] ) && (string) $data['Version'] !== (string) $verified[ $slug ]['version'] ) {
				$check = '';
			}
			if ( 'verified' === $check ) {
				$files = array( 'safe', __( 'Verified', 'karetaker' ) );
			} elseif ( 'modified' === $check ) {
				$files = array( 'act', __( 'Changed files', 'karetaker' ) );
			} elseif ( 'unavailable' === $check || 'not_found' === $status ) {
				$files = array( 'log', __( 'Can\'t verify', 'karetaker' ) );
			} else {
				$files = array( 'log', '—' );
			}

			$out[] = array(
				'name'    => (string) $data['Name'],
				'slug'    => $slug,
				'version' => (string) $data['Version'],
				'active'  => $is_on,
				'fchip'   => $files[0],
				'files'   => $files[1],
				'chip'    => $risk[0],
				'risk'    => $risk[1],
				'why'     => $risk[2],
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				$rank = array(
					'act'   => 0,
					'check' => 1,
					'log'   => 2,
					'safe'  => 3,
				);
				$diff = $rank[ $a['chip'] ] - $rank[ $b['chip'] ];
				return 0 !== $diff ? $diff : strcasecmp( $a['name'], $b['name'] );
			}
		);

		$last   = Karetaker_Events::query(
			array(
				'code'  => 'plugin_updated',
				'limit' => 1,
			)
		);
		$update = null;
		if ( $last ) {
			$ctx    = $last[0]->context;
			$slug   = isset( $ctx['slug'] ) ? sanitize_title( (string) $ctx['slug'] ) : '';
			$update = array(
				'name' => isset( $ctx['name'] ) ? (string) $ctx['name'] : $slug,
				'from' => isset( $ctx['from'] ) ? (string) $ctx['from'] : '',
				'to'   => isset( $ctx['to'] ) ? (string) $ctx['to'] : '',
				'when' => Karetaker_Admin_Data::when( (string) $last[0]->event_time ),
				'url'  => '' !== $slug ? 'https://plugins.trac.wordpress.org/log/' . rawurlencode( $slug ) . '/' : '',
			);
		}

		return array(
			'rows'   => $out,
			'update' => $update,
		);
	}

	/**
	 * External script domains from the baseline plus unresolved new ones.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $state Scan state.
	 * @return array{rows: array<int, array<string, mixed>>, seen: bool, cloaking: array<string, mixed>}
	 */
	public static function scripts( array $state ) {
		$open  = self::open_events( array( 'script_domain_new' ) );
		$fresh = array();
		if ( ! empty( $open['script_domain_new'] ) ) {
			foreach ( $open['script_domain_new'] as $row ) {
				foreach ( self::list_of( isset( $row->context['domains'] ) ? $row->context['domains'] : '' ) as $host ) {
					if ( ! isset( $fresh[ $host ] ) ) {
						$fresh[ $host ] = (int) $row->id;
					}
				}
			}
		}

		$hosts   = isset( $state['script_domains'] ) && is_array( $state['script_domains'] ) ? array_map( 'strval', $state['script_domains'] ) : array();
		$hosts   = array_values( array_unique( array_merge( array_keys( $fresh ), $hosts ) ) );
		$sources = isset( $state['script_sources'] ) && is_array( $state['script_sources'] ) ? $state['script_sources'] : array();
		$labels  = array(
			'home'     => __( 'Home page', 'karetaker' ),
			'cart'     => __( 'Cart', 'karetaker' ),
			'checkout' => __( 'Checkout', 'karetaker' ),
		);
		$rows    = array();
		foreach ( $hosts as $host ) {
			$where = array();
			foreach ( isset( $sources[ $host ] ) ? (array) $sources[ $host ] : array() as $source ) {
				$source  = (string) $source;
				$where[] = isset( $labels[ $source ] ) ? $labels[ $source ] : ( 0 === strpos( $source, 'option:' ) ? __( 'Saved code setting', 'karetaker' ) : $source );
			}
			$rows[] = array(
				'host'     => $host,
				'where'    => $where ? implode( ', ', array_unique( $where ) ) : __( 'No longer seen', 'karetaker' ),
				'chip'     => isset( $fresh[ $host ] ) ? 'check' : 'safe',
				'status'   => isset( $fresh[ $host ] ) ? __( 'New', 'karetaker' ) : __( 'Known', 'karetaker' ),
				'event_id' => isset( $fresh[ $host ] ) ? $fresh[ $host ] : 0,
			);
		}

		return array(
			'rows'     => $rows,
			'seen'     => isset( $state['script_domains'] ),
			'cloaking' => isset( $state['cloaking'] ) && is_array( $state['cloaking'] ) ? $state['cloaking'] : array(),
		);
	}

	/**
	 * Short "Browser on OS" label from a User-Agent string.
	 *
	 * @since 1.0.0
	 * @param string $ua User-Agent.
	 * @return string
	 */
	public static function device_label( $ua ) {
		$ua = (string) $ua;
		if ( '' === $ua ) {
			return __( 'Unknown device', 'karetaker' );
		}

		$browser = __( 'Browser', 'karetaker' );
		foreach ( array(
			'Edg/'     => 'Edge',
			'OPR/'     => 'Opera',
			'Firefox/' => 'Firefox',
			'Chrome/'  => 'Chrome',
			'Safari/'  => 'Safari',
			'curl/'    => 'curl',
		) as $needle => $name ) {
			if ( false !== stripos( $ua, $needle ) ) {
				$browser = $name;
				break;
			}
		}

		$os = '';
		foreach ( array(
			'Android'   => 'Android',
			'iPhone'    => 'iOS',
			'iPad'      => 'iPadOS',
			'Windows'   => 'Windows',
			'Mac OS X'  => 'macOS',
			'Macintosh' => 'macOS',
			'Linux'     => 'Linux',
		) as $needle => $name ) {
			if ( false !== stripos( $ua, $needle ) ) {
				$os = $name;
				break;
			}
		}

		/* translators: 1: browser, 2: operating system */
		return '' === $os ? $browser : sprintf( __( '%1$s on %2$s', 'karetaker' ), $browser, $os );
	}

	/**
	 * Administrator IDs and logins read from usermeta, including hidden accounts.
	 *
	 * @since 1.0.0
	 * @return array<int, string> User ID => login.
	 */
	public static function admin_ids() {
		$out = array();
		foreach ( self::people( array() ) as $row ) {
			$out[ (int) $row['id'] ] = (string) $row['login'];
		}
		return $out;
	}

	/**
	 * Signed-in sessions for every administrator.
	 *
	 * @since 1.0.0
	 * @return array{rows: array<int, array<string, mixed>>, per_session: bool}
	 */
	public static function sessions() {
		$per_session = 'WP_User_Meta_Session_Tokens' === apply_filters( 'session_token_manager', 'WP_User_Meta_Session_Tokens' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		$current     = hash( 'sha256', (string) wp_get_session_token() );
		$rows        = array();

		foreach ( self::admin_ids() as $user_id => $user_login ) {
			$sessions = $per_session ? get_user_meta( $user_id, 'session_tokens', true ) : array();
			if ( ! $per_session ) {
				foreach ( WP_Session_Tokens::get_instance( $user_id )->get_all() as $i => $session ) {
					$sessions[ 'n' . $i ] = $session;
				}
			}
			if ( ! is_array( $sessions ) ) {
				continue;
			}
			foreach ( $sessions as $verifier => $session ) {
				if ( ! is_array( $session ) || ( isset( $session['expiration'] ) && (int) $session['expiration'] < time() ) ) {
					continue;
				}
				$rows[] = array(
					'user_id'  => $user_id,
					'login'    => $user_login,
					'verifier' => $per_session ? (string) $verifier : '',
					'device'   => self::device_label( isset( $session['ua'] ) ? (string) $session['ua'] : '' ),
					'ip'       => isset( $session['ip'] ) ? (string) $session['ip'] : '',
					'login_at' => isset( $session['login'] ) ? (int) $session['login'] : 0,
					'when'     => isset( $session['login'] ) ? Karetaker_Admin_Data::when( gmdate( 'Y-m-d H:i:s', (int) $session['login'] ) ) : '',
					'current'  => get_current_user_id() === $user_id && hash_equals( $current, (string) $verifier ),
				);
			}
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['login_at'] - $a['login_at'];
			}
		);

		return array(
			'rows'        => $rows,
			'per_session' => $per_session,
		);
	}

	/**
	 * Ends one stored session by its verifier (user-meta session storage only).
	 *
	 * @since 1.0.0
	 * @param int    $user_id  User ID.
	 * @param string $verifier Session verifier hash.
	 * @return bool
	 */
	public static function end_session( $user_id, $verifier ) {
		$sessions = get_user_meta( (int) $user_id, 'session_tokens', true );
		if ( ! is_array( $sessions ) || ! isset( $sessions[ $verifier ] ) ) {
			return false;
		}
		unset( $sessions[ $verifier ] );
		if ( $sessions ) {
			update_user_meta( (int) $user_id, 'session_tokens', $sessions );
		} else {
			delete_user_meta( (int) $user_id, 'session_tokens' );
		}
		return true;
	}

	/**
	 * Signs every administrator out everywhere, keeping only the current session.
	 *
	 * @since 1.0.0
	 * @return int Number of administrators affected.
	 */
	public static function end_all_admin_sessions() {
		$count = 0;
		foreach ( array_keys( self::admin_ids() ) as $user_id ) {
			$manager = WP_Session_Tokens::get_instance( $user_id );
			if ( get_current_user_id() === $user_id ) {
				$manager->destroy_others( wp_get_session_token() );
			} else {
				$manager->destroy_all();
			}
			++$count;
		}
		return $count;
	}
}
