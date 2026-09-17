<?php
/**
 * View models for the admin screens.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns events, scan state and settings into plain arrays the partials render.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Admin_Data {

	const ATTENTION_LIMIT = 6;

	/**
	 * Severity key used by CSS chips: act, watch, or log.
	 *
	 * @since 1.0.0
	 * @param int $severity Severity constant.
	 * @return string
	 */
	public static function severity_key( $severity ) {
		$severity = (int) $severity;
		if ( Karetaker_Events::SEVERITY_ACT === $severity ) {
			return 'act';
		}
		if ( Karetaker_Events::SEVERITY_ATTENTION === $severity ) {
			return 'watch';
		}
		return 'log';
	}

	/**
	 * Human label for a severity.
	 *
	 * @since 1.0.0
	 * @param int $severity Severity constant.
	 * @return string
	 */
	public static function severity_label( $severity ) {
		$key = self::severity_key( $severity );
		if ( 'act' === $key ) {
			return __( 'Act now', 'karetaker' );
		}
		if ( 'watch' === $key ) {
			return __( 'Worth a look', 'karetaker' );
		}
		return __( 'Logged', 'karetaker' );
	}

	/**
	 * Short local time for a UTC MySQL datetime: "14:02", "Yesterday", or "3 Aug".
	 *
	 * @since 1.0.0
	 * @param string $mysql_utc UTC datetime.
	 * @return string
	 */
	public static function short_time( $mysql_utc ) {
		$ts = strtotime( (string) $mysql_utc . ' UTC' );
		if ( ! $ts ) {
			return '';
		}

		$day   = wp_date( 'Y-m-d', $ts );
		$today = wp_date( 'Y-m-d' );
		if ( $day === $today ) {
			return wp_date( (string) get_option( 'time_format' ), $ts );
		}
		if ( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) === $day ) {
			return __( 'Yesterday', 'karetaker' );
		}
		return wp_date( 'j M', $ts );
	}

	/**
	 * Readable local time for a UTC MySQL datetime: "Today, 14:02" or "3 Aug, 14:02".
	 *
	 * @since 1.0.0
	 * @param string $mysql_utc UTC datetime.
	 * @return string
	 */
	public static function when( $mysql_utc ) {
		$ts = strtotime( (string) $mysql_utc . ' UTC' );
		if ( ! $ts ) {
			return '';
		}

		$time = wp_date( (string) get_option( 'time_format' ), $ts );
		$day  = wp_date( 'Y-m-d', $ts );
		if ( wp_date( 'Y-m-d' ) === $day ) {
			/* translators: %s: local time */
			return sprintf( __( 'Today, %s', 'karetaker' ), $time );
		}
		if ( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) === $day ) {
			/* translators: %s: local time */
			return sprintf( __( 'Yesterday, %s', 'karetaker' ), $time );
		}
		$format = wp_date( 'Y' ) === wp_date( 'Y', $ts ) ? 'j M' : 'j M Y';
		return wp_date( $format, $ts ) . ', ' . $time;
	}

	/**
	 * Who / where line for an event.
	 *
	 * @since 1.0.0
	 * @param object $row Hydrated event row.
	 * @return string
	 */
	public static function who( $row ) {
		$user_id = (int) $row->user_id;
		$ip      = isset( $row->ip_display ) ? (string) $row->ip_display : '';

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			$name = $user ? (string) $user->user_login : sprintf(
				/* translators: %d: user ID */
				__( 'Deleted user #%d', 'karetaker' ),
				$user_id
			);
		} elseif ( '' === $ip ) {
			$name = __( 'Karetaker check', 'karetaker' );
		} else {
			$name = __( 'Visitor', 'karetaker' );
		}

		return '' === $ip ? $name : $name . ' · ' . $ip;
	}

	/**
	 * Labelled, human-readable context pairs for the drawer.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $context Event context.
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function details( array $context ) {
		$labels = array(
			'paths'        => __( 'Files', 'karetaker' ),
			'files'        => __( 'Files', 'karetaker' ),
			'added'        => __( 'Added', 'karetaker' ),
			'removed'      => __( 'Removed', 'karetaker' ),
			'changed'      => __( 'Changed', 'karetaker' ),
			'logins'       => __( 'Accounts', 'karetaker' ),
			'user_login'   => __( 'User', 'karetaker' ),
			'login'        => __( 'User', 'karetaker' ),
			'role'         => __( 'Role', 'karetaker' ),
			'name'         => __( 'Name', 'karetaker' ),
			'plugin'       => __( 'Plugin', 'karetaker' ),
			'slug'         => __( 'Slug', 'karetaker' ),
			'theme'        => __( 'Theme', 'karetaker' ),
			'version'      => __( 'Version', 'karetaker' ),
			'option'       => __( 'Setting', 'karetaker' ),
			'names'        => __( 'Names', 'karetaker' ),
			'domains'      => __( 'Domains', 'karetaker' ),
			'hooks'        => __( 'Scheduled hooks', 'karetaker' ),
			'dirs'         => __( 'Folders', 'karetaker' ),
			'device'       => __( 'Device', 'karetaker' ),
			'ip'           => __( 'IP', 'karetaker' ),
			'check'        => __( 'Check', 'karetaker' ),
			'count'        => __( 'Count', 'karetaker' ),
			'reason'       => __( 'Reason', 'karetaker' ),
			'closed_date'  => __( 'Closed', 'karetaker' ),
			'last_updated' => __( 'Last updated', 'karetaker' ),
			'vuln_name'    => __( 'Vulnerability', 'karetaker' ),
			'cve'          => __( 'CVE', 'karetaker' ),
			'fixed_before' => __( 'Fixed in', 'karetaker' ),
			'via'          => __( 'Via', 'karetaker' ),
			'from'         => __( 'From', 'karetaker' ),
			'to'           => __( 'To', 'karetaker' ),
			'old'          => __( 'Before', 'karetaker' ),
			'new'          => __( 'After', 'karetaker' ),
			'value'        => __( 'Value', 'karetaker' ),
			'subject'      => __( 'Checked', 'karetaker' ),
			'source'       => __( 'Source', 'karetaker' ),
			'error'        => __( 'Error', 'karetaker' ),
			'code'         => __( 'Event', 'karetaker' ),
		);
		$skip   = array( 'baseline', 'sample', 'roster', 'vuln_uuid', 'ua', 'plugin_file', 'event_id', 'match_count' );

		$out = array();
		foreach ( $context as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			if ( is_bool( $value ) ) {
				$value = $value ? __( 'Yes', 'karetaker' ) : __( 'No', 'karetaker' );
			} elseif ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
			} elseif ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = (string) $value;
			if ( '' === $value ) {
				continue;
			}
			$label = isset( $labels[ $key ] ) ? $labels[ $key ] : ucfirst( str_replace( '_', ' ', $key ) );
			$out[] = array( $label, $value );
			if ( count( $out ) >= 10 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Everything the drawer and lists need for one event.
	 *
	 * @since 1.0.0
	 * @param object          $row    Hydrated event row.
	 * @param array<int,true> $marked Event ids already marked as expected.
	 * @return array<string, mixed>
	 */
	public static function event( $row, array $marked = array() ) {
		$context  = is_array( $row->context ) ? $row->context : array();
		$code     = (string) $row->event_code;
		$guidance = Karetaker_Guidance::for_event( $code, $context );
		$id       = (int) $row->id;
		$title    = '' !== $guidance['title'] ? $guidance['title'] : $code;
		$json     = wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return array(
			'id'         => $id,
			'code'       => $code,
			'sev'        => self::severity_key( (int) $row->severity ),
			'sev_label'  => self::severity_label( (int) $row->severity ),
			'title'      => $title,
			'summary'    => (string) $guidance['summary'],
			'steps'      => array_values( array_map( 'strval', (array) $guidance['steps'] ) ),
			'link'       => (string) $guidance['link'],
			'link_label' => (string) $guidance['link_label'],
			'when'       => self::when( (string) $row->event_time ),
			'short'      => self::short_time( (string) $row->event_time ),
			'time_utc'   => (string) $row->event_time,
			'who'        => self::who( $row ),
			'details'    => self::details( $context ),
			'json'       => false === $json ? '{}' : $json,
			'can_mark'   => Karetaker_Expected::supports( $code ) && ! isset( $marked[ $id ] ),
			'expected'   => isset( $marked[ $id ] ),
		);
	}

	/**
	 * Unresolved Act now and Worth a look events, newest per event code, Act now first.
	 *
	 * @since 1.0.0
	 * @param object[]        $act_rows   Act now rows.
	 * @param object[]        $watch_rows Worth a look rows.
	 * @param array<int,true> $marked     Marked-expected ids.
	 * @return array{items: array<int, array<string, mixed>>, act: int, watch: int, more: int}
	 */
	public static function attention( array $act_rows, array $watch_rows, array $marked ) {
		$seen  = array();
		$items = array();
		$act   = 0;
		$watch = 0;

		foreach ( array_merge( $act_rows, $watch_rows ) as $row ) {
			if ( isset( $marked[ (int) $row->id ] ) ) {
				continue;
			}
			$code = (string) $row->event_code;
			if ( isset( $seen[ $code ] ) ) {
				continue;
			}
			$seen[ $code ] = true;
			if ( Karetaker_Events::SEVERITY_ACT === (int) $row->severity ) {
				++$act;
			} else {
				++$watch;
			}
			$items[] = self::event( $row, $marked );
		}

		$total = count( $items );

		return array(
			'items' => array_slice( $items, 0, self::ATTENTION_LIMIT ),
			'act'   => $act,
			'watch' => $watch,
			'more'  => max( 0, $total - self::ATTENTION_LIMIT ),
		);
	}

	/**
	 * Dot level for a group of codes from unresolved recent events.
	 *
	 * @since 1.0.0
	 * @param object[]        $rows   Act now + Worth a look rows.
	 * @param array<int,true> $marked Marked-expected ids.
	 * @param string[]        $codes  Codes in the group.
	 * @return string '', 'check', or 'act'.
	 */
	private static function level( array $rows, array $marked, array $codes ) {
		$level = '';
		foreach ( $rows as $row ) {
			if ( isset( $marked[ (int) $row->id ] ) || ! in_array( (string) $row->event_code, $codes, true ) ) {
				continue;
			}
			if ( Karetaker_Events::SEVERITY_ACT === (int) $row->severity ) {
				return 'act';
			}
			$level = 'check';
		}
		return $level;
	}

	/**
	 * Rows for the "What Karetaker is watching" panel.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $state  Scan state.
	 * @param object[]             $rows   Act now + Worth a look rows from the last week.
	 * @param array<int,true>      $marked Marked-expected ids.
	 * @return array<int, array{name: string, value: string, dot: string}>
	 */
	public static function coverage( array $state, array $rows, array $marked ) {
		$never = __( 'Not checked yet', 'karetaker' );
		$guard = isset( $state['guard'] ) && is_array( $state['guard'] ) ? $state['guard'] : array();
		$out   = array();

		$critical = 0;
		foreach ( array_keys( isset( $state['critical_files'] ) && is_array( $state['critical_files'] ) ? $state['critical_files'] : array() ) as $rel ) {
			if ( 0 !== strpos( (string) $rel, 'uploads:' ) ) {
				++$critical;
			}
		}
		$out[] = array(
			'name'  => __( 'Critical files', 'karetaker' ),
			/* translators: %d: number of files */
			'value' => isset( $state['critical_files'] ) ? sprintf( _n( '%d file', '%d files', $critical, 'karetaker' ), $critical ) : $never,
			'dot'   => self::level( $rows, $marked, array( 'critical_file_changed' ) ),
		);

		$core_dot = '';
		foreach ( $rows as $row ) {
			if ( 'file_hash_mismatch' === (string) $row->event_code && ! isset( $marked[ (int) $row->id ] ) && ( ! isset( $row->context['subject'] ) || 'WordPress core' === (string) $row->context['subject'] ) ) {
				$core_dot = 'act';
			}
		}
		$out[] = array(
			'name'  => __( 'WordPress core', 'karetaker' ),
			/* translators: %s: WordPress version */
			'value' => empty( $state['core_verified'] ) ? $never : ( '' === $core_dot ? sprintf( __( 'Matches %s', 'karetaker' ), get_bloginfo( 'version' ) ) : __( 'Changed files found', 'karetaker' ) ),
			'dot'   => $core_dot,
		);

		$active   = (array) get_option( 'active_plugins', array() );
		$verified = 0;
		$changed  = 0;
		foreach ( $active as $file ) {
			$slug   = Karetaker_Checksums::plugin_slug( (string) $file );
			$status = isset( $state['plugin_verified'][ $slug ]['status'] ) ? (string) $state['plugin_verified'][ $slug ]['status'] : '';
			if ( 'verified' === $status ) {
				++$verified;
			} elseif ( 'modified' === $status ) {
				++$changed;
			}
		}
		$out[] = array(
			'name'  => __( 'Active plugins (checksums)', 'karetaker' ),
			/* translators: 1: verified plugins, 2: active plugins */
			'value' => isset( $state['plugin_verified'] ) ? sprintf( __( '%1$d of %2$d verified', 'karetaker' ), $verified, count( $active ) ) : $never,
			'dot'   => $changed ? 'act' : '',
		);

		$admins    = isset( $state['admin_roster'] ) && is_array( $state['admin_roster'] ) ? count( $state['admin_roster'] ) : 0;
		$admin_dot = self::level( $rows, $marked, array( 'admin_user_added', 'role_escalated', 'admin_roster_changed', 'hidden_admin_found' ) );
		$new       = 0;
		foreach ( $rows as $row ) {
			if ( in_array( (string) $row->event_code, array( 'admin_user_added', 'admin_roster_changed', 'hidden_admin_found' ), true ) && ! isset( $marked[ (int) $row->id ] ) ) {
				++$new;
			}
		}
		/* translators: %d: number of administrators */
		$admin_val = sprintf( _n( '%d known', '%d known', $admins, 'karetaker' ), $admins );
		if ( $new ) {
			/* translators: %d: number of new administrator signals */
			$admin_val .= ' · ' . sprintf( _n( '%d new', '%d new', $new, 'karetaker' ), $new );
		}
		$out[] = array(
			'name'  => __( 'Administrators', 'karetaker' ),
			'value' => isset( $state['admin_roster'] ) ? $admin_val : $never,
			'dot'   => $admin_dot,
		);

		$closed    = 0;
		$abandoned = 0;
		foreach ( isset( $state['plugin_directory'] ) && is_array( $state['plugin_directory'] ) ? $state['plugin_directory'] : array() as $entry ) {
			$status     = is_array( $entry ) && isset( $entry['status'] ) ? (string) $entry['status'] : '';
			$closed    += 'closed' === $status ? 1 : 0;
			$abandoned += 'abandoned' === $status ? 1 : 0;
		}
		$vulns = isset( $state['vulns'] ) && is_array( $state['vulns'] ) ? count( $state['vulns'] ) : 0;
		if ( $closed ) {
			/* translators: %d: number of plugins */
			$risk = sprintf( _n( '%d closed on WordPress.org', '%d closed on WordPress.org', $closed, 'karetaker' ), $closed );
		} elseif ( $vulns ) {
			/* translators: %d: number of plugins */
			$risk = sprintf( _n( '%d with a known vulnerability', '%d with known vulnerabilities', $vulns, 'karetaker' ), $vulns );
		} elseif ( $abandoned ) {
			/* translators: %d: number of plugins */
			$risk = sprintf( _n( '%d not updated in 2+ years', '%d not updated in 2+ years', $abandoned, 'karetaker' ), $abandoned );
		} else {
			$risk = isset( $state['plugin_directory'] ) ? __( 'Nothing closed or abandoned', 'karetaker' ) : $never;
		}
		$risk_dot = self::level( $rows, $marked, array( 'plugin_directory_closed', 'plugin_directory_abandoned', 'plugin_vuln_found', 'plugin_owner_changed', 'hidden_plugin_found', 'silent_plugin_found' ) );
		$out[]    = array(
			'name'  => __( 'Plugin risk', 'karetaker' ),
			'value' => $risk,
			'dot'   => '' === $risk_dot && ( $closed || $vulns || $abandoned ) ? 'check' : $risk_dot,
		);

		$uploads = isset( $state['uploads_found'] ) && is_array( $state['uploads_found'] ) ? count( $state['uploads_found'] ) : 0;
		$out[]   = array(
			'name'  => __( 'Uploads folder', 'karetaker' ),
			/* translators: %d: number of files */
			'value' => ! isset( $state['uploads_found'] ) ? $never : ( $uploads ? sprintf( _n( '%d code file', '%d code files', $uploads, 'karetaker' ), $uploads ) : __( 'No code files', 'karetaker' ) ),
			'dot'   => self::level( $rows, $marked, array( 'uploads_php_found', 'uploads_config_changed' ) ),
		);

		$mu     = isset( $state['muplugins'] ) && is_array( $state['muplugins'] ) ? count( $state['muplugins'] ) : 0;
		$mu_dot = self::level( $rows, $marked, array( 'muplugin_changed' ) );
		$out[]  = array(
			'name'  => __( 'MU-plugins', 'karetaker' ),
			/* translators: %d: number of must-use plugin files */
			'value' => ! isset( $state['muplugins'] ) ? $never : ( $mu ? sprintf( __( '%d, unchanged', 'karetaker' ), $mu ) : __( 'None', 'karetaker' ) ),
			'dot'   => $mu_dot,
		);
		if ( $mu && '' !== $mu_dot ) {
			/* translators: %d: number of must-use plugin files */
			$out[ count( $out ) - 1 ]['value'] = sprintf( __( '%d, changed', 'karetaker' ), $mu );
		}

		$scripts = isset( $state['script_domains'] ) && is_array( $state['script_domains'] ) ? count( $state['script_domains'] ) : 0;
		$out[]   = array(
			'name'  => function_exists( 'wc_get_checkout_url' ) ? __( 'Checkout scripts', 'karetaker' ) : __( 'External scripts', 'karetaker' ),
			/* translators: %d: number of domains */
			'value' => isset( $state['script_domains'] ) ? sprintf( _n( '%d known domain', '%d known domains', $scripts, 'karetaker' ), $scripts ) : $never,
			'dot'   => self::level( $rows, $marked, array( 'script_domain_new', 'db_script_found', 'cloaking_suspected' ) ),
		);

		$hidden = ! empty( $guard['blog_public']['bad'] );
		$out[]  = array(
			'name'  => __( 'Search engine visibility', 'karetaker' ),
			'value' => $hidden ? __( 'Hidden', 'karetaker' ) : __( 'Visible', 'karetaker' ),
			'dot'   => $hidden ? 'act' : '',
		);

		$mail_bad = ! empty( $guard['mail_failed']['bad'] );
		$mail_ok  = (int) get_option( 'karetaker_mail_ok_at', 0 );
		if ( $mail_bad ) {
			$mail = __( 'Last send failed', 'karetaker' );
		} elseif ( $mail_ok ) {
			$mail = __( 'Last sent OK', 'karetaker' );
		} else {
			$mail = __( 'No sends seen yet', 'karetaker' );
		}
		$out[] = array(
			'name'  => __( 'Email delivery', 'karetaker' ),
			'value' => $mail,
			'dot'   => $mail_bad ? 'act' : '',
		);

		return $out;
	}

	/**
	 * Activity log filters from the request: kt_sev, kt_cat, kt_range, kt_s, kt_code.
	 *
	 * Read-only list filters; callers check capabilities.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> Query args plus 'ui' with the raw values.
	 */
	public static function log_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET filters only change which events are listed.
		$sev   = isset( $_GET['kt_sev'] ) ? sanitize_key( wp_unslash( $_GET['kt_sev'] ) ) : '';
		$cat   = isset( $_GET['kt_cat'] ) ? sanitize_key( wp_unslash( $_GET['kt_cat'] ) ) : '';
		$range = isset( $_GET['kt_range'] ) ? sanitize_key( wp_unslash( $_GET['kt_range'] ) ) : '7d';
		$q     = isset( $_GET['kt_s'] ) ? sanitize_text_field( wp_unslash( $_GET['kt_s'] ) ) : '';
		$code  = isset( $_GET['kt_code'] ) ? sanitize_key( wp_unslash( $_GET['kt_code'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$args = array();
		if ( 'act' === $sev ) {
			$args['severity'] = Karetaker_Events::SEVERITY_ACT;
		} elseif ( 'review' === $sev ) {
			$args['severity'] = Karetaker_Events::SEVERITY_ATTENTION;
		} elseif ( 'log' === $sev ) {
			$args['severity'] = Karetaker_Events::SEVERITY_LOG;
		}
		if ( '' !== $cat ) {
			$codes = array();
			foreach ( array_keys( Karetaker_Events::codes() ) as $event_code ) {
				if ( Karetaker_Issues::area( $event_code ) === $cat ) {
					$codes[] = $event_code;
				}
			}
			$args['codes'] = $codes;
		}
		$spans = array(
			'24h' => DAY_IN_SECONDS,
			'7d'  => WEEK_IN_SECONDS,
			'30d' => 30 * DAY_IN_SECONDS,
		);
		if ( isset( $spans[ $range ] ) ) {
			$args['since'] = gmdate( 'Y-m-d H:i:s', time() - $spans[ $range ] );
		} else {
			$range = 'all';
		}
		if ( '' !== $q ) {
			$args['search'] = $q;
		}
		if ( '' !== $code ) {
			$args['code'] = $code;
		}
		$args['ui'] = array(
			'kt_sev'   => $sev,
			'kt_cat'   => $cat,
			'kt_range' => $range,
			'kt_s'     => $q,
			'kt_code'  => $code,
		);
		return $args;
	}

	/**
	 * The twelve checks listed on Home, with a level for each: '', 'warn', or 'bad'.
	 *
	 * @since 1.0.0
	 * @return array<int, array{name: string, value: string, level: string}>
	 */
	public static function checks() {
		$state  = Karetaker_Scanner::state();
		$marked = Karetaker_Expected::marked_ids();

		// Events behind an issue somebody resolved or marked as expected count as handled,
		// so a check stops reporting a finding once its issue is closed.
		foreach ( Karetaker_Issues::all() as $issue ) {
			if ( in_array( $issue['status'], array( 'resolved', 'expected' ), true ) ) {
				foreach ( $issue['event_ids'] as $event_id ) {
					$marked[ (int) $event_id ] = true;
				}
			}
		}
		$rows  = Karetaker_Events::query(
			array(
				'min_severity' => Karetaker_Events::SEVERITY_ATTENTION,
				'since'        => gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ),
				'limit'        => 500,
			)
		);
		$level = static function ( $dot ) {
			return 'act' === $dot ? 'bad' : ( 'check' === $dot ? 'warn' : '' );
		};
		$cover = array();
		foreach ( self::coverage( $state, $rows, $marked ) as $row ) {
			$cover[ $row['name'] ] = $row;
		}
		$pick = static function ( $key, $label ) use ( $cover, $level ) {
			$row    = isset( $cover[ $key ] ) ? $cover[ $key ] : array(
				'value' => '',
				'dot'   => '',
			);
			$flag   = $level( $row['dot'] );
			$issues = array(
				__( 'Critical files', 'karetaker' ) => __( 'Changed', 'karetaker' ),
				__( 'Plugin risk', 'karetaker' )    => __( 'Needs a look', 'karetaker' ),
				__( 'Uploads folder', 'karetaker' ) => __( 'Code found', 'karetaker' ),
				__( 'MU-plugins', 'karetaker' )     => __( 'Changed', 'karetaker' ),
			);
			return array(
				'name'  => $label,
				'value' => '' !== $flag && isset( $issues[ $key ] ) && false === strpos( (string) $row['value'], '·' ) ? $issues[ $key ] : $row['value'],
				'level' => $flag,
			);
		};

		$cloak      = isset( $state['cloaking'] ) && is_array( $state['cloaking'] ) ? $state['cloaking'] : array();
		$cloak_open = self::level( $rows, $marked, array( 'cloaking_suspected' ) );
		$failures   = get_option( 'karetaker_login_failures', array() );
		$attempts   = 0;
		foreach ( is_array( $failures ) ? $failures : array() as $row ) {
			if ( time() - (int) $row['last'] <= DAY_IN_SECONDS ) {
				$attempts += (int) $row['count'];
			}
		}
		$burst = self::level( $rows, $marked, array( 'login_failure_burst' ) );

		return array(
			$pick( __( 'Critical files', 'karetaker' ), __( 'Important files', 'karetaker' ) ),
			$pick( __( 'Administrators', 'karetaker' ), __( 'Administrators', 'karetaker' ) ),
			$pick( __( 'Plugin risk', 'karetaker' ), __( 'Plugins', 'karetaker' ) ),
			$pick( __( 'WordPress core', 'karetaker' ), __( 'WordPress core files', 'karetaker' ) ),
			$pick( __( 'Active plugins (checksums)', 'karetaker' ), __( 'Plugin files', 'karetaker' ) ),
			$pick( __( 'Uploads folder', 'karetaker' ), __( 'Uploads folder', 'karetaker' ) ),
			$pick( function_exists( 'wc_get_checkout_url' ) ? __( 'Checkout scripts', 'karetaker' ) : __( 'External scripts', 'karetaker' ), function_exists( 'wc_get_checkout_url' ) ? __( 'Checkout scripts', 'karetaker' ) : __( 'External scripts', 'karetaker' ) ),
			array(
				'name'  => __( 'What Google sees', 'karetaker' ),
				'value' => empty( $cloak['status'] ) ? __( 'Not checked yet', 'karetaker' ) : ( 'different' === $cloak['status'] ? __( 'Different from visitors', 'karetaker' ) : ( 'unavailable' === $cloak['status'] ? __( 'Could not check', 'karetaker' ) : __( 'Same as visitors', 'karetaker' ) ) ),
				'level' => $level( $cloak_open ),
			),
			array(
				'name'  => __( 'Login attempts', 'karetaker' ),
				/* translators: %d: failed login attempts in 24 hours */
				'value' => $burst ? __( 'Password guessing seen', 'karetaker' ) : ( $attempts ? sprintf( _n( '%d failed today', '%d failed today', $attempts, 'karetaker' ), $attempts ) : __( 'Normal', 'karetaker' ) ),
				'level' => $level( $burst ),
			),
			$pick( __( 'MU-plugins', 'karetaker' ), __( 'Must-use plugins', 'karetaker' ) ),
			$pick( __( 'Search engine visibility', 'karetaker' ), __( 'Search engines', 'karetaker' ) ),
			$pick( __( 'Email delivery', 'karetaker' ), __( 'Email sending', 'karetaker' ) ),
		);
	}

	/**
	 * Day-grouped activity feed for the Simple Activity screen.
	 *
	 * @since 1.0.0
	 * @param string $mode important or all.
	 * @param int    $page Page number.
	 * @return array{days: array<int, array{day: string, items: array<int, array<string, mixed>>}>, more: bool}
	 */
	public static function feed( $mode, $page ) {
		$per  = 40;
		$args = array(
			'limit'  => $per + 1,
			'offset' => ( max( 1, (int) $page ) - 1 ) * $per,
		);
		if ( 'important' === $mode ) {
			$args['min_severity'] = Karetaker_Events::SEVERITY_ATTENTION;
		}
		$rows   = Karetaker_Events::query( $args );
		$more   = count( $rows ) > $per;
		$marked = Karetaker_Expected::marked_ids();
		$days   = array();
		foreach ( array_slice( $rows, 0, $per ) as $row ) {
			$ts   = strtotime( (string) $row->event_time . ' UTC' );
			$date = wp_date( 'Y-m-d', $ts );
			if ( wp_date( 'Y-m-d' ) === $date ) {
				$label = __( 'Today', 'karetaker' );
			} elseif ( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) === $date ) {
				$label = __( 'Yesterday', 'karetaker' );
			} else {
				$label = wp_date( (string) get_option( 'date_format' ), $ts );
			}
			$view   = self::event( $row, $marked );
			$detail = $view['details'] ? $view['details'][0][1] : '';
			$who    = $view['who'];
			if ( '' !== $detail ) {
				$who = $detail . ' · ' . $who;
			}
			$days[ $label ][] = array(
				'lv'    => $view['expected'] ? 'log' : ( 'watch' === $view['sev'] ? 'review' : $view['sev'] ),
				'icon'  => Karetaker_Issues::icon( Karetaker_Issues::area( (string) $row->event_code ) ),
				'text'  => $view['title'],
				'who'   => $who,
				'time'  => wp_date( (string) get_option( 'time_format' ), $ts ),
				'event' => $view,
			);
		}
		$out = array();
		foreach ( $days as $day => $items ) {
			$out[] = array(
				'day'   => $day,
				'items' => $items,
			);
		}
		return array(
			'days' => $out,
			'more' => $more,
		);
	}

	/**
	 * Rows for the "Where alerts go" panel: email, each connected channel, weekly summary.
	 *
	 * @since 1.0.0
	 * @return array<int, array{name: string, value: string, dot: string}>
	 */
	public static function alert_routes() {
		$settings = Karetaker_Settings::all();
		$on       = __( 'Connected', 'karetaker' );
		$rows     = array();
		$paused   = Karetaker_Settings::alerts_paused_until();

		if ( $paused ) {
			$rows[] = array(
				'name'  => __( 'All alerts paused', 'karetaker' ),
				/* translators: %s: local time */
				'value' => sprintf( __( 'Until %s', 'karetaker' ), wp_date( (string) get_option( 'time_format' ), $paused ) ),
				'dot'   => 'check',
			);
		}

		$rows[] = array(
			'name'  => __( 'Email', 'karetaker' ),
			'value' => ! empty( $settings['alerts_enabled'] ) ? Karetaker_Settings::alert_email() : __( 'Off', 'karetaker' ),
			'dot'   => ! empty( $settings['alerts_enabled'] ) ? '' : 'check',
		);

		foreach ( array(
			'telegram' => __( 'Telegram', 'karetaker' ),
			'slack'    => __( 'Slack', 'karetaker' ),
			'discord'  => __( 'Discord', 'karetaker' ),
			'teams'    => __( 'Microsoft Teams', 'karetaker' ),
			'webhook'  => __( 'Custom webhook', 'karetaker' ),
		) as $key => $label ) {
			if ( ! empty( $settings[ $key . '_enabled' ] ) ) {
				$rows[] = array(
					'name'  => $label,
					'value' => $on,
					'dot'   => '',
				);
			}
		}

		$rows[] = array(
			'name'  => __( 'Weekly summary', 'karetaker' ),
			'value' => ! empty( $settings['weekly_summary_enabled'] ) ? __( 'On', 'karetaker' ) : __( 'Off', 'karetaker' ),
			'dot'   => ! empty( $settings['weekly_summary_enabled'] ) ? '' : 'check',
		);

		return $rows;
	}

	/**
	 * Recent "marked as expected" entries with the original event title.
	 *
	 * @since 1.0.0
	 * @param int $limit Max entries.
	 * @return array<int, array{event_id: int, title: string, detail: string, when: string}>
	 */
	public static function ignored( $limit = 20 ) {
		$out = array();
		foreach ( array_slice( array_keys( Karetaker_Expected::marked_ids() ), 0, (int) $limit ) as $event_id ) {
			$original = Karetaker_Expected::get_event( $event_id );
			if ( ! $original ) {
				continue;
			}
			$original = Karetaker_Events::hydrate( $original );
			$pairs    = self::details( $original->context );
			$guide    = Karetaker_Guidance::for_event( (string) $original->event_code, $original->context );
			$out[]    = array(
				'event_id' => (int) $event_id,
				'title'    => '' !== $guide['title'] ? $guide['title'] : (string) $original->event_code,
				'detail'   => $pairs ? $pairs[0][1] : '',
				'when'     => self::when( (string) $original->event_time ),
			);
		}
		return $out;
	}

	/**
	 * JSON for a row's data attribute (drawer payload).
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $event Event view model.
	 * @return string
	 */
	public static function event_json( array $event ) {
		$json = wp_json_encode( $event );
		return false === $json ? '{}' : $json;
	}
}
