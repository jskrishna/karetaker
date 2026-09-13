<?php
/**
 * One-checkbox catastrophe checks.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Karetaker_Guard {

	const CHECKS = array( 'blog_public', 'mail_failed', 'admin_email_invalid', 'no_administrator' );

	public static function init() {
		add_action( 'update_option_blog_public', array( __CLASS__, 'on_blog_public' ), 10, 2 );
		add_action( 'update_option_admin_email', array( __CLASS__, 'on_admin_email_option' ), 10, 2 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'on_mail_failed' ), 10, 1 );
		add_action( 'set_user_role', array( __CLASS__, 'on_user_capability_change' ), 20, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'on_user_capability_change_add' ), 20, 2 );
		add_action( 'remove_user_role', array( __CLASS__, 'on_user_capability_change_remove' ), 20, 2 );
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ), 20, 3 );
	}

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

	public static function evaluate( $check, $is_bad, array $context, array &$state ) {
		$check = sanitize_key( $check );
		if ( ! in_array( $check, self::CHECKS, true ) ) {
			return false;
		}

		if ( ! isset( $state['guard'] ) || ! is_array( $state['guard'] ) ) {
			$state['guard'] = array();
		}

		$prev     = isset( $state['guard'][ $check ] ) && is_array( $state['guard'][ $check ] ) ? $state['guard'][ $check ] : array();
		$was_bad  = ! empty( $prev['bad'] );
		$is_bad   = (bool) $is_bad;
		$recorded = false;

		if ( $is_bad && ! $was_bad ) {
			$ctx = array_merge( array( 'check' => $check ), $context );
			if ( Karetaker_Events::record( 'guard_tripped', $ctx, 0 ) <= 0 ) {
				return false;
			}
			$recorded = true;
		}

		$state['guard'][ $check ] = array(
			'bad'   => $is_bad,
			'since' => $is_bad ? ( $was_bad && ! empty( $prev['since'] ) ? $prev['since'] : current_time( 'mysql', true ) ) : null,
		);

		return $recorded;
	}

	public static function is_blog_public_bad() {
		return '0' === (string) get_option( 'blog_public' );
	}

	public static function is_admin_email_bad() {
		$email = (string) get_option( 'admin_email' );
		return '' === $email || ! is_email( $email );
	}

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

	public static function on_blog_public( $old, $new ) {
		$state = Karetaker_Scanner::state();
		self::evaluate( 'blog_public', '0' === (string) $new, array( 'old' => (string) $old, 'new' => (string) $new ), $state );
		Karetaker_Scanner::save_state( $state );
	}

	public static function on_admin_email_option( $old, $new ) {
		$state = Karetaker_Scanner::state();
		$bad   = '' === (string) $new || ! is_email( (string) $new );
		self::evaluate( 'admin_email_invalid', $bad, array(), $state );
		Karetaker_Scanner::save_state( $state );
	}

	public static function on_mail_failed( $error ) {
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
		Karetaker_Scanner::save_state( $state );
	}

	public static function maybe_check_administrators() {
		$state = Karetaker_Scanner::state();
		$count = self::administrator_count();
		self::evaluate( 'no_administrator', $count < 1, array( 'count' => $count ), $state );
		Karetaker_Scanner::save_state( $state );
	}

	public static function on_user_capability_change( $user_id, $role, $old_roles ) {
		self::maybe_check_administrators();
	}

	public static function on_user_capability_change_add( $user_id, $role ) {
		self::maybe_check_administrators();
	}

	public static function on_user_capability_change_remove( $user_id, $role ) {
		self::maybe_check_administrators();
	}

	public static function on_user_deleted( $user_id, $reassign, $user = null ) {
		self::maybe_check_administrators();
	}
}
