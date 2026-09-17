<?php
/**
 * Downloads: the activity log.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Download handler behind admin-post.php. It checks a capability and a nonce.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Exports {

	/**
	 * Registers the download handler.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_karetaker_export', array( __CLASS__, 'handle' ) );
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
		if ( 'activity' !== $type ) {
			wp_die( esc_html__( 'Unknown download.', 'karetaker' ), '', array( 'response' => 404 ) );
		}
		if ( ! current_user_can( 'karetaker_view_log' ) ) {
			wp_die( esc_html__( 'You do not have permission to download this file.', 'karetaker' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'karetaker_export_' . $type );

		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = is_string( $host ) && '' !== $host ? sanitize_file_name( $host ) : 'site';

		self::activity( $format, $host );
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
}
