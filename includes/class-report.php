<?php
/**
 * On-demand client-facing status report.
 *
 * @package Karetaker
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a shareable HTML summary for agencies and site owners.
 *
 * Local only: generated on demand, never emailed automatically.
 *
 * @since 1.0.0
 */
class Karetaker_Report {

	const DAYS_MIN     = 7;
	const DAYS_DEFAULT = 30;
	const DAYS_MAX     = 90;
	const ACT_LIST_CAP = 25;

	/**
	 * Normalise a reporting window in days.
	 *
	 * @since 1.0.0
	 * @param int $days Requested days.
	 * @return int
	 */
	public static function sanitize_days( $days ) {
		$days = (int) $days;
		if ( $days < self::DAYS_MIN ) {
			return self::DAYS_DEFAULT;
		}
		if ( $days > self::DAYS_MAX ) {
			return self::DAYS_MAX;
		}
		return $days;
	}

	/**
	 * Report periods: rolling windows, the last twelve calendar months, and custom.
	 *
	 * @since 1.0.0
	 * @return array<string, string> Period key => label.
	 */
	public static function periods() {
		$tz    = wp_timezone();
		$out   = array(
			'7'  => __( 'Last 7 days', 'karetaker' ),
			'30' => __( 'Last 30 days', 'karetaker' ),
			'90' => __( 'Last 90 days', 'karetaker' ),
		);
		$month = new DateTimeImmutable( 'first day of this month 00:00', $tz );
		for ( $i = 0; $i < 12; $i++ ) {
			$m                                 = $month->modify( '-' . $i . ' months' );
			$out[ 'm:' . $m->format( 'Y-m' ) ] = wp_date( 'F Y', $m->getTimestamp() );
		}
		$out['custom'] = __( 'Custom…', 'karetaker' );
		return $out;
	}

	/**
	 * Resolves a period key to a UTC window.
	 *
	 * @since 1.0.0
	 * @param string|int $period Period key: days, m:YYYY-MM, custom:YYYY-MM-DD:YYYY-MM-DD, this_month, last_month.
	 * @return array{since: int, until: int, label: string, days: int, key: string}
	 */
	public static function period_range( $period ) {
		$period = (string) $period;
		$tz     = wp_timezone();

		if ( 'this_month' === $period || 'last_month' === $period ) {
			$period = 'm:' . ( new DateTimeImmutable( 'first day of ' . ( 'this_month' === $period ? 'this' : 'last' ) . ' month', $tz ) )->format( 'Y-m' );
		}

		if ( preg_match( '/^m:(\d{4})-(\d{2})$/', $period, $m ) ) {
			$start = new DateTimeImmutable( $m[1] . '-' . $m[2] . '-01 00:00', $tz );
			$until = min( time(), $start->modify( 'first day of next month' )->getTimestamp() - 1 );
			return self::range_row( $start->getTimestamp(), $until, wp_date( 'F Y', $start->getTimestamp() ), $period );
		}

		if ( preg_match( '/^custom:(\d{4}-\d{2}-\d{2}):(\d{4}-\d{2}-\d{2})$/', $period, $m ) ) {
			$from  = ( new DateTimeImmutable( $m[1] . ' 00:00', $tz ) )->getTimestamp();
			$until = min( time(), ( new DateTimeImmutable( $m[2] . ' 23:59:59', $tz ) )->getTimestamp() );
			if ( $from < $until ) {
				/* translators: 1: start date, 2: end date */
				return self::range_row( $from, $until, sprintf( __( '%1$s to %2$s', 'karetaker' ), wp_date( (string) get_option( 'date_format' ), $from ), wp_date( (string) get_option( 'date_format' ), $until ) ), $period );
			}
		}

		$days = self::sanitize_days( (int) $period );
		/* translators: %d: number of days */
		return self::range_row( time() - $days * DAY_IN_SECONDS, time(), sprintf( __( 'Last %d days', 'karetaker' ), $days ), (string) $days );
	}

	/**
	 * Builds a period row.
	 *
	 * @since 1.0.0
	 * @param int    $since Start.
	 * @param int    $until End.
	 * @param string $label Label.
	 * @param string $key   Key.
	 * @return array{since: int, until: int, label: string, days: int, key: string}
	 */
	private static function range_row( $since, $until, $label, $key ) {
		return array(
			'since' => (int) $since,
			'until' => (int) $until,
			'label' => $label,
			'days'  => max( 1, (int) ceil( ( $until - $since ) / DAY_IN_SECONDS ) ),
			'key'   => $key,
		);
	}

	/**
	 * Collect facts for a printable client report.
	 *
	 * @since 1.0.0
	 * @param int $days Window length.
	 * @return array<string, mixed>
	 */
	public static function build( $days = self::DAYS_DEFAULT ) {
		$range = self::period_range( $days );
		$days  = $range['days'];
		$since = gmdate( 'Y-m-d H:i:s', $range['since'] );
		$until = gmdate( 'Y-m-d H:i:s', $range['until'] );
		$state = Karetaker_Scanner::state();
		$guard = isset( $state['guard'] ) && is_array( $state['guard'] ) ? $state['guard'] : array();

		$act_rows   = Karetaker_Events::query(
			array(
				'min_severity' => Karetaker_Events::SEVERITY_ACT,
				'since'        => $since,
				'until'        => $until,
				'limit'        => 500,
			)
		);
		$watch_rows = Karetaker_Events::query(
			array(
				'severity' => Karetaker_Events::SEVERITY_ATTENTION,
				'since'    => $since,
				'until'    => $until,
				'limit'    => 500,
			)
		);

		$guard_flags = array();
		$bad_count   = 0;
		foreach ( Karetaker_Guard::CHECKS as $check ) {
			$entry = isset( $guard[ $check ] ) && is_array( $guard[ $check ] ) ? $guard[ $check ] : array();
			$bad   = ! empty( $entry['bad'] );
			if ( $bad ) {
				++$bad_count;
			}
			$guard_flags[] = array(
				'check' => (string) $check,
				'label' => self::guard_label( $check ),
				'bad'   => $bad,
				'since' => ! empty( $entry['since'] ) ? (string) $entry['since'] : '',
			);
		}

		$harden_on     = array();
		$harden_labels = array(
			'headers'       => __( 'Security headers', 'karetaker' ),
			'xmlrpc'        => __( 'Disable XML-RPC', 'karetaker' ),
			'file_editor'   => __( 'Block file editor', 'karetaker' ),
			'user_enum'     => __( 'Block user enumeration', 'karetaker' ),
			'version'       => __( 'Hide WordPress version', 'karetaker' ),
			'registration'  => __( 'Close registration', 'karetaker' ),
			'app_passwords' => __( 'Limit application passwords', 'karetaker' ),
		);
		foreach ( Karetaker_Settings::harden() as $key => $on ) {
			if ( ! $on ) {
				continue;
			}
			$key         = sanitize_key( (string) $key );
			$harden_on[] = isset( $harden_labels[ $key ] ) ? $harden_labels[ $key ] : $key;
		}

		$act_list = array();
		foreach ( array_slice( $act_rows, 0, self::ACT_LIST_CAP ) as $row ) {
			$context    = isset( $row->context ) && is_array( $row->context ) ? $row->context : array();
			$guidance   = Karetaker_Guidance::for_event( (string) $row->event_code, $context );
			$act_list[] = array(
				'time'    => (string) $row->event_time,
				'code'    => (string) $row->event_code,
				'title'   => ! empty( $guidance['title'] ) ? (string) $guidance['title'] : (string) $row->event_code,
				'summary' => ! empty( $guidance['summary'] ) ? (string) $guidance['summary'] : '',
			);
		}

		$act_count   = count( $act_rows );
		$watch_count = count( $watch_rows );
		$verdict     = 'quiet';
		if ( $act_count > 0 || $bad_count > 0 ) {
			$verdict = 'act';
		} elseif ( $watch_count > 0 ) {
			$verdict = 'attention';
		}

		$last_run = isset( $state['last_run'] ) ? (string) $state['last_run'] : '';
		$checks   = Karetaker_Events::count_filtered(
			array(
				'code'  => 'scan_ran',
				'since' => $since,
				'until' => $until,
			)
		);

		$resolved = array();
		foreach ( Karetaker_Issues::all( array( 'status' => 'resolved' ) ) as $issue ) {
			if ( $issue['at'] >= $range['since'] && $issue['at'] <= $range['until'] ) {
				$resolved[] = $issue['title'];
			}
		}
		$updates = Karetaker_Events::count_filtered(
			array(
				'code'  => 'plugin_updated',
				'since' => $since,
				'until' => $until,
			)
		);
		$risk    = array();
		$dir     = isset( $state['plugin_directory'] ) && is_array( $state['plugin_directory'] ) ? $state['plugin_directory'] : array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			$slug   = Karetaker_Checksums::plugin_slug( (string) $file );
			$status = isset( $dir[ $slug ]['status'] ) ? (string) $dir[ $slug ]['status'] : '';
			if ( in_array( $status, array( 'closed', 'abandoned' ), true ) ) {
				$risk[] = array(
					'slug'   => $slug,
					'status' => $status,
				);
			}
		}
		$recommend = array();
		if ( count( $harden_on ) < count( Karetaker_Harden::KEYS ) ) {
			/* translators: %d: number of protections that are off */
			$recommend[] = sprintf( _n( 'Turn on the %d remaining protection in Karetaker', 'Turn on the %d remaining protections in Karetaker', count( Karetaker_Harden::KEYS ) - count( $harden_on ), 'karetaker' ), count( Karetaker_Harden::KEYS ) - count( $harden_on ) );
		}
		if ( $risk ) {
			$recommend[] = __( 'Replace plugins that are closed or no longer maintained', 'karetaker' );
		}
		foreach ( Karetaker_Admin_Watch::people( $state ) as $admin ) {
			if ( 'off' === $admin['twofa'] ) {
				$recommend[] = __( 'Turn on two-factor sign-in for every administrator', 'karetaker' );
				break;
			}
		}

		return array(
			'site_name'       => (string) get_bloginfo( 'name' ),
			'resolved'        => $resolved,
			'updates'         => (int) $updates,
			'plugin_risk'     => $risk,
			'protections'     => count( $harden_on ),
			'protections_all' => count( Karetaker_Harden::KEYS ),
			'recommend'       => $recommend,
			'since_ts'        => $range['since'],
			'until_ts'        => $range['until'],
			'include'         => self::includes(),
			'agency'          => (string) Karetaker_Settings::get( 'report_agency' ),
			'period_key'      => $range['key'],
			'period_label'    => $range['label'],
			'logo_url'        => self::logo_url(),
			'client'          => (string) Karetaker_Settings::get( 'report_client' ),
			'checks'          => (int) $checks,
			'site_url'        => home_url( '/' ),
			'days'            => $days,
			'since'           => $since,
			'until'           => $until,
			'verdict'         => $verdict,
			'verdict_label'   => self::verdict_label( $verdict ),
			'verdict_summary' => self::verdict_summary( $verdict, $act_count, $watch_count, $bad_count, $days ),
			'last_scan'       => $last_run,
			'guard_bad'       => $bad_count,
			'guard_flags'     => $guard_flags,
			'harden_on'       => $harden_on,
			'act_count'       => $act_count,
			'watch_count'     => $watch_count,
			'act_events'      => $act_list,
			'act_truncated'   => $act_count > self::ACT_LIST_CAP,
			'generated_at'    => gmdate( 'c' ),
			'plugin_version'  => defined( 'KARETAKER_VERSION' ) ? KARETAKER_VERSION : '',
		);
	}

	/**
	 * Evidence pack: installed software, versions, protections and recent security events.
	 *
	 * Meant for record-keeping duties such as the EU Cyber Resilience Act; it lists facts,
	 * it does not claim compliance.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public static function evidence_pack() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$state     = Karetaker_Scanner::state();
		$directory = isset( $state['plugin_directory'] ) && is_array( $state['plugin_directory'] ) ? $state['plugin_directory'] : array();
		$verified  = isset( $state['plugin_verified'] ) && is_array( $state['plugin_verified'] ) ? $state['plugin_verified'] : array();
		$active    = (array) get_option( 'active_plugins', array() );
		$plugins   = array();
		foreach ( get_plugins() as $file => $data ) {
			$slug      = Karetaker_Checksums::plugin_slug( $file );
			$plugins[] = array(
				'name'      => (string) $data['Name'],
				'slug'      => $slug,
				'version'   => (string) $data['Version'],
				'active'    => in_array( $file, $active, true ),
				'directory' => isset( $directory[ $slug ]['status'] ) ? (string) $directory[ $slug ]['status'] : 'unknown',
				'files'     => isset( $verified[ $slug ]['status'] ) ? (string) $verified[ $slug ]['status'] : 'unknown',
			);
		}

		$themes = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$themes[] = array(
				'name'    => (string) $theme->get( 'Name' ),
				'slug'    => (string) $stylesheet,
				'version' => (string) $theme->get( 'Version' ),
				'active'  => get_stylesheet() === $stylesheet,
			);
		}

		$events = array();
		foreach ( Karetaker_Events::query(
			array(
				'min_severity' => Karetaker_Events::SEVERITY_ATTENTION,
				'since'        => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ),
				'limit'        => 500,
			)
		) as $row ) {
			$events[] = array(
				'id'       => (int) $row->id,
				'time_utc' => (string) $row->event_time,
				'severity' => Karetaker_Events::SEVERITY_ACT === (int) $row->severity ? 'act' : 'watch',
				'code'     => (string) $row->event_code,
				'context'  => $row->context,
			);
		}

		return array(
			'generated_utc'           => gmdate( 'c' ),
			'site'                    => array(
				'name' => (string) get_bloginfo( 'name' ),
				'url'  => home_url( '/' ),
			),
			'software'                => array(
				'wordpress' => (string) get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
				'karetaker' => KARETAKER_VERSION,
				'plugins'   => $plugins,
				'themes'    => $themes,
			),
			'core_verified'           => isset( $state['core_verified'] ) ? gmdate( 'c', (int) $state['core_verified'] ) : null,
			'last_scan_utc'           => isset( $state['last_run'] ) ? (string) $state['last_run'] : null,
			'harden'                  => Karetaker_Settings::harden(),
			'security_events_90_days' => $events,
		);
	}

	/**
	 * Sections chosen for the client report.
	 *
	 * @since 1.0.0
	 * @return string[] Any of summary, issues, plugins, harden, credit.
	 */
	public static function includes() {
		$value = Karetaker_Settings::get( 'report_include' );
		return is_array( $value ) ? array_values( array_intersect( array( 'summary', 'issues', 'plugins', 'harden', 'credit' ), $value ) ) : array( 'summary', 'issues', 'plugins', 'harden' );
	}

	/**
	 * URL of the agency logo chosen for reports, or empty.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function logo_url() {
		$id = (int) Karetaker_Settings::get( 'report_logo_id' );
		if ( $id < 1 ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $id, 'medium' );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Human verdict label.
	 *
	 * @since 1.0.0
	 * @param string $verdict quiet|attention|act.
	 * @return string
	 */
	public static function verdict_label( $verdict ) {
		$map = array(
			'quiet'     => __( 'Quiet period', 'karetaker' ),
			'attention' => __( 'Worth a look', 'karetaker' ),
			'act'       => __( 'Action needed', 'karetaker' ),
		);
		return isset( $map[ $verdict ] ) ? $map[ $verdict ] : $map['attention'];
	}

	/**
	 * One-sentence client-facing summary.
	 *
	 * @since 1.0.0
	 * @param string $verdict Verdict key.
	 * @param int    $act_count ACT events in window.
	 * @param int    $watch_count Watch events in window.
	 * @param int    $bad_count Open Guard flags.
	 * @param int    $days Window days.
	 * @return string
	 */
	public static function verdict_summary( $verdict, $act_count, $watch_count, $bad_count, $days ) {
		if ( 'act' === $verdict ) {
			return sprintf(
				/* translators: 1: days, 2: ACT count, 3: open Guard flags */
				__( 'Over the last %1$d days Karetaker recorded %2$d act-now signal(s) and %3$d open Guard flag(s). A human should review Activity before treating the site as clear.', 'karetaker' ),
				(int) $days,
				(int) $act_count,
				(int) $bad_count
			);
		}
		if ( 'attention' === $verdict ) {
			return sprintf(
				/* translators: 1: days, 2: watch count */
				__( 'Over the last %1$d days there were no act-now alerts, but %2$d watch signal(s) were logged. Review them when convenient.', 'karetaker' ),
				(int) $days,
				(int) $watch_count
			);
		}
		return sprintf(
			/* translators: %d: days */
			__( 'Over the last %d days Karetaker saw no act-now alerts and Guard is clear. This is a watchtower summary, not a penetration test or malware clean certificate.', 'karetaker' ),
			(int) $days
		);
	}

	/**
	 * Friendly Guard check label.
	 *
	 * @since 1.0.0
	 * @param string $check Guard key.
	 * @return string
	 */
	private static function guard_label( $check ) {
		$map   = array(
			'blog_public'         => __( 'Search engine visibility', 'karetaker' ),
			'mail_failed'         => __( 'Site email delivery', 'karetaker' ),
			'admin_email_invalid' => __( 'Admin email reachable', 'karetaker' ),
			'no_administrator'    => __( 'Administrator accounts present', 'karetaker' ),
		);
		$check = sanitize_key( $check );
		return isset( $map[ $check ] ) ? $map[ $check ] : $check;
	}

	/**
	 * Self-contained printable client report.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $report build() payload.
	 * @param bool                 $printable Open the print dialog when loaded.
	 * @return string
	 */
	public static function render_html( array $report, $printable = false ) {
		$inc    = (array) $report['include'];
		$agency = '' !== (string) $report['agency'] ? (string) $report['agency'] : (string) $report['site_name'];
		$client = '' !== (string) $report['client'] ? (string) $report['client'] : (string) $report['site_name'];
		$quiet  = 0 === (int) $report['act_count'];
		ob_start();
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php echo esc_html( $agency . ' — ' . $client . ' — ' . $report['period_label'] ); ?></title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1d2327;background:#f6f7f7;margin:0;padding:24px;line-height:1.5}
.paper{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:26px;max-width:680px;margin:0 auto}
.rh{display:flex;justify-content:space-between;gap:12px;padding-bottom:10px;border-bottom:2px solid #1d2327;margin-bottom:14px}
.logo{font-weight:800;font-size:17px;letter-spacing:-.02em}.m{color:#646970}
h3{font-size:18px;margin:0 0 2px}h4{font-size:14px;margin:18px 0 6px}
.k{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:14px 0}.k div{border:1px solid #dcdcde;border-radius:4px;padding:8px}.k b{display:block;font-size:20px}.k span{font-size:11.5px;color:#646970}
ul{margin:6px 0 12px;padding-left:18px}img{max-height:44px;max-width:200px;display:block;margin-bottom:6px}
@media print{body{background:#fff;padding:0}.paper{border:0}}
</style>
</head>
<body<?php echo $printable ? ' onload="window.print()"' : ''; ?>>
<div class="paper">
	<div class="rh">
		<div>
			<?php if ( ! empty( $report['logo_url'] ) ) : ?>
				<img src="<?php echo esc_url( (string) $report['logo_url'] ); ?>" alt="" />
			<?php endif; ?>
			<div class="logo"><?php echo esc_html( $agency ); ?></div>
			<span class="m"><?php echo esc_html__( 'Website security report', 'karetaker' ); ?></span>
		</div>
		<div style="text-align:right"><b><?php echo esc_html( $client ); ?></b><br><span class="m"><?php echo esc_html( (string) $report['period_label'] ); ?></span></div>
	</div>
		<?php if ( in_array( 'summary', $inc, true ) ) : ?>
	<h3><?php echo esc_html( $quiet ? __( 'No security issues this period.', 'karetaker' ) : __( 'Some things needed attention this period.', 'karetaker' ) ); ?></h3>
	<span class="m">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: site, 2: checks, 3: start date, 4: end date */
					__( '%1$s was checked %2$d times between %3$s and %4$s.', 'karetaker' ),
					wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
					(int) $report['checks'],
					wp_date( (string) get_option( 'date_format' ), (int) $report['since_ts'] ),
					wp_date( (string) get_option( 'date_format' ), (int) $report['until_ts'] )
				)
			);
			?>
	</span>
	<div class="k">
		<div><b><?php echo esc_html( number_format_i18n( (int) $report['checks'] ) ); ?></b><span><?php echo esc_html__( 'checks', 'karetaker' ); ?></span></div>
		<div><b><?php echo esc_html( number_format_i18n( (int) $report['act_count'] ) ); ?></b><span><?php echo esc_html__( 'urgent issues', 'karetaker' ); ?></span></div>
		<div><b><?php echo esc_html( number_format_i18n( (int) $report['updates'] ) ); ?></b><span><?php echo esc_html__( 'updates verified', 'karetaker' ); ?></span></div>
		<div><b><?php echo esc_html( (int) $report['protections'] . '/' . (int) $report['protections_all'] ); ?></b><span><?php echo esc_html__( 'protections on', 'karetaker' ); ?></span></div>
	</div>
	<?php endif; ?>
	<b><?php echo esc_html__( 'Work completed', 'karetaker' ); ?></b>
	<ul>
		<li><?php /* translators: %s: WordPress version */ echo esc_html( sprintf( __( 'WordPress %s and active plugins checked against official files', 'karetaker' ), get_bloginfo( 'version' ) ) ); ?></li>
		<?php if ( in_array( 'issues', $inc, true ) ) : ?>
			<?php foreach ( (array) $report['resolved'] as $title ) : ?>
				<li><?php /* translators: %s: issue title */ echo esc_html( sprintf( __( 'Resolved: %s', 'karetaker' ), $title ) ); ?></li>
			<?php endforeach; ?>
		<?php endif; ?>
	</ul>
		<?php if ( in_array( 'issues', $inc, true ) && ! empty( $report['act_events'] ) ) : ?>
	<h4><?php echo esc_html__( 'Urgent events', 'karetaker' ); ?></h4>
	<ul>
			<?php foreach ( (array) $report['act_events'] as $event ) : ?>
		<li><?php echo esc_html( (string) $event['title'] ); ?> <span class="m"><?php echo esc_html( (string) $event['time'] ); ?> UTC</span></li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>
		<?php if ( in_array( 'plugins', $inc, true ) ) : ?>
	<h4><?php echo esc_html__( 'Plugin risk', 'karetaker' ); ?></h4>
			<?php if ( $report['plugin_risk'] ) : ?>
		<ul>
				<?php foreach ( (array) $report['plugin_risk'] as $row ) : ?>
			<li><?php echo esc_html( $row['slug'] . ' — ' . ( 'closed' === $row['status'] ? __( 'closed on WordPress.org', 'karetaker' ) : __( 'not updated in 2+ years', 'karetaker' ) ) ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php else : ?>
		<p class="m"><?php echo esc_html__( 'No closed or unmaintained plugins are active.', 'karetaker' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
		<?php if ( in_array( 'harden', $inc, true ) ) : ?>
	<h4><?php echo esc_html__( 'Hardening', 'karetaker' ); ?></h4>
	<p class="m"><?php echo esc_html( $report['harden_on'] ? implode( ', ', (array) $report['harden_on'] ) : __( 'No protections are switched on.', 'karetaker' ) ); ?></p>
	<?php endif; ?>
		<?php if ( $report['recommend'] ) : ?>
	<b><?php echo esc_html__( 'Recommendations', 'karetaker' ); ?></b>
	<ul>
			<?php foreach ( (array) $report['recommend'] as $line ) : ?>
		<li><?php echo esc_html( $line ); ?></li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>
		<?php if ( in_array( 'credit', $inc, true ) ) : ?>
	<p class="m" style="font-size:12px"><?php echo esc_html__( 'Monitored by Karetaker', 'karetaker' ); ?></p>
	<?php endif; ?>
</div>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
