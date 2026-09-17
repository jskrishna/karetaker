<?php
/**
 * Uninstall routine.
 *
 * Drops the events table, settings option, scan-related options, and scheduled hooks.
 *
 * @package Karetaker
 * @since 1.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-schema.php';

Karetaker_Schema::uninstall();
Karetaker_Settings::uninstall();

foreach ( array( 'karetaker_scan', 'karetaker_digest', 'karetaker_pause_end', 'karetaker_window_tick', 'karetaker_monthly_report' ) as $karetaker_hook ) {
	wp_unschedule_hook( $karetaker_hook );
}

delete_option( 'karetaker_baseline' );
delete_option( 'karetaker_last_scan' );
delete_option( 'karetaker_scan_state' );
delete_option( 'karetaker_incident_last' );
delete_option( 'karetaker_mail_ok_at' );
delete_option( 'karetaker_issue_state' );
delete_option( 'karetaker_cases' );
delete_option( 'karetaker_api_tokens' );
delete_option( 'karetaker_update_receipts' );
delete_option( 'karetaker_login_failures' );
delete_option( 'karetaker_onboarded' );
delete_option( 'karetaker_weekly_slot' );
delete_option( 'karetaker_monthly_sent' );
delete_metadata( 'user', 0, 'karetaker_saved_views', '', true );
delete_metadata( 'user', 0, 'karetaker_login_seen', '', true );
