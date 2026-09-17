<?php
/**
 * One-checkbox catastrophe checks.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-checkbox catastrophe checks and sticky state.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Guard {

	const CHECKS = array( 'blog_public', 'mail_failed', 'admin_email_invalid', 'no_administrator' );

	/**
	 * Re-entrancy guard for wp_mail_failed handling.
	 *
	 * @var bool
	 */
	private static $handling_mail_failed = false;

	/**
	 * Hooks option, mail, and role changes that can trip Guard checks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_action( 'update_option_blog_public', array( __CLASS__, 'on_blog_public' ), 10, 2 );
		add_action( 'update_option_admin_email', array( __CLASS__, 'on_admin_email_option' ), 10, 2 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'on_mail_failed' ), 10, 1 );
		add_action( 'wp_mail_succeeded', array( __CLASS__, 'on_mail_succeeded' ), 10, 1 );
		add_action( 'set_user_role', array( __CLASS__, 'on_user_capability_change' ), 20, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'on_user_capability_change_add' ), 20, 2 );
		add_action( 'remove_user_role', array( __CLASS__, 'on_user_capability_change_remove' ), 20, 2 );
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ), 20, 3 );
	}

	/**
	 * Evaluates blog_public, admin email, and administrator presence for a scan run.
	 *
	 * @since 1.0.0
	 * @param array &$state Scan state (updated by reference).
	 * @return string
	 */
	public static function scan( array &$state ) {
		$tripped = 0;

		if ( self::evaluate( 'blog_public', self::is_blog_public_bad(), array(), $state ) ) {
			++$tripped;
		}
		if ( self::evaluate( 'admin_email_invalid', self::is_admin_email_bad(), array(), $state ) ) {
			++$tripped;
		}
		$count = self::administrator_count();
		if ( self::evaluate( 'no_administrator', $count < 1, array( 'count' => $count ), $state ) ) {
			++$tripped;
		}

		$guard = isset( $state['guard'] ) && is_array( $state['guard'] ) ? $state['guard'] : array();
		$mail  = ! empty( $guard['mail_failed']['bad'] );

		return $tripped ? ( 'tripped:' . $tripped ) : ( $mail ? 'clean:mail_still_bad' : 'clean' );
	}

	/**
	 * Records guard_tripped when a check newly becomes bad and updates sticky state.
	 *
	 * State is persisted before recording so nested hooks (e.g. wp_mail_failed from
	 * an ACT alert) see was_bad=true and do not re-trip.
	 *
	 * @since 1.0.0
	 * @param string $check Check key from CHECKS.
	 * @param bool   $is_bad Whether the check is currently bad.
	 * @param array  $context Extra context stored on the event.
	 * @param array  &$state Scan state (updated by reference).
	 * @return bool
	 */
	public static function evaluate( $check, $is_bad, array $context, array &$state ) {
		$check = sanitize_key( $check );
		if ( ! in_array( $check, self::CHECKS, true ) ) {
			return false;
		}

		if ( ! isset( $state['guard'] ) || ! is_array( $state['guard'] ) ) {
			$state['guard'] = array();
		}

		$prev    = isset( $state['guard'][ $check ] ) && is_array( $state['guard'][ $check ] ) ? $state['guard'][ $check ] : array();
		$was_bad = ! empty( $prev['bad'] );
		$is_bad  = (bool) $is_bad;

		$entry = array(
			'bad'   => $is_bad,
			'since' => $is_bad ? ( $was_bad && ! empty( $prev['since'] ) ? $prev['since'] : current_time( 'mysql', true ) ) : null,
		);

		$state['guard'][ $check ] = $entry;
		self::persist_guard_check( $check, $entry );

		$recorded = false;

		if ( $is_bad && ! $was_bad ) {
			$ctx = array_merge( array( 'check' => $check ), $context );
			if ( Karetaker_Events::record( 'guard_tripped', $ctx, 0 ) > 0 ) {
				$recorded = true;
			}
		}

		return $recorded;
	}

	/**
	 * Writes one Guard check into the persisted scan state without clobbering siblings.
	 *
	 * @since 1.0.0
	 * @param string $check Check key.
	 * @param array  $entry Guard entry (bad/since).
	 * @return void
	 */
	private static function persist_guard_check( $check, array $entry ) {
		$state = Karetaker_Scanner::state();
		if ( ! isset( $state['guard'] ) || ! is_array( $state['guard'] ) ) {
			$state['guard'] = array();
		}
		$state['guard'][ $check ] = $entry;
		Karetaker_Scanner::save_state( $state );
	}

	/**
	 * Whether search engines are discouraged (blog_public is 0).
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_blog_public_bad() {
		return '0' === (string) get_option( 'blog_public' );
	}

	/**
	 * Whether the site admin email is empty or invalid.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_admin_email_bad() {
		$email = (string) get_option( 'admin_email' );
		return '' === $email || ! is_email( $email );
	}

	/**
	 * Counts users with the manage_options capability (capped sample of 2).
	 *
	 * @since 1.0.0
	 * @return int
	 */
	public static function administrator_count() {
		$users = get_users(
			array(
				'capability' => 'manage_options',
				'fields'     => 'ID',
				'number'     => 2,
			)
		);
		return is_array( $users ) ? count( $users ) : 0;
	}

	/**
	 * Re-evaluates blog_public after the option updates.
	 *
	 * @since 1.0.0
	 * @param mixed $old Previous value.
	 * @param mixed $new_value New value.
	 * @return void
	 */
	public static function on_blog_public( $old, $new_value ) {
		$state = Karetaker_Scanner::state();
		self::evaluate(
			'blog_public',
			'0' === (string) $new_value,
			array(
				'old' => (string) $old,
				'new' => (string) $new_value,
			),
			$state
		);
	}

	/**
	 * Re-evaluates admin email validity after the option updates.
	 *
	 * @since 1.0.0
	 * @param mixed $old Previous value.
	 * @param mixed $new_value New value.
	 * @return void
	 */
	public static function on_admin_email_option( $old, $new_value ) {
		unset( $old );
		$state = Karetaker_Scanner::state();
		$bad   = '' === (string) $new_value || ! is_email( (string) $new_value );
		self::evaluate( 'admin_email_invalid', $bad, array(), $state );
	}

	/**
	 * Trips the mail_failed Guard check when wp_mail fails.
	 *
	 * @since 1.0.0
	 * @param mixed $error WP_Error or failure payload from wp_mail_failed.
	 * @return void
	 */
	public static function on_mail_failed( $error ) {
		if ( self::$handling_mail_failed ) {
			return;
		}

		self::$handling_mail_failed = true;

		$message = '';
		if ( is_wp_error( $error ) ) {
			$message = $error->get_error_message();
		}

		$state = Karetaker_Scanner::state();
		self::evaluate(
			'mail_failed',
			true,
			array( 'error' => sanitize_text_field( substr( (string) $message, 0, 200 ) ) ),
			$state
		);

		self::$handling_mail_failed = false;
	}

	/**
	 * Clears the mail_failed Guard flag after a successful wp_mail send.
	 *
	 * @since 1.0.0
	 * @param array $mail_data Mail payload from wp_mail_succeeded (unused).
	 * @return void
	 */
	public static function on_mail_succeeded( $mail_data = array() ) {
		unset( $mail_data );

		if ( self::$handling_mail_failed ) {
			return;
		}

		$now = time();
		if ( $now - (int) get_option( 'karetaker_mail_ok_at', 0 ) > 10 * MINUTE_IN_SECONDS ) {
			update_option( 'karetaker_mail_ok_at', $now, false );
		}

		$state = Karetaker_Scanner::state();
		$prev  = isset( $state['guard']['mail_failed'] ) && is_array( $state['guard']['mail_failed'] )
			? $state['guard']['mail_failed']
			: array();

		if ( empty( $prev['bad'] ) ) {
			return;
		}

		self::evaluate( 'mail_failed', false, array(), $state );
	}

	/**
	 * Owner-dismissed clear for mail_failed (Overview).
	 *
	 * @since 1.0.0
	 * @return bool True when a bad flag was cleared.
	 */
	public static function dismiss_mail_failed() {
		$state = Karetaker_Scanner::state();
		$prev  = isset( $state['guard']['mail_failed'] ) && is_array( $state['guard']['mail_failed'] )
			? $state['guard']['mail_failed']
			: array();

		if ( empty( $prev['bad'] ) ) {
			return false;
		}

		self::evaluate( 'mail_failed', false, array( 'dismissed' => 1 ), $state );
		Karetaker_Events::record(
			'setting_changed',
			array(
				'option' => 'guard_mail_failed',
				'value'  => 'dismissed',
			)
		);

		return true;
	}

	/**
	 * Re-evaluates whether any administrator remains.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function maybe_check_administrators() {
		$state = Karetaker_Scanner::state();
		$count = self::administrator_count();
		self::evaluate( 'no_administrator', $count < 1, array( 'count' => $count ), $state );
	}

	/**
	 * Re-checks administrators after set_user_role.
	 *
	 * @since 1.0.0
	 * @param int    $user_id User ID.
	 * @param string $role New role.
	 * @param array  $old_roles Previous roles.
	 * @return void
	 */
	public static function on_user_capability_change( $user_id, $role, $old_roles ) {
		unset( $user_id, $role, $old_roles );
		self::maybe_check_administrators();
	}

	/**
	 * Re-checks administrators after add_user_role.
	 *
	 * @since 1.0.0
	 * @param int    $user_id User ID.
	 * @param string $role Role added.
	 * @return void
	 */
	public static function on_user_capability_change_add( $user_id, $role ) {
		unset( $user_id, $role );
		self::maybe_check_administrators();
	}

	/**
	 * Re-checks administrators after remove_user_role.
	 *
	 * @since 1.0.0
	 * @param int    $user_id User ID.
	 * @param string $role Role removed.
	 * @return void
	 */
	public static function on_user_capability_change_remove( $user_id, $role ) {
		unset( $user_id, $role );
		self::maybe_check_administrators();
	}

	/**
	 * Re-checks administrators after a user is deleted.
	 *
	 * @since 1.0.0
	 * @param int          $user_id Deleted user ID.
	 * @param int|null     $reassign Reassignment target.
	 * @param WP_User|null $user Deleted user object when available.
	 * @return void
	 */
	public static function on_user_deleted( $user_id, $reassign, $user = null ) {
		unset( $user_id, $reassign, $user );
		self::maybe_check_administrators();
	}
}
