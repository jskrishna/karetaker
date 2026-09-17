<?php
/**
 * Plugin Name: Karetaker
 * Plugin URI:  https://wordpress.org/plugins/karetaker/
 * Description: Watches a WordPress site for compromise signals and tells the owner only when something needs them.
 * Version:     1.1.1
 * Author:      Team Krikir
 * Author URI:  https://www.krikir.com/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:  false
 * Requires PHP: 7.4
 * Requires at least: 6.2
 * Text Domain: karetaker
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KARETAKER_VERSION', '1.1.1' );
define( 'KARETAKER_FILE', __FILE__ );
define( 'KARETAKER_DIR', plugin_dir_path( __FILE__ ) );
define( 'KARETAKER_SLUG', 'karetaker' );
define( 'KARETAKER_API', 1 );

/**
 * Whether the kill switch is engaged.
 *
 * @since 1.0.0
 * @return bool True when KARETAKER_DISABLE is set or the disable file exists.
 */
function karetaker_is_disabled() {
	if ( defined( 'KARETAKER_DISABLE' ) && KARETAKER_DISABLE ) {
		return true;
	}

	return file_exists( WP_CONTENT_DIR . '/karetaker-disable' );
}

require_once KARETAKER_DIR . 'includes/class-schema.php';
require_once KARETAKER_DIR . 'includes/class-settings.php';
require_once KARETAKER_DIR . 'includes/class-events.php';
require_once KARETAKER_DIR . 'includes/class-hooks.php';
require_once KARETAKER_DIR . 'includes/class-checksums.php';
require_once KARETAKER_DIR . 'includes/class-vulns.php';
require_once KARETAKER_DIR . 'includes/class-scanner.php';
require_once KARETAKER_DIR . 'includes/class-expected.php';
require_once KARETAKER_DIR . 'includes/class-guard.php';
require_once KARETAKER_DIR . 'includes/class-email.php';
require_once KARETAKER_DIR . 'includes/class-alerts.php';
require_once KARETAKER_DIR . 'includes/class-guidance.php';
require_once KARETAKER_DIR . 'includes/class-webhook.php';
require_once KARETAKER_DIR . 'includes/class-chat.php';
require_once KARETAKER_DIR . 'includes/class-summary.php';
require_once KARETAKER_DIR . 'includes/class-harden.php';
require_once KARETAKER_DIR . 'includes/class-status.php';
require_once KARETAKER_DIR . 'includes/class-report.php';
require_once KARETAKER_DIR . 'includes/class-incident.php';
require_once KARETAKER_DIR . 'includes/class-tower.php';
require_once KARETAKER_DIR . 'includes/class-routing.php';
require_once KARETAKER_DIR . 'includes/class-access.php';
require_once KARETAKER_DIR . 'includes/class-receipts.php';
require_once KARETAKER_DIR . 'includes/class-issues.php';
require_once KARETAKER_DIR . 'includes/class-admin-data.php';
require_once KARETAKER_DIR . 'includes/class-admin-watch.php';
require_once KARETAKER_DIR . 'includes/class-admin-rest.php';
require_once KARETAKER_DIR . 'includes/class-exports.php';

register_activation_hook( __FILE__, 'karetaker_activate' );
register_deactivation_hook( __FILE__, 'karetaker_deactivate' );

/**
 * Activation: install schema and schedule the scanner.
 *
 * @since 1.0.0
 * @return void
 */
function karetaker_activate() {
	Karetaker_Schema::activate();
	Karetaker_Scanner::schedule();
}

/**
 * Deactivation: unschedule the scanner (data retained).
 *
 * @since 1.0.0
 * @return void
 */
function karetaker_deactivate() {
	Karetaker_Scanner::unschedule();
	wp_unschedule_hook( Karetaker_Summary::WEEKLY_HOOK );
	wp_unschedule_hook( Karetaker_Summary::PAUSE_HOOK );
}

/**
 * Boot watchers when the kill switch is off.
 *
 * @since 1.0.0
 * @return void
 */
function karetaker_boot() {
	if ( karetaker_is_disabled() ) {
		return;
	}

	Karetaker_Events::init();
	Karetaker_Hooks::init();
	Karetaker_Scanner::init();
	Karetaker_Guard::init();
	Karetaker_Alerts::init();
	Karetaker_Webhook::init();
	Karetaker_Chat::init();
	Karetaker_Summary::init();
	Karetaker_Harden::init();
	Karetaker_Routing::init();
	Karetaker_Access::init();
	Karetaker_Admin_Rest::init();
	Karetaker_Exports::init();

	if ( is_admin() ) {
		require_once KARETAKER_DIR . 'includes/class-admin.php';
		require_once KARETAKER_DIR . 'includes/class-site-health.php';
		Karetaker_Admin::init();
		Karetaker_Site_Health::init();
	}

	/**
	 * Fires after Karetaker has booted, so add-ons can extend it.
	 *
	 * @since 1.0.2
	 * @param int $api Extension API version.
	 */
	do_action( 'karetaker_loaded', KARETAKER_API );

	// Add-ons may have added settings defaults while loading.
	Karetaker_Settings::flush();
}
add_action( 'plugins_loaded', 'karetaker_boot' );

/**
 * Run schema upgrades if needed.
 *
 * @since 1.0.0
 * @return void
 */
function karetaker_maybe_upgrade() {
	Karetaker_Schema::maybe_upgrade();
}
add_action( 'admin_init', 'karetaker_maybe_upgrade' );
add_action( 'karetaker_scan', 'karetaker_maybe_upgrade', 1 );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action( 'init', 'karetaker_maybe_upgrade', 1 );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once KARETAKER_DIR . 'includes/class-cli.php';
	WP_CLI::add_command( 'karetaker', 'Karetaker_CLI' );
}
