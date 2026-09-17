<?php
/**
 * Real-time event listeners.
 *
 * @package Karetaker
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers WordPress hooks that record security-relevant events.
 *
 * @since 1.0.0
 */
class Karetaker_Hooks {

	const BURST_THRESHOLD = 10;
	const BURST_WINDOW    = 900;

	/**
	 * True while inserting a user we are already observing.
	 *
	 * @var bool
	 */
	private static $creating = false;

	/**
	 * Attaches action and filter callbacks for monitored site activity.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_pre_insert_user_data', array( __CLASS__, 'on_pre_insert_user' ), 10, 2 );
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 10, 1 );
		add_action( 'set_user_role', array( __CLASS__, 'on_set_user_role' ), 10, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'on_add_user_role' ), 10, 2 );
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ), 10, 3 );

		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'on_login_failed' ), 10, 1 );
		add_action( 'parse_request', array( __CLASS__, 'on_user_enum_probe' ), 1 );

		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_activated' ), 10, 1 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_deactivated' ), 10, 1 );
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'remember_plugin_version' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader_complete' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'on_theme_switched' ), 10, 1 );

		add_action( 'update_option_users_can_register', array( __CLASS__, 'on_registration_option' ), 10, 2 );
		add_action( 'update_option_default_role', array( __CLASS__, 'on_default_role_option' ), 10, 2 );
		add_action( 'update_option_admin_email', array( __CLASS__, 'on_admin_email' ), 10, 2 );

		add_action( 'wp_ajax_edit-theme-plugin-file', array( __CLASS__, 'on_file_editor' ), 0 );
	}

	/**
	 * Whether the user is an administrator or can manage options.
	 *
	 * @since 1.0.0
	 * @param mixed $user User object or other value.
	 * @return bool
	 */
	private static function is_admin_user( $user ) {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		return in_array( 'administrator', (array) $user->roles, true ) || $user->has_cap( 'manage_options' );
	}

	/**
	 * Tracks new-user inserts so role hooks during registration are ignored.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $data   User data passed to wp_insert_user.
	 * @param bool                 $update True when updating an existing user.
	 * @return array<string, mixed> Unmodified user data.
	 */
	public static function on_pre_insert_user( $data, $update ) {
		if ( ! $update ) {
			self::$creating = true;
		}

		return $data;
	}

	/**
	 * Records admin_user_added or user_created after registration completes.
	 *
	 * @since 1.0.0
	 * @param int $user_id New user ID.
	 * @return void
	 */
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

	/**
	 * Records role_escalated when a user's primary role becomes administrator.
	 *
	 * @since 1.0.0
	 * @param int    $user_id   User ID.
	 * @param string $role      New role slug.
	 * @param array  $old_roles Previous roles.
	 * @return void
	 */
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

	/**
	 * Records role_escalated when administrator is added as an extra role.
	 *
	 * @since 1.0.0
	 * @param int    $user_id User ID.
	 * @param string $role    Role slug being added.
	 * @return void
	 */
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

	/**
	 * Records user_deleted with login and prior admin status.
	 *
	 * @since 1.0.0
	 * @param int      $user_id  Deleted user ID.
	 * @param int|null $reassign Reassign posts user ID.
	 * @param mixed    $user     WP_User before deletion or other value.
	 * @return void
	 */
	public static function on_user_deleted( $user_id, $reassign, $user ) {
		Karetaker_Events::record(
			'user_deleted',
			array(
				'user_login' => $user instanceof WP_User ? $user->user_login : (string) $user_id,
				'was_admin'  => self::is_admin_user( $user ),
			)
		);
	}

	/**
	 * Records user_login with admin flag and the logging-in user's ID.
	 *
	 * @since 1.0.0
	 * @param string       $user_login Username.
	 * @param WP_User|null $user       User object when provided by the hook.
	 * @return void
	 */
	public static function on_login( $user_login, $user = null ) {
		if ( ! $user instanceof WP_User ) {
			$user = get_user_by( 'login', $user_login );
		}

		$is_admin = self::is_admin_user( $user );

		Karetaker_Events::record(
			'user_login',
			array(
				'user_login' => (string) $user_login,
				'is_admin'   => $is_admin,
			),
			$user instanceof WP_User ? $user->ID : 0
		);

		if ( $is_admin && $user instanceof WP_User ) {
			self::maybe_record_admin_login_novelty( $user );
		}
	}

	/**
	 * Alerts when an administrator signs in from a new IP or browser fingerprint.
	 *
	 * @since 1.0.0
	 * @param WP_User $user Administrator user.
	 * @return void
	 */
	private static function maybe_record_admin_login_novelty( WP_User $user ) {
		$ip = Karetaker_Events::client_ip();
		$ua = '';
		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$ua = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 300 );
		}
		$device = ( '' !== $ua ) ? md5( $ua ) : '';

		$meta_key = 'karetaker_login_seen';
		$seen     = get_user_meta( $user->ID, $meta_key, true );
		if ( ! is_array( $seen ) ) {
			$seen = array();
		}
		if ( ! isset( $seen['ips'] ) || ! is_array( $seen['ips'] ) ) {
			$seen['ips'] = array();
		}
		if ( ! isset( $seen['devices'] ) || ! is_array( $seen['devices'] ) ) {
			$seen['devices'] = array();
		}

		$now          = time();
		$known_ips    = $seen['ips'];
		$known_devs   = $seen['devices'];
		$has_baseline = ( count( $known_ips ) + count( $known_devs ) ) > 0;

		$new_ip     = ( '' !== $ip && ! isset( $known_ips[ $ip ] ) );
		$new_device = ( '' !== $device && ! isset( $known_devs[ $device ] ) );

		if ( $has_baseline && $new_ip ) {
			Karetaker_Events::record(
				'admin_login_new_ip',
				array(
					'user_login' => $user->user_login,
					'ip'         => $ip,
					'device'     => $device,
				),
				$user->ID
			);
		}

		if ( $has_baseline && $new_device ) {
			Karetaker_Events::record(
				'admin_login_new_device',
				array(
					'user_login' => $user->user_login,
					'ip'         => $ip,
					'device'     => $device,
					'ua'         => $ua,
				),
				$user->ID
			);
		}

		if ( '' !== $ip ) {
			$seen['ips'][ $ip ] = $now;
		}
		if ( '' !== $device ) {
			$seen['devices'][ $device ] = array(
				'at' => $now,
				'ua' => $ua,
			);
		}

		// Cap stored history so usermeta stays small.
		$seen['ips']     = self::trim_assoc_newest( $seen['ips'], 20 );
		$seen['devices'] = self::trim_assoc_newest( $seen['devices'], 20 );
		update_user_meta( $user->ID, $meta_key, $seen );
	}

	/**
	 * Keeps the newest N associative entries (values are timestamps or arrays with at).
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $map Map to trim.
	 * @param int                  $max Max entries.
	 * @return array<string, mixed>
	 */
	private static function trim_assoc_newest( array $map, $max ) {
		$max = max( 1, (int) $max );
		if ( count( $map ) <= $max ) {
			return $map;
		}

		$scores = array();
		foreach ( $map as $key => $val ) {
			if ( is_array( $val ) && isset( $val['at'] ) ) {
				$scores[ $key ] = (int) $val['at'];
			} else {
				$scores[ $key ] = (int) $val;
			}
		}
		arsort( $scores, SORT_NUMERIC );
		$keep = array_slice( array_keys( $scores ), 0, $max );
		$out  = array();
		foreach ( $keep as $key ) {
			$out[ $key ] = $map[ $key ];
		}
		return $out;
	}

	/**
	 * Counts failed logins per IP and records login_failure_burst at the threshold.
	 *
	 * @since 1.0.0
	 * @param string $username Username attempted on failure.
	 * @return void
	 */
	public static function on_login_failed( $username ) {
		$ip = Karetaker_Events::client_ip();

		if ( '' === $ip ) {
			return;
		}

		$recent = get_option( 'karetaker_login_failures', array() );
		$recent = is_array( $recent ) ? $recent : array();
		$now    = time();
		foreach ( $recent as $seen_ip => $row ) {
			if ( $now - (int) $row['last'] > DAY_IN_SECONDS ) {
				unset( $recent[ $seen_ip ] );
			}
		}
		$row           = isset( $recent[ $ip ] ) ? $recent[ $ip ] : array(
			'count' => 0,
			'first' => $now,
			'burst' => 0,
		);
		$row['count']  = (int) $row['count'] + 1;
		$row['last']   = $now;
		$row['user']   = substr( sanitize_user( (string) $username ), 0, 60 );
		$recent[ $ip ] = $row;
		uasort(
			$recent,
			static function ( $a, $b ) {
				return (int) $b['last'] - (int) $a['last'];
			}
		);
		update_option( 'karetaker_login_failures', array_slice( $recent, 0, 50, true ), false );

		$key   = 'karetaker_fails_' . md5( $ip );
		$count = (int) get_transient( $key ) + 1;

		set_transient( $key, $count, self::BURST_WINDOW );

		if ( $count < self::BURST_THRESHOLD || 0 !== ( $count % self::BURST_THRESHOLD ) ) {
			return;
		}

		$recent[ $ip ]['burst'] = $now;
		update_option( 'karetaker_login_failures', array_slice( $recent, 0, 50, true ), false );

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

	/**
	 * Records rate-limited probes of classic user-enumeration URLs.
	 *
	 * @since 1.0.0
	 * @param WP $wp Request object (unused).
	 * @return void
	 */
	public static function on_user_enum_probe( $wp ) {
		unset( $wp );

		if ( is_admin() || is_user_logged_in() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public probe observation.
		$author      = isset( $_GET['author'] ) ? sanitize_text_field( wp_unslash( $_GET['author'] ) ) : '';
		$author_name = isset( $_GET['author_name'] ) ? sanitize_text_field( wp_unslash( $_GET['author_name'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$via = '';
		if ( '' !== $author && ctype_digit( $author ) ) {
			$via = 'author_id';
		} elseif ( '' !== $author_name ) {
			$via = 'author_name';
		} else {
			return;
		}

		$ip  = Karetaker_Events::client_ip();
		$key = 'karetaker_enum_' . md5( $ip . '|' . $via );
		if ( false !== get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );

		$state = Karetaker_Scanner::state();
		if ( isset( $state['enum_expected'][ $via ] ) && $state['enum_expected'][ $via ] ) {
			return;
		}

		Karetaker_Events::record(
			'user_enum_probe',
			array(
				'via'     => $via,
				'blocked' => Karetaker_Harden::is_on( 'user_enum' ) ? 1 : 0,
			),
			0
		);
	}

	/**
	 * Records plugin_activated with the plugin basename.
	 *
	 * @since 1.0.0
	 * @param string $plugin Plugin file relative to wp-content/plugins.
	 * @return void
	 */
	public static function on_plugin_activated( $plugin ) {
		Karetaker_Events::record( 'plugin_activated', array( 'plugin' => (string) $plugin ) );
	}

	/**
	 * Plugin versions captured just before an update installs.
	 *
	 * @var array<string, string>
	 */
	private static $versions_before = array();

	/**
	 * Remembers a plugin's version before the upgrader replaces it.
	 *
	 * @since 1.0.0
	 * @param bool|WP_Error $response   Pass-through value.
	 * @param array         $hook_extra Upgrader context.
	 * @return bool|WP_Error
	 */
	public static function remember_plugin_version( $response, $hook_extra ) {
		if ( is_array( $hook_extra ) && ! empty( $hook_extra['plugin'] ) ) {
			$file = (string) $hook_extra['plugin'];
			if ( file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				if ( ! function_exists( 'get_plugin_data' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$data                           = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
				self::$versions_before[ $file ] = isset( $data['Version'] ) ? (string) $data['Version'] : '';
				Karetaker_Receipts::before( $file );
			}
		}
		return $response;
	}

	/**
	 * Records plugin_updated with before and after versions.
	 *
	 * @since 1.0.0
	 * @param WP_Upgrader $upgrader   Upgrader instance.
	 * @param array       $hook_extra Upgrader context.
	 * @return void
	 */
	public static function on_upgrader_complete( $upgrader, $hook_extra ) {
		unset( $upgrader );
		if ( ! is_array( $hook_extra ) || ( isset( $hook_extra['type'] ) && 'plugin' !== $hook_extra['type'] ) || ( isset( $hook_extra['action'] ) && 'update' !== $hook_extra['action'] ) ) {
			return;
		}
		$files = array();
		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$files = $hook_extra['plugins'];
		} elseif ( ! empty( $hook_extra['plugin'] ) ) {
			$files = array( $hook_extra['plugin'] );
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( $files as $file ) {
			$file = (string) $file;
			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				continue;
			}
			$data    = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
			$name    = isset( $data['Name'] ) ? (string) $data['Name'] : $file;
			$from    = isset( self::$versions_before[ $file ] ) ? self::$versions_before[ $file ] : '';
			$to      = isset( $data['Version'] ) ? (string) $data['Version'] : '';
			$receipt = Karetaker_Receipts::after( $file, $name, $from, $to );
			Karetaker_Events::record(
				'plugin_updated',
				array(
					'plugin'        => $file,
					'slug'          => Karetaker_Checksums::plugin_slug( $file ),
					'name'          => $name,
					'from'          => $from,
					'to'            => $to,
					'files_changed' => $receipt ? (int) $receipt['changed'] : null,
				)
			);
		}
	}

	/**
	 * Records plugin_deactivated with the plugin basename.
	 *
	 * @since 1.0.0
	 * @param string $plugin Plugin file relative to wp-content/plugins.
	 * @return void
	 */
	public static function on_plugin_deactivated( $plugin ) {
		Karetaker_Events::record( 'plugin_deactivated', array( 'plugin' => (string) $plugin ) );
	}

	/**
	 * Records theme_switched with the new theme name.
	 *
	 * @since 1.0.0
	 * @param string $new_name Display name of the active theme.
	 * @return void
	 */
	public static function on_theme_switched( $new_name ) {
		Karetaker_Events::record( 'theme_switched', array( 'theme' => (string) $new_name ) );
	}

	/**
	 * Records registration_opened or setting_changed when users_can_register updates.
	 *
	 * @since 1.0.0
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 * @return void
	 */
	public static function on_registration_option( $old_value, $new_value ) {
		if ( (string) $old_value === (string) $new_value ) {
			return;
		}

		if ( ! $new_value ) {
			Karetaker_Events::record(
				'setting_changed',
				array(
					'option' => 'users_can_register',
					'value'  => '0',
				)
			);

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

	/**
	 * Records registration_opened or setting_changed when default_role updates.
	 *
	 * @since 1.0.0
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 * @return void
	 */
	public static function on_default_role_option( $old_value, $new_value ) {
		if ( (string) $old_value === (string) $new_value ) {
			return;
		}

		if ( 'administrator' !== $new_value ) {
			Karetaker_Events::record(
				'setting_changed',
				array(
					'option' => 'default_role',
					'value'  => (string) $new_value,
				)
			);

			return;
		}

		Karetaker_Events::record(
			'registration_opened',
			array(
				'option'             => 'default_role',
				'value'              => 'administrator',
				'users_can_register' => (string) get_option( 'users_can_register' ),
			)
		);
	}

	/**
	 * Records admin_email_changed when the site admin email option changes.
	 *
	 * @since 1.0.0
	 * @param mixed $old_value Previous email.
	 * @param mixed $new_value New email.
	 * @return void
	 */
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

	/**
	 * Records file_editor_used when the theme/plugin file editor AJAX runs.
	 *
	 * Observes core's `edit-theme-plugin-file` action; verifies the same nonce
	 * and capabilities WordPress uses before writing an event.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function on_file_editor() {
		if ( ! isset( $_POST['nonce'] ) || ! isset( $_POST['file'] ) ) {
			return;
		}

		$nonce  = sanitize_text_field( wp_unslash( $_POST['nonce'] ) );
		$file   = sanitize_text_field( wp_unslash( $_POST['file'] ) );
		$plugin = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		$theme  = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '';
		$valid  = false;

		if ( '' !== $plugin ) {
			if ( ! current_user_can( 'edit_plugins' ) ) {
				return;
			}
			$valid = (bool) wp_verify_nonce( $nonce, 'edit-plugin_' . $file );
		} elseif ( '' !== $theme ) {
			if ( ! current_user_can( 'edit_themes' ) ) {
				return;
			}
			$valid = (bool) wp_verify_nonce( $nonce, 'edit-theme_' . $theme . '_' . $file );
		}

		if ( ! $valid ) {
			return;
		}

		Karetaker_Events::record( 'file_editor_used', array( 'file' => $file ) );
	}
}
