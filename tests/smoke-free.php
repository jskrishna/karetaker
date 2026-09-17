<?php
/**
 * WP-CLI smoke test: wp eval-file tests/smoke-free.php
 *
 * @package Karetaker
 */

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput

global $karetaker_smoke_failed; // wp eval-file runs this file inside a function.
$karetaker_smoke_failed = 0;

/**
 * Prints one check result.
 *
 * @param bool   $ok    Result.
 * @param string $label What was checked.
 * @return void
 */
function karetaker_smoke_assert( $ok, $label ) {
	global $karetaker_smoke_failed;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		++$karetaker_smoke_failed;
	}
}

karetaker_smoke_assert( defined( 'KARETAKER_VERSION' ), 'plugin loaded' );
karetaker_smoke_assert( class_exists( 'Karetaker_Scanner' ), 'scanner class' );
karetaker_smoke_assert( class_exists( 'Karetaker_Issues' ), 'issues class' );
karetaker_smoke_assert( class_exists( 'Karetaker_Routing' ), 'routing class' );
karetaker_smoke_assert( false !== wp_next_scheduled( 'karetaker_scan' ), 'scan scheduled' );
karetaker_smoke_assert( isset( rest_get_server()->get_routes()['/karetaker/v1/admin/setting'] ), 'admin REST routes' );

karetaker_smoke_assert( defined( 'KARETAKER_API' ) && 1 === KARETAKER_API, 'KARETAKER_API is 1' );
karetaker_smoke_assert( did_action( 'karetaker_loaded' ) > 0, 'karetaker_loaded fired' );

if ( ! class_exists( 'Karetaker_Admin' ) ) {
	require_once KARETAKER_DIR . 'includes/class-admin.php';
}
$karetaker_tabs = Karetaker_Admin::tabs();
karetaker_smoke_assert( isset( $karetaker_tabs['home']['file'] ) && is_readable( $karetaker_tabs['home']['file'] ), 'tab registry has home' );
add_filter(
	'karetaker_admin_tabs',
	static function ( $tabs ) {
		$tabs['smoke'] = array(
			'label' => 'Smoke',
			'cap'   => 'manage_options',
			'file'  => __FILE__,
			'group' => 'pro',
		);
		return $tabs;
	}
);
karetaker_smoke_assert( isset( Karetaker_Admin::tabs( true )['smoke'] ), 'karetaker_admin_tabs filter applies' );
remove_all_filters( 'karetaker_admin_tabs' );
Karetaker_Admin::tabs( true );

add_filter(
	'karetaker_repeat_ttl',
	static function () {
		return 123;
	}
);
karetaker_smoke_assert( 123 === Karetaker_Routing::repeat_ttl(), 'karetaker_repeat_ttl filter' );
remove_all_filters( 'karetaker_repeat_ttl' );

Karetaker_Settings::update( array( 'alert_email' => 'owner@example.com' ) );
karetaker_smoke_assert( true === Karetaker_Routing::should_send( 'email', Karetaker_Events::SEVERITY_ACT ), 'email gets Act now alerts by default' );

add_filter( 'karetaker_alerts_held', '__return_true' );
karetaker_smoke_assert( true === Karetaker_Routing::held(), 'karetaker_alerts_held filter' );
karetaker_smoke_assert( false === Karetaker_Routing::should_send( 'email', Karetaker_Events::SEVERITY_ACT ), 'held blocks sending' );
remove_all_filters( 'karetaker_alerts_held' );

add_filter( 'karetaker_should_send', '__return_false' );
karetaker_smoke_assert( false === Karetaker_Routing::should_send( 'email', Karetaker_Events::SEVERITY_ACT ), 'karetaker_should_send filter' );
remove_all_filters( 'karetaker_should_send' );

add_filter(
	'karetaker_status_snapshot',
	static function ( $snapshot ) {
		$snapshot['smoke'] = 1;
		return $snapshot;
	}
);
karetaker_smoke_assert( isset( Karetaker_Status::snapshot()['smoke'] ), 'karetaker_status_snapshot filter' );
remove_all_filters( 'karetaker_status_snapshot' );

$karetaker_editor = wp_insert_user(
	array(
		'user_login' => 'kt_smoke_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password(),
		'role'       => 'editor',
	)
);
if ( ! is_wp_error( $karetaker_editor ) ) {
	Karetaker_Settings::update( array( 'admins_only' => false ) );
	karetaker_smoke_assert( ! user_can( $karetaker_editor, 'karetaker_view' ), 'editor has no access by default' );
	add_filter(
		'karetaker_grant_caps',
		static function ( $allcaps ) {
			$allcaps['karetaker_view'] = true;
			return $allcaps;
		}
	);
	karetaker_smoke_assert( user_can( $karetaker_editor, 'karetaker_view' ), 'karetaker_grant_caps filter' );
	remove_all_filters( 'karetaker_grant_caps' );
	Karetaker_Settings::update( array( 'admins_only' => true ) );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $karetaker_editor );
}

// API checks are appended by later tasks above this line.

if ( $karetaker_smoke_failed ) {
	WP_CLI::error( $karetaker_smoke_failed . ' check(s) failed.' );
}
WP_CLI::success( 'All checks passed.' );
