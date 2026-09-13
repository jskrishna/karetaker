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

class Karetaker_List_Table extends WP_List_Table {

	const PER_PAGE = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'karetaker_event',
				'plural'   => 'karetaker_events',
				'ajax'     => false,
			)
		);
	}

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

	public function prepare_items() {
		$per_page     = self::PER_PAGE;
		$current_page = max( 1, (int) $this->get_pagenum() );
		$total_items  = Karetaker_Schema::count();

		$this->items = Karetaker_Events::query(
			array(
				'limit'  => $per_page,
				'offset' => ( $current_page - 1 ) * $per_page,
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

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'event_time':
				return esc_html( (string) $item->event_time );

			case 'severity':
				return esc_html( (string) (int) $item->severity );

			case 'event_code':
				return esc_html( (string) $item->event_code );

			case 'user_id':
				return esc_html( (string) (int) $item->user_id );

			case 'ip_display':
				return esc_html( (string) $item->ip_display );

			case 'context':
				$json = wp_json_encode( is_array( $item->context ) ? $item->context : array() );
				if ( false === $json ) {
					$json = '{}';
				}
				return esc_html( $json );

			default:
				return '';
		}
	}

	public function no_items() {
		echo esc_html__( 'No events recorded yet.', 'karetaker' );
	}
}
