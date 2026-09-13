<?php
/**
 * Uninstall routine.
 *
 * Drops the events table, settings option, scan-related options, and scheduled hooks.
 *
 * @package Karetaker
 * @since 0.1.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-schema.php';

Karetaker_Schema::uninstall();
Karetaker_Settings::uninstall();

foreach ( array( 'karetaker_scan', 'karetaker_digest' ) as $karetaker_hook ) {
	$karetaker_timestamp = wp_next_scheduled( $karetaker_hook );

	while ( $karetaker_timestamp ) {
		wp_unschedule_event( $karetaker_timestamp, $karetaker_hook );
		$karetaker_timestamp = wp_next_scheduled( $karetaker_hook );
	}
}

delete_option( 'karetaker_baseline' );
delete_option( 'karetaker_last_scan' );
delete_option( 'karetaker_scan_state' );
