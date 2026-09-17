<?php
/**
 * On-demand “I think we were hacked” incident check.
 *
 * @package Karetaker
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guided local incident pass: deeper scan + checklist of facts.
 *
 * Does not clean malware, lock logins, or write server config.
 *
 * @since 1.0.0
 */
class Karetaker_Incident {

	const OPTION        = 'karetaker_incident_last';
	const PASS_BUDGET   = 20;
	const PASS_COUNT    = 3;
	const LOOKBACK_DAYS = 7;

	/**
	 * Prepare scan state so a follow-up run does not skip core/plugins.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function prepare_deep_scan() {
		$state = Karetaker_Scanner::state();

		$state['core_verified']    = 0;
		$state['core_offset']      = 0;
		$state['core_carry']       = array();
		$state['core_checked']     = 0;
		$state['plugin_cursor']    = 0;
		$state['directory_cursor'] = 0;
		$state['vuln_cursor']      = 0;
		$state['uploads_offset']   = 0;

		Karetaker_Scanner::save_state( $state );
	}

	/**
	 * Run a multi-pass scan and store a checklist snapshot.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public static function run() {
		self::prepare_deep_scan();

		$passes = array();
		for ( $i = 0; $i < self::PASS_COUNT; $i++ ) {
			$passes[] = Karetaker_Scanner::run( self::PASS_BUDGET );
		}

		$snapshot = self::build_snapshot( $passes );
		update_option( self::OPTION, $snapshot, false );

		Karetaker_Events::record(
			'incident_check_ran',
			array(
				'passes'      => self::PASS_COUNT,
				'budget'      => self::PASS_BUDGET,
				'act_count'   => isset( $snapshot['act_count'] ) ? (int) $snapshot['act_count'] : 0,
				'guard_bad'   => isset( $snapshot['guard_bad'] ) ? (int) $snapshot['guard_bad'] : 0,
				'needs_human' => ! empty( $snapshot['needs_human'] ) ? 1 : 0,
			)
		);

		return $snapshot;
	}

	/**
	 * Last stored incident snapshot, if any.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public static function last() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Self-contained HTML export of the last incident check, for a host or developer.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $snapshot Stored snapshot from last().
	 * @return string
	 */
	public static function render_html( array $snapshot ) {
		$items = isset( $snapshot['items'] ) && is_array( $snapshot['items'] ) ? $snapshot['items'] : array();
		$act   = Karetaker_Events::query(
			array(
				'min_severity' => Karetaker_Events::SEVERITY_ACT,
				'since'        => gmdate( 'Y-m-d H:i:s', time() - ( self::LOOKBACK_DAYS * DAY_IN_SECONDS ) ),
				'limit'        => 50,
			)
		);

		ob_start();
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Incident report — %s', 'karetaker' ), get_bloginfo( 'name' ) ) ); ?></title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1d2327;margin:0;padding:24px;line-height:1.5}
.wrap{max-width:760px;margin:0 auto}
h1{font-size:22px;margin:0 0 4px}h2{font-size:15px;margin:24px 0 8px;border-bottom:1px solid #dcdcde;padding-bottom:6px}
.sub{color:#646970;font-size:13px}
.item{border:1px solid #dcdcde;border-radius:6px;padding:10px 14px;margin:0 0 8px}
.item.flag{border-color:#d63638;background:#fcf0f1}
.item b{display:block}.item p{margin:4px 0 0;font-size:13px;color:#3c434a}
table{width:100%;border-collapse:collapse;font-size:13px}td,th{border-bottom:1px solid #f0f0f1;padding:6px 8px;text-align:left;vertical-align:top}
pre{white-space:pre-wrap;word-break:break-word;font-size:12px;margin:0}
</style>
</head>
<body>
<div class="wrap">
	<h1><?php echo esc_html__( 'Karetaker incident report', 'karetaker' ); ?></h1>
	<p class="sub"><?php echo esc_html( get_bloginfo( 'name' ) . ' · ' . home_url( '/' ) . ' · ' . ( isset( $snapshot['at'] ) ? (string) $snapshot['at'] : '' ) ); ?></p>
	<p><?php echo esc_html( isset( $snapshot['disclaimer'] ) ? (string) $snapshot['disclaimer'] : '' ); ?></p>

	<h2><?php echo esc_html__( 'Checklist', 'karetaker' ); ?></h2>
		<?php foreach ( $items as $item ) : ?>
		<div class="item<?php echo ! empty( $item['flagged'] ) ? ' flag' : ''; ?>">
			<b><?php echo esc_html( ( ! empty( $item['flagged'] ) ? __( 'Review: ', 'karetaker' ) : __( 'Clear: ', 'karetaker' ) ) . ( isset( $item['title'] ) ? (string) $item['title'] : '' ) ); ?></b>
			<p><?php echo esc_html( isset( $item['help'] ) ? (string) $item['help'] : '' ); ?></p>
		</div>
	<?php endforeach; ?>

	<h2><?php echo esc_html__( 'Act now events in the last week', 'karetaker' ); ?></h2>
		<?php if ( $act ) : ?>
		<table>
			<thead><tr><th><?php echo esc_html__( 'Time (UTC)', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Event', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Details', 'karetaker' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $act as $row ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $row->event_time ); ?></td>
					<td><?php echo esc_html( (string) $row->event_code ); ?></td>
					<td><pre><?php echo esc_html( (string) wp_json_encode( $row->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p><?php echo esc_html__( 'None recorded.', 'karetaker' ); ?></p>
	<?php endif; ?>
</div>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build checklist + counts from live state and recent ACT events.
	 *
	 * @since 1.0.0
	 * @param array<int, array<string, string>> $passes Scan pass results.
	 * @return array<string, mixed>
	 */
	public static function build_snapshot( array $passes = array() ) {
		$since = gmdate( 'Y-m-d H:i:s', time() - ( self::LOOKBACK_DAYS * DAY_IN_SECONDS ) );
		$act   = Karetaker_Events::query(
			array(
				'min_severity' => Karetaker_Events::SEVERITY_ACT,
				'since'        => $since,
				'limit'        => 500,
			)
		);

		$codes = array();
		foreach ( $act as $row ) {
			$code = isset( $row->event_code ) ? (string) $row->event_code : '';
			if ( '' !== $code ) {
				$codes[ $code ] = isset( $codes[ $code ] ) ? (int) $codes[ $code ] + 1 : 1;
			}
		}

		$state = Karetaker_Scanner::state();
		$guard = isset( $state['guard'] ) && is_array( $state['guard'] ) ? $state['guard'] : array();
		$bad   = 0;
		foreach ( Karetaker_Guard::CHECKS as $check ) {
			$entry = isset( $guard[ $check ] ) && is_array( $guard[ $check ] ) ? $guard[ $check ] : array();
			if ( ! empty( $entry['bad'] ) ) {
				++$bad;
			}
		}

		$items = array(
			self::item(
				'admins',
				__( 'Administrator accounts', 'karetaker' ),
				__( 'Open Users and confirm every administrator is someone you know. SQL-made admins often skip the normal UI.', 'karetaker' ),
				admin_url( 'users.php?role=administrator' ),
				__( 'Open Users', 'karetaker' ),
				self::codes_hit( $codes, array( 'admin_user_added', 'role_escalated', 'admin_roster_changed', 'hidden_admin_found' ) )
			),
			self::item(
				'plugins',
				__( 'Plugins and must-use plugins', 'karetaker' ),
				__( 'Look for plugins you did not install, silent folders, or closed/abandoned directory plugins still active.', 'karetaker' ),
				admin_url( 'plugins.php' ),
				__( 'Open Plugins', 'karetaker' ),
				self::codes_hit( $codes, array( 'hidden_plugin_found', 'silent_plugin_found', 'muplugin_changed', 'plugin_directory_closed', 'plugin_owner_changed', 'option_name_suspect' ) )
			),
			self::item(
				'uploads',
				__( 'Uploads and executable PHP', 'karetaker' ),
				__( 'PHP under uploads is often served directly and bypasses WordPress. Remove unknown .php / .htaccess / .user.ini files.', 'karetaker' ),
				add_query_arg(
					array(
						'page'    => 'karetaker',
						'tab'     => 'activity',
						'kt_code' => 'uploads_php_found',
					),
					admin_url( 'admin.php' )
				),
				__( 'Open Activity', 'karetaker' ),
				self::codes_hit( $codes, array( 'uploads_php_found', 'uploads_config_changed' ) )
			),
			self::item(
				'integrity',
				__( 'Core, critical files, and checksums', 'karetaker' ),
				__( 'Unexpected changes to wp-config, root .htaccess, or files that no longer match wordpress.org need a human review.', 'karetaker' ),
				add_query_arg(
					array(
						'page'   => 'karetaker',
						'tab'    => 'activity',
						'kt_sev' => 'act',
					),
					admin_url( 'admin.php' )
				),
				__( 'Act-now Activity', 'karetaker' ),
				self::codes_hit( $codes, array( 'critical_file_changed', 'file_hash_mismatch', 'db_script_found', 'option_denylist_hit', 'script_domain_new', 'cloaking_suspected' ) )
			),
			self::item(
				'guard',
				__( 'One-checkbox Guard flags', 'karetaker' ),
				__( 'Search engine visibility, broken site email, missing admins, or an invalid admin email can look like “the site is fine” while it is not.', 'karetaker' ),
				add_query_arg(
					array(
						'page' => 'karetaker',
						'tab'  => 'overview',
					),
					admin_url( 'admin.php' )
				),
				__( 'Open Overview', 'karetaker' ),
				$bad > 0
			),
			self::item(
				'access',
				__( 'Passwords and sessions', 'karetaker' ),
				__( 'If anything above looks hostile, rotate administrator passwords, review application passwords, and sign out other sessions.', 'karetaker' ),
				admin_url( 'profile.php' ),
				__( 'Open your profile', 'karetaker' ),
				self::codes_hit( $codes, array( 'admin_login_new_ip', 'admin_login_new_device', 'login_failure_burst' ) )
			),
		);

		$needs = false;
		foreach ( $items as $item ) {
			if ( ! empty( $item['flagged'] ) ) {
				$needs = true;
				break;
			}
		}
		if ( count( $act ) > 0 ) {
			$needs = true;
		}

		return array(
			'at'          => gmdate( 'c' ),
			'lookback'    => self::LOOKBACK_DAYS,
			'act_count'   => count( $act ),
			'guard_bad'   => $bad,
			'needs_human' => $needs,
			'items'       => $items,
			'passes'      => $passes,
			'disclaimer'  => __( 'This is a watchtower checklist, not a malware cleaner and not a “site is safe” certificate. Karetaker does not remove files or lock logins.', 'karetaker' ),
		);
	}

	/**
	 * One checklist row.
	 *
	 * @since 1.0.0
	 * @param string $id Row id.
	 * @param string $title Title.
	 * @param string $help Help text.
	 * @param string $link URL.
	 * @param string $link_label Link label.
	 * @param bool   $flagged Whether recent signals hit this row.
	 * @return array<string, mixed>
	 */
	private static function item( $id, $title, $help, $link, $link_label, $flagged ) {
		return array(
			'id'         => sanitize_key( $id ),
			'title'      => $title,
			'help'       => $help,
			'link'       => $link,
			'link_label' => $link_label,
			'flagged'    => (bool) $flagged,
		);
	}

	/**
	 * Whether any of the listed event codes appear in the recent ACT map.
	 *
	 * @since 1.0.0
	 * @param array<string, int> $codes Code counts.
	 * @param string[]           $need Codes to test.
	 * @return bool
	 */
	private static function codes_hit( array $codes, array $need ) {
		foreach ( $need as $code ) {
			if ( ! empty( $codes[ $code ] ) ) {
				return true;
			}
		}
		return false;
	}
}
