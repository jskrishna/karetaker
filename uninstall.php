<?php
/**
 * Uninstall routine.
 *
 * @package Karetaker
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-schema.php';

Karetaker_Schema::uninstall();
Karetaker_Settings::uninstall();

foreach ( array( 'karetaker_scan', 'karetaker_digest' ) as $hook ) {
	$timestamp = wp_next_scheduled( $hook );

	while ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
		$timestamp = wp_next_scheduled( $hook );
	}
}

delete_option( 'karetaker_baseline' );
delete_option( 'karetaker_last_scan' );
delete_option( 'karetaker_scan_state' );
