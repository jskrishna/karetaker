<?php
/**
 * Downloads: activity log, software inventory, incident records, CRA evidence pack, client report.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Download handlers behind admin-post.php. Every handler checks a capability and a nonce.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Exports {

	const MONTHLY_HOOK = 'karetaker_monthly_report';

	/**
	 * Registers handlers and the monthly report schedule.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_karetaker_export', array( __CLASS__, 'handle' ) );
		add_action( self::MONTHLY_HOOK, array( __CLASS__, 'send_monthly' ) );
		add_action( 'init', array( __CLASS__, 'sync_monthly' ) );
	}

	/**
	 * Signed download URL.
	 *
	 * @since 1.0.0
	 * @param string               $type Export type.
	 * @param array<string, mixed> $args Extra query args.
	 * @return string
	 */
	public static function url( $type, array $args = array() ) {
		return wp_nonce_url(
			add_query_arg(
				array_merge(
					array(
						'action' => 'karetaker_export',
						'type'   => $type,
					),
					$args
				),
				admin_url( 'admin-post.php' )
			),
			'karetaker_export_' . $type
		);
	}

	/**
	 * Dispatches a download.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce checked per type below.
		$type   = isset( $_REQUEST['type'] ) ? sanitize_key( wp_unslash( $_REQUEST['type'] ) ) : '';
		$format = isset( $_REQUEST['format'] ) && 'json' === $_REQUEST['format'] ? 'json' : 'csv';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$cap = in_array( $type, array( 'activity' ), true ) ? 'karetaker_view_log' : 'manage_options';
		if ( in_array( $type, array( 'case' ), true ) ) {
			$cap = 'karetaker_resolve';
		}
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to download this file.', 'karetaker' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'karetaker_export_' . $type );

		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = is_string( $host ) && '' !== $host ? sanitize_file_name( $host ) : 'site';

		switch ( $type ) {
			case 'activity':
				self::activity( $format, $host );
				break;
			case 'inventory':
				self::inventory( $format, $host );
				break;
			case 'evidence':
				self::evidence( $host );
				break;
			case 'case':
				self::case_record( $format );
				break;
			case 'report':
				self::report( $host );
				break;
		}
		exit;
	}

	/**
	 * Sends download headers.
	 *
	 * @since 1.0.0
	 * @param string $name File name.
	 * @param string $mime Content type.
	 * @return void
	 */
	private static function headers( $name, $mime ) {
		nocache_headers();
		header( 'Content-Type: ' . $mime . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $name ) );
	}

	/**
	 * Activity log for the current filters.
	 *
	 * @since 1.0.0
	 * @param string $format csv or json.
	 * @param string $host   Site host.
	 * @return void
	 */
	private static function activity( $format, $host ) {
		$args = Karetaker_Admin_Data::log_filters();
		unset( $args['ui'] );
		$args['limit'] = 500;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked in handle().
		$ids  = isset( $_REQUEST['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_REQUEST['ids'] ) ) ) ) ) : array();
		$rows = array();
		for ( $offset = 0; $offset < 5000; $offset += 500 ) {
			$page = Karetaker_Events::query( array_merge( $args, array( 'offset' => $offset ) ) );
			foreach ( $page as $row ) {
				if ( ! $ids || in_array( (int) $row->id, $ids, true ) ) {
					$rows[] = $row;
				}
			}
			if ( count( $page ) < 500 ) {
				break;
			}
		}

		if ( 'json' === $format ) {
			self::headers( 'karetaker-events-' . $host . '.json', 'application/json' );
			$out = array();
			foreach ( $rows as $row ) {
				$out[] = array(
					'id'       => (int) $row->id,
					'time_utc' => (string) $row->event_time,
					'severity' => (int) $row->severity,
					'code'     => (string) $row->event_code,
					'category' => Karetaker_Issues::area( (string) $row->event_code ),
					'user_id'  => (int) $row->user_id,
					'ip'       => (string) $row->ip_display,
					'context'  => $row->context,
				);
			}
			echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			return;
		}

		self::headers( 'karetaker-events-' . $host . '.csv', 'text/csv' );
		$fh = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming CSV to output.
		fputcsv( $fh, array( 'id', 'time_utc', 'severity', 'code', 'category', 'user_id', 'ip', 'context' ) );
		foreach ( $rows as $row ) {
			fputcsv( $fh, array( (int) $row->id, (string) $row->event_time, (int) $row->severity, (string) $row->event_code, Karetaker_Issues::area( (string) $row->event_code ), (int) $row->user_id, (string) $row->ip_display, (string) wp_json_encode( $row->context ) ) );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with fopen above.
	}

	/**
	 * Software inventory rows.
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, string>>
	 */
	public static function inventory_rows() {
		$pack = Karetaker_Report::evidence_pack();
		$rows = array(
			array(
				'type'    => 'core',
				'name'    => 'WordPress',
				'version' => $pack['software']['wordpress'],
				'active'  => 'yes',
				'source'  => 'WordPress.org',
				'files'   => empty( $pack['core_verified'] ) ? 'unknown' : 'verified',
			),
			array(
				'type'    => 'runtime',
				'name'    => 'PHP',
				'version' => $pack['software']['php'],
				'active'  => 'yes',
				'source'  => '',
				'files'   => '',
			),
		);
		foreach ( $pack['software']['plugins'] as $plugin ) {
			$rows[] = array(
				'type'    => 'plugin',
				'name'    => $plugin['name'],
				'version' => $plugin['version'],
				'active'  => $plugin['active'] ? 'yes' : 'no',
				'source'  => 'not_found' === $plugin['directory'] ? 'Other' : 'WordPress.org',
				'files'   => $plugin['files'],
			);
		}
		foreach ( $pack['software']['themes'] as $theme ) {
			$rows[] = array(
				'type'    => 'theme',
				'name'    => $theme['name'],
				'version' => $theme['version'],
				'active'  => $theme['active'] ? 'yes' : 'no',
				'source'  => '',
				'files'   => 'unknown',
			);
		}
		return $rows;
	}

	/**
	 * Software inventory download.
	 *
	 * @since 1.0.0
	 * @param string $format csv or json.
	 * @param string $host   Site host.
	 * @return void
	 */
	private static function inventory( $format, $host ) {
		$rows = self::inventory_rows();
		if ( 'json' === $format ) {
			self::headers( 'karetaker-inventory-' . $host . '.json', 'application/json' );
			echo wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			return;
		}
		self::headers( 'karetaker-inventory-' . $host . '.csv', 'text/csv' );
		$fh = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming CSV to output.
		fputcsv( $fh, array_keys( $rows[0] ) );
		foreach ( $rows as $row ) {
			fputcsv( $fh, $row );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with fopen above.
	}

	/**
	 * CRA evidence pack: ZIP with inventory, events and incidents when ZipArchive exists, JSON otherwise.
	 *
	 * @since 1.0.0
	 * @param string $host Site host.
	 * @return void
	 */
	private static function evidence( $host ) {
		$pack  = Karetaker_Report::evidence_pack();
		$cases = array();
		foreach ( Karetaker_Cases::all() as $case ) {
			$cases[] = Karetaker_Cases::export( $case );
		}
		$pack['incidents'] = $cases;

		if ( ! class_exists( 'ZipArchive' ) ) {
			self::headers( 'karetaker-evidence-' . $host . '.json', 'application/json' );
			echo wp_json_encode( $pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			return;
		}

		$tmp = wp_tempnam( 'karetaker-evidence' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			self::headers( 'karetaker-evidence-' . $host . '.json', 'application/json' );
			echo wp_json_encode( $pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			return;
		}
		$zip->addFromString( 'inventory.json', (string) wp_json_encode( self::inventory_rows(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$zip->addFromString( 'security-events-90-days.json', (string) wp_json_encode( $pack['security_events_90_days'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$zip->addFromString( 'incidents.json', (string) wp_json_encode( $cases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		unset( $pack['security_events_90_days'], $pack['incidents'] );
		$zip->addFromString( 'summary.json', (string) wp_json_encode( $pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$zip->close();

		self::headers( 'karetaker-evidence-' . $host . '-' . gmdate( 'Y-m-d' ) . '.zip', 'application/zip' );
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming the temporary ZIP.
		wp_delete_file( $tmp );
	}

	/**
	 * Incident record download.
	 *
	 * @since 1.0.0
	 * @param string $format html or json.
	 * @return void
	 */
	private static function case_record( $format ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked in handle().
		$id   = isset( $_REQUEST['id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['id'] ) ) : '';
		$case = $id ? Karetaker_Cases::get( $id ) : null;
		if ( ! $case ) {
			$all  = Karetaker_Cases::all();
			$case = $all ? $all[0] : null;
		}
		if ( ! $case ) {
			wp_die( esc_html__( 'There are no incidents to export yet.', 'karetaker' ) );
		}
		if ( 'json' === $format ) {
			self::headers( strtolower( $case['id'] ) . '.json', 'application/json' );
			echo wp_json_encode( Karetaker_Cases::export( $case ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			return;
		}
		self::headers( strtolower( $case['id'] ) . '.html', 'text/html' );
		echo Karetaker_Cases::export_html( $case ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the renderer.
	}

	/**
	 * Client report: printable page (opens print dialog), or email to the client.
	 *
	 * @since 1.0.0
	 * @param string $host Site host.
	 * @return void
	 */
	private static function report( $host ) {
		unset( $host );
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Nonce checked in handle().
		$fields = array(
			'report_agency'       => isset( $_REQUEST['report_agency'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['report_agency'] ) ) : (string) Karetaker_Settings::get( 'report_agency' ),
			'report_client'       => isset( $_REQUEST['report_client'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['report_client'] ) ) : (string) Karetaker_Settings::get( 'report_client' ),
			'report_client_email' => isset( $_REQUEST['report_client_email'] ) ? sanitize_email( wp_unslash( $_REQUEST['report_client_email'] ) ) : (string) Karetaker_Settings::get( 'report_client_email' ),
			'report_logo_id'      => isset( $_REQUEST['report_logo_id'] ) ? absint( wp_unslash( $_REQUEST['report_logo_id'] ) ) : (int) Karetaker_Settings::get( 'report_logo_id' ),
			'report_include'      => isset( $_REQUEST['include'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_REQUEST['include'] ) ) : array(),
			'report_schedule'     => isset( $_REQUEST['schedule'] ) && 'monthly' === $_REQUEST['schedule'] ? 'monthly' : '',
		);
		$period = isset( $_REQUEST['period'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['period'] ) ) : '30';
		if ( 'custom' === $period ) {
			$from   = isset( $_REQUEST['from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['from'] ) ) : '';
			$to     = isset( $_REQUEST['to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['to'] ) ) : '';
			$period = 'custom:' . $from . ':' . $to;
		}
		$send = ! empty( $_REQUEST['send'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		if ( ! is_email( $fields['report_client_email'] ) ) {
			$fields['report_client_email'] = '';
		}
		Karetaker_Settings::update( $fields );
		self::sync_monthly();

		$report = Karetaker_Report::build( $period );
		if ( $send ) {
			$ok = self::email_report( $report );
			wp_safe_redirect( add_query_arg( 'kt_report', $ok ? 'sent' : 'failed', Karetaker_Admin::admin_page_url( 'reports' ) ) );
			return;
		}
		header( 'Content-Type: text/html; charset=utf-8' );
		echo Karetaker_Report::render_html( $report, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the renderer.
	}

	/**
	 * Emails a built report to the client address.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $report Report.
	 * @return bool
	 */
	private static function email_report( array $report ) {
		$to = (string) Karetaker_Settings::get( 'report_client_email' );
		if ( ! is_email( $to ) ) {
			return false;
		}
		$sent = wp_mail(
			$to,
			/* translators: 1: site name, 2: period */
			sprintf( __( 'Website security report: %1$s, %2$s', 'karetaker' ), (string) get_bloginfo( 'name' ), $report['period_label'] ),
			Karetaker_Report::render_html( $report ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
		if ( $sent ) {
			Karetaker_Events::record(
				'alerts_summary_sent',
				array(
					'kind' => 'client_report',
					'to'   => $to,
				)
			);
		}
		return (bool) $sent;
	}

	/**
	 * Keeps the daily check for the 1st-of-month report scheduled only when wanted.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function sync_monthly() {
		$want = 'monthly' === Karetaker_Settings::get( 'report_schedule' ) && is_email( (string) Karetaker_Settings::get( 'report_client_email' ) );
		$next = wp_next_scheduled( self::MONTHLY_HOOK );
		if ( $want && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::MONTHLY_HOOK );
		} elseif ( ! $want && $next ) {
			wp_unschedule_hook( self::MONTHLY_HOOK );
		}
	}

	/**
	 * On the 1st of the month, emails last month's report once.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function send_monthly() {
		if ( '1' !== wp_date( 'j' ) ) {
			return;
		}
		$key = 'm:' . ( new DateTimeImmutable( 'first day of last month', wp_timezone() ) )->format( 'Y-m' );
		if ( get_option( 'karetaker_monthly_sent' ) === $key ) {
			return;
		}
		if ( self::email_report( Karetaker_Report::build( $key ) ) ) {
			update_option( 'karetaker_monthly_sent', $key, false );
		}
	}
}
