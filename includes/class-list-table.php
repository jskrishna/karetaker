<?php
/**
 * Activity log list table.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for the Activity events log.
 *
 * @since 0.1.0
 * @package Karetaker
 */
class Karetaker_List_Table extends WP_List_Table {

	const PER_PAGE = 20;

	/**
	 * Active filter args for pagination links.
	 *
	 * @var array<string, mixed>
	 */
	private $filter_args = array();

	/**
	 * Sets up the activity events list table.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'karetaker_event',
				'plural'   => 'karetaker_events',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Parses Activity tab filter arguments from the request.
	 *
	 * @since 0.1.0
	 * @return array<string, mixed>
	 */
	public static function filter_args_from_request() {
		$args = array(
			'code'   => '',
			'since'  => '',
			'until'  => '',
			'search' => '',
		);

		if ( isset( $_GET['kt_code'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['code'] = sanitize_key( wp_unslash( $_GET['kt_code'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( isset( $_GET['kt_since'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw_since     = sanitize_text_field( wp_unslash( $_GET['kt_since'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['since'] = self::sanitize_date_input( $raw_since );
		}

		if ( isset( $_GET['kt_until'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw_until     = sanitize_text_field( wp_unslash( $_GET['kt_until'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['until'] = self::sanitize_date_input( $raw_until );
		}

		if ( isset( $_GET['kt_s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['search'] = sanitize_text_field( wp_unslash( $_GET['kt_s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$band = isset( $_GET['kt_sev'] ) ? sanitize_key( wp_unslash( $_GET['kt_sev'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'log' === $band ) {
			$args['severity'] = Karetaker_Events::SEVERITY_LOG;
		} elseif ( 'watch' === $band ) {
			$args['severity'] = Karetaker_Events::SEVERITY_ATTENTION;
		} elseif ( 'act' === $band ) {
			$args['severity'] = Karetaker_Events::SEVERITY_ACT;
		}

		return $args;
	}

	/**
	 * Normalises a date input to UTC MySQL datetime or empty string.
	 *
	 * @since 0.1.0
	 * @param string $raw Date or datetime string.
	 * @return string
	 */
	private static function sanitize_date_input( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			return $raw . ' 00:00:00';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $raw ) ) {
			return $raw;
		}

		return '';
	}

	/**
	 * Column headers for the activity table.
	 *
	 * @since 0.1.0
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'event_time' => __( 'Time (UTC)', 'karetaker' ),
			'severity'   => __( 'Severity', 'karetaker' ),
			'event_code' => __( 'Code', 'karetaker' ),
			'user_id'    => __( 'User', 'karetaker' ),
			'ip_display' => __( 'IP', 'karetaker' ),
			'context'    => __( 'Context', 'karetaker' ),
		);
	}

	/**
	 * Loads one page of events and pagination args.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function prepare_items() {
		$this->filter_args = self::filter_args_from_request();

		$per_page     = self::PER_PAGE;
		$current_page = max( 1, (int) $this->get_pagenum() );
		$total_items  = Karetaker_Events::count_filtered( $this->filter_args );

		$this->items = Karetaker_Events::query(
			array_merge(
				$this->filter_args,
				array(
					'limit'  => $per_page,
					'offset' => ( $current_page - 1 ) * $per_page,
				)
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Appends Activity filters to pagination URLs.
	 *
	 * @since 0.1.0
	 * @param string $which Which table nav.
	 * @return void
	 */
	protected function pagination( $which ) {
		if ( empty( $this->_pagination_args['total_items'] ) ) {
			return;
		}

		$base = add_query_arg(
			array_merge(
				array(
					'page' => Karetaker_Admin::PAGE_SLUG,
					'tab'  => 'activity',
				),
				self::filter_query_for_url( $this->filter_args )
			),
			admin_url( 'admin.php' )
		);

		$total_pages = (int) $this->_pagination_args['total_pages'];
		$current     = (int) $this->get_pagenum();

		echo '<div class="tablenav-pages kt-pagination">';
		echo '<span class="displaying-num">';
		echo esc_html(
			sprintf(
				/* translators: %s: number of items */
				_n( '%s item', '%s items', (int) $this->_pagination_args['total_items'], 'karetaker' ),
				number_format_i18n( (int) $this->_pagination_args['total_items'] )
			)
		);
		echo '</span>';

		if ( $total_pages > 1 ) {
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => $base . '%_%',
						'format'    => '&paged=%#%',
						'current'   => $current,
						'total'     => $total_pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
		}
		echo '</div>';
	}

	/**
	 * Maps internal filter args to URL query keys.
	 *
	 * @since 0.1.0
	 * @param array<string, mixed> $args Filter args.
	 * @return array<string, string>
	 */
	public static function filter_query_for_url( array $args ) {
		$query = array();

		if ( ! empty( $args['code'] ) ) {
			$query['kt_code'] = $args['code'];
		}

		if ( ! empty( $args['since'] ) ) {
			$query['kt_since'] = substr( (string) $args['since'], 0, 10 );
		}

		if ( ! empty( $args['until'] ) ) {
			$query['kt_until'] = substr( (string) $args['until'], 0, 10 );
		}

		if ( ! empty( $args['search'] ) ) {
			$query['kt_s'] = $args['search'];
		}

		if ( isset( $args['severity'] ) ) {
			if ( Karetaker_Events::SEVERITY_ACT === (int) $args['severity'] ) {
				$query['kt_sev'] = 'act';
			} elseif ( Karetaker_Events::SEVERITY_ATTENTION === (int) $args['severity'] ) {
				$query['kt_sev'] = 'watch';
			} elseif ( Karetaker_Events::SEVERITY_LOG === (int) $args['severity'] ) {
				$query['kt_sev'] = 'log';
			}
		}

		return $query;
	}

	/**
	 * Renders one table row with drawer metadata.
	 *
	 * @since 0.1.0
	 * @param object $item Event row.
	 * @return void
	 */
	public function single_row( $item ) {
		$context = is_array( $item->context ) ? $item->context : array();
		$json    = wp_json_encode( $context );
		if ( false === $json ) {
			$json = '{}';
		}

		$guidance = Karetaker_Guidance::for_event( (string) $item->event_code, $context );
		$guide    = wp_json_encode( $guidance );
		if ( false === $guide ) {
			$guide = '{}';
		}

		$severity = (int) $item->severity;

		echo '<tr class="karetaker-event-row" tabindex="0" role="button" data-karetaker-json="' . esc_attr( $json ) . '" data-karetaker-guidance="' . esc_attr( $guide ) . '" data-karetaker-severity="' . esc_attr( (string) $severity ) . '" data-karetaker-code="' . esc_attr( (string) $item->event_code ) . '" data-karetaker-time="' . esc_attr( (string) $item->event_time ) . '" data-karetaker-id="' . esc_attr( (string) (int) $item->id ) . '">';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Renders a single cell for the given column.
	 *
	 * @since 0.1.0
	 * @param object $item Event row object.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'event_time':
				return esc_html( (string) $item->event_time );

			case 'severity':
				return self::format_severity( (int) $item->severity );

			case 'event_code':
				return esc_html( (string) $item->event_code );

			case 'user_id':
				return esc_html( self::format_user( (int) $item->user_id ) );

			case 'ip_display':
				return esc_html( (string) $item->ip_display );

			case 'context':
				$context = is_array( $item->context ) ? $item->context : array();
				$summary = self::context_summary( $context );
				return esc_html( $summary );

			default:
				return '';
		}
	}

	/**
	 * Maps a severity int to a Log / Watch / Act-now badge.
	 *
	 * @since 0.1.0
	 * @param int $severity Severity constant.
	 * @return string Escaped HTML.
	 */
	public static function format_severity( $severity ) {
		if ( Karetaker_Events::SEVERITY_ACT === $severity ) {
			$label = __( 'Act-now', 'karetaker' );
			$class = 'kt-badge kt-badge--act';
		} elseif ( Karetaker_Events::SEVERITY_ATTENTION === $severity ) {
			$label = __( 'Watch', 'karetaker' );
			$class = 'kt-badge kt-badge--watch';
		} else {
			$label = __( 'Log', 'karetaker' );
			$class = 'kt-badge kt-badge--log';
		}

		return '<span class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Formats a user id for display.
	 *
	 * @since 0.1.0
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	public static function format_user( $user_id ) {
		if ( $user_id <= 0 ) {
			return __( 'Guest', 'karetaker' );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			/* translators: %d: user ID */
			return sprintf( __( 'Deleted (#%d)', 'karetaker' ), $user_id );
		}

		return sprintf( '%s (#%d)', $user->display_name, $user_id );
	}

	/**
	 * Builds a short human-readable context line from known keys.
	 *
	 * @since 0.1.0
	 * @param array $context Event context.
	 * @return string
	 */
	public static function context_summary( array $context ) {
		if ( empty( $context ) ) {
			return '—';
		}

		$parts = array();
		$keys  = array(
			'check',
			'plugin',
			'theme',
			'option',
			'error',
			'user_login',
			'login',
			'path',
			'file',
			'value',
			'role',
			'from',
			'to',
		);

		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $context ) ) {
				continue;
			}
			$val = $context[ $key ];
			if ( is_bool( $val ) ) {
				$val = $val ? '1' : '0';
			} elseif ( is_scalar( $val ) ) {
				$val = (string) $val;
			} else {
				continue;
			}
			if ( '' === $val ) {
				continue;
			}
			$parts[] = $key . '=' . $val;
			if ( count( $parts ) >= 3 ) {
				break;
			}
		}

		if ( empty( $parts ) ) {
			$json = wp_json_encode( $context );
			if ( false === $json ) {
				return '—';
			}
			if ( strlen( $json ) > 80 ) {
				return substr( $json, 0, 77 ) . '...';
			}
			return $json;
		}

		return implode( '; ', $parts );
	}

	/**
	 * Message when the events table is empty.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function no_items() {
		echo esc_html__( 'No events match these filters.', 'karetaker' );
	}
}
