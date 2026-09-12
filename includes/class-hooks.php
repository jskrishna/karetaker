<?php
/**
 * Real-time event listeners.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Karetaker_Hooks {

	const BURST_THRESHOLD = 10;
	const BURST_WINDOW    = 900;

	private static $creating = false;

	public static function init() {
		add_filter( 'wp_pre_insert_user_data', array( __CLASS__, 'on_pre_insert_user' ), 10, 2 );
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 10, 1 );
		add_action( 'set_user_role', array( __CLASS__, 'on_set_user_role' ), 10, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'on_add_user_role' ), 10, 2 );
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ), 10, 3 );

		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'on_login_failed' ), 10, 1 );

		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_activated' ), 10, 1 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_deactivated' ), 10, 1 );
		add_action( 'switch_theme', array( __CLASS__, 'on_theme_switched' ), 10, 1 );

		add_action( 'update_option_users_can_register', array( __CLASS__, 'on_registration_option' ), 10, 2 );
		add_action( 'update_option_default_role', array( __CLASS__, 'on_default_role_option' ), 10, 2 );
		add_action( 'update_option_admin_email', array( __CLASS__, 'on_admin_email' ), 10, 2 );

		add_action( 'wp_ajax_edit-theme-plugin-file', array( __CLASS__, 'on_file_editor' ), 0 );
	}

	private static function is_admin_user( $user ) {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		return in_array( 'administrator', (array) $user->roles, true ) || $user->has_cap( 'manage_options' );
	}

	public static function on_pre_insert_user( $data, $update ) {
		if ( ! $update ) {
			self::$creating = true;
		}

		return $data;
	}

	public static function on_user_register( $user_id ) {
		self::$creating = false;

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		if ( self::is_admin_user( $user ) ) {
			Karetaker_Events::record(
				'admin_user_added',
				array(
					'user_login' => $user->user_login,
					'user_email' => $user->user_email,
					'roles'      => (array) $user->roles,
				)
			);

			return;
		}

		Karetaker_Events::record(
			'user_created',
			array(
				'user_login' => $user->user_login,
				'roles'      => (array) $user->roles,
			)
		);
	}

	public static function on_set_user_role( $user_id, $role, $old_roles ) {
		if ( self::$creating ) {
			return;
		}

		if ( 'administrator' !== $role ) {
			return;
		}

		if ( in_array( 'administrator', (array) $old_roles, true ) ) {
			return;
		}

		$user = get_userdata( $user_id );

		Karetaker_Events::record(
			'role_escalated',
			array(
				'user_login' => $user ? $user->user_login : (string) $user_id,
				'from'       => (array) $old_roles,
				'to'         => $role,
			)
		);
	}

	public static function on_add_user_role( $user_id, $role ) {
		if ( self::$creating || 'administrator' !== $role ) {
			return;
		}

		$user = get_userdata( $user_id );

		Karetaker_Events::record(
			'role_escalated',
			array(
				'user_login' => $user ? $user->user_login : (string) $user_id,
				'to'         => $role,
				'added'      => true,
			)
		);
	}

	public static function on_user_deleted( $user_id, $reassign, $user ) {
		Karetaker_Events::record(
			'user_deleted',
			array(
				'user_login' => $user instanceof WP_User ? $user->user_login : (string) $user_id,
				'was_admin'  => self::is_admin_user( $user ),
			)
		);
	}

	public static function on_login( $user_login, $user = null ) {
		Karetaker_Events::record(
			'user_login',
			array(
				'user_login' => (string) $user_login,
				'is_admin'   => self::is_admin_user( $user ),
			),
			$user instanceof WP_User ? $user->ID : 0
		);
	}

	public static function on_login_failed( $username ) {
		$ip = Karetaker_Events::client_ip();

		if ( '' === $ip ) {
			return;
		}

		$key   = 'karetaker_fails_' . md5( $ip );
		$count = (int) get_transient( $key ) + 1;

		set_transient( $key, $count, self::BURST_WINDOW );

		if ( self::BURST_THRESHOLD !== $count ) {
			return;
		}

		Karetaker_Events::record(
			'login_failure_burst',
			array(
				'attempts'     => $count,
				'window_secs'  => self::BURST_WINDOW,
				'last_attempt' => (string) $username,
			),
			0
		);
	}

	public static function on_plugin_activated( $plugin ) {
		Karetaker_Events::record( 'plugin_activated', array( 'plugin' => (string) $plugin ) );
	}

	public static function on_plugin_deactivated( $plugin ) {
		Karetaker_Events::record( 'plugin_deactivated', array( 'plugin' => (string) $plugin ) );
	}

	public static function on_theme_switched( $new_name ) {
		Karetaker_Events::record( 'theme_switched', array( 'theme' => (string) $new_name ) );
	}

	public static function on_registration_option( $old_value, $new_value ) {
		if ( (string) $old_value === (string) $new_value ) {
			return;
		}

		if ( ! $new_value ) {
			Karetaker_Events::record( 'setting_changed', array( 'option' => 'users_can_register', 'value' => '0' ) );

			return;
		}

		Karetaker_Events::record(
			'registration_opened',
			array(
				'option'       => 'users_can_register',
				'default_role' => (string) get_option( 'default_role' ),
			)
		);
	}

	public static function on_default_role_option( $old_value, $new_value ) {
		if ( (string) $old_value === (string) $new_value ) {
			return;
		}

		if ( 'administrator' !== $new_value ) {
			Karetaker_Events::record( 'setting_changed', array( 'option' => 'default_role', 'value' => (string) $new_value ) );

			return;
		}

		Karetaker_Events::record(
			'registration_opened',
			array(
				'option'            => 'default_role',
				'value'             => 'administrator',
				'users_can_register' => (string) get_option( 'users_can_register' ),
			)
		);
	}

	public static function on_admin_email( $old_value, $new_value ) {
		if ( (string) $old_value === (string) $new_value ) {
			return;
		}

		Karetaker_Events::record(
			'admin_email_changed',
			array(
				'from' => (string) $old_value,
				'to'   => (string) $new_value,
			)
		);
	}

	public static function on_file_editor() {
		$file = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		Karetaker_Events::record( 'file_editor_used', array( 'file' => $file ) );
	}
}
