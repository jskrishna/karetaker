<?php
/**
 * Plugin Name: Karetaker
 * Description: Watches a WordPress site for the changes that indicate compromise, and tells the owner only when something needs them.
 * Version:     0.1.0
 * Author:      Team Krikir
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

define( 'KARETAKER_VERSION', '0.1.0' );
define( 'KARETAKER_FILE', __FILE__ );
define( 'KARETAKER_DIR', plugin_dir_path( __FILE__ ) );
define( 'KARETAKER_SLUG', 'karetaker' );

/**
 * Whether the kill switch is engaged.
 *
 * @since 0.1.0
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
require_once KARETAKER_DIR . 'includes/class-scanner.php';
require_once KARETAKER_DIR . 'includes/class-guard.php';
require_once KARETAKER_DIR . 'includes/class-alerts.php';
require_once KARETAKER_DIR . 'includes/class-guidance.php';
require_once KARETAKER_DIR . 'includes/class-webhook.php';
require_once KARETAKER_DIR . 'includes/class-harden.php';
require_once KARETAKER_DIR . 'includes/class-status.php';
require_once KARETAKER_DIR . 'includes/class-agency.php';

register_activation_hook( __FILE__, 'karetaker_activate' );
register_deactivation_hook( __FILE__, 'karetaker_deactivate' );

/**
 * Activation: install schema and schedule the scanner.
 *
 * @since 0.1.0
 * @return void
 */
function karetaker_activate() {
	Karetaker_Schema::activate();
	Karetaker_Scanner::schedule();
}

/**
 * Deactivation: unschedule the scanner (data retained).
 *
 * @since 0.1.0
 * @return void
 */
function karetaker_deactivate() {
	Karetaker_Scanner::unschedule();
}

/**
 * Boot watchers when the kill switch is off.
 *
 * @since 0.1.0
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
	Karetaker_Harden::init();
	Karetaker_Agency::init();

	if ( is_admin() ) {
		require_once KARETAKER_DIR . 'includes/class-admin.php';
		require_once KARETAKER_DIR . 'includes/class-list-table.php';
		require_once KARETAKER_DIR . 'includes/class-site-health.php';
		Karetaker_Admin::init();
		Karetaker_Site_Health::init();
	}
}
add_action( 'plugins_loaded', 'karetaker_boot' );

/**
 * Run schema upgrades if needed.
 *
 * @since 0.1.0
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
