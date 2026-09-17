<?php
/**
 * View models for the "What we watch" tab.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads scan state, users, plugins and sessions into rows for the Watch tab.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Admin_Watch {

	/**
	 * Administrators read straight from usermeta, so hidden accounts show too.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $state Scan state.
	 * @return array<int, array<string, mixed>>
	 */
	public static function people( array $state ) {
		global $wpdb;

		$cap_key = $wpdb->get_blog_prefix() . 'capabilities';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same roster read as Karetaker_Scanner::scan_admin_roster().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.user_login, u.user_email, u.user_registered, um.meta_value FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id WHERE um.meta_key = %s",
				$cap_key
			)
		);

		$visible = array();
		foreach ( get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'ID' ),
			)
		) as $user ) {
			$visible[ (int) $user->ID ] = true;
		}

		$baseline = isset( $state['admin_roster'] ) && is_array( $state['admin_roster'] ) ? array_map( 'strval', $state['admin_roster'] ) : null;
		$out      = array();
		$creators = array();
		foreach ( array( 'admin_user_added', 'user_created' ) as $code ) {
			foreach ( Karetaker_Events::query(
				array(
					'code'  => $code,
					'limit' => 200,
				)
			) as $event ) {
				$login = isset( $event->context['user_login'] ) ? (string) $event->context['user_login'] : '';
				if ( '' !== $login && ! isset( $creators[ $login ] ) ) {
					$actor              = (int) $event->user_id ? get_userdata( (int) $event->user_id ) : false;
					$creators[ $login ] = $actor ? (string) $actor->user_login : __( 'Sign-up or script', 'karetaker' );
				}
			}
		}
		$two_factor = class_exists( 'Two_Factor_Core' ) && method_exists( 'Two_Factor_Core', 'is_user_using_two_factor' );
		foreach ( (array) $rows as $row ) {
			$caps = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $caps ) || empty( $caps['administrator'] ) ) {
				continue;
			}
			$id     = (int) $row->ID;
			$hidden = ! isset( $visible[ $id ] );
			$new    = null !== $baseline && ! in_array( (string) $row->user_login, $baseline, true );
			$seen   = get_user_meta( $id, 'karetaker_login_seen', true );
			$last   = 0;
			if ( is_array( $seen ) && ! empty( $seen['ips'] ) && is_array( $seen['ips'] ) ) {
				$last = (int) max( $seen['ips'] );
			}

			if ( $hidden ) {
				$status = array( 'act', $new ? __( 'Hidden · new', 'karetaker' ) : __( 'Hidden', 'karetaker' ) );
			} elseif ( $new ) {
				$status = array( 'check', __( 'New', 'karetaker' ) );
			} elseif ( $last && ( time() - $last ) > 180 * DAY_IN_SECONDS ) {
				/* translators: %d: months */
				$status = array( 'check', sprintf( __( 'Inactive %d months', 'karetaker' ), (int) floor( ( time() - $last ) / ( 30 * DAY_IN_SECONDS ) ) ) );
			} else {
				$status = array( 'safe', __( 'Known', 'karetaker' ) );
			}

			if ( isset( $creators[ (string) $row->user_login ] ) ) {
				$created_by = $creators[ (string) $row->user_login ];
			} elseif ( $hidden || $new ) {
				$created_by = __( 'Database write', 'karetaker' );
			} else {
				$created_by = __( 'Before Karetaker', 'karetaker' );
			}
			if ( $two_factor ) {
				$twofa = Two_Factor_Core::is_user_using_two_factor( $id ) ? 'on' : 'off';
			} else {
				$twofa = 'unknown';
			}

			$out[] = array(
				'id'         => $id,
				'created_by' => $created_by,
				'twofa'      => $twofa,
				'login'      => (string) $row->user_login,
				'email'      => (string) $row->user_email,
				'hidden'     => $hidden,
				'chip'       => $status[0],
				'status'     => $status[1],
				'added'      => Karetaker_Admin_Data::when( (string) $row->user_registered ),
				'last'       => $last ? Karetaker_Admin_Data::when( gmdate( 'Y-m-d H:i:s', $last ) ) : __( 'Not seen', 'karetaker' ),
				'edit'       => $hidden ? '' : get_edit_user_link( $id ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				$rank = array(
					'act'   => 0,
					'check' => 1,
					'safe'  => 2,
				);
				return $rank[ $a['chip'] ] - $rank[ $b['chip'] ];
			}
		);

		return $out;
	}

	/**
	 * Administrator IDs and logins read from usermeta, including hidden accounts.
	 *
	 * @since 1.0.0
	 * @return array<int, string> User ID => login.
	 */
	public static function admin_ids() {
		$out = array();
		foreach ( self::people( array() ) as $row ) {
			$out[ (int) $row['id'] ] = (string) $row['login'];
		}
		return $out;
	}

	/**
	 * Ends one stored session by its verifier (user-meta session storage only).
	 *
	 * @since 1.0.0
	 * @param int    $user_id  User ID.
	 * @param string $verifier Session verifier hash.
	 * @return bool
	 */
	public static function end_session( $user_id, $verifier ) {
		$sessions = get_user_meta( (int) $user_id, 'session_tokens', true );
		if ( ! is_array( $sessions ) || ! isset( $sessions[ $verifier ] ) ) {
			return false;
		}
		unset( $sessions[ $verifier ] );
		if ( $sessions ) {
			update_user_meta( (int) $user_id, 'session_tokens', $sessions );
		} else {
			delete_user_meta( (int) $user_id, 'session_tokens' );
		}
		return true;
	}

	/**
	 * Signs every administrator out everywhere, keeping only the current session.
	 *
	 * @since 1.0.0
	 * @return int Number of administrators affected.
	 */
	public static function end_all_admin_sessions() {
		$count = 0;
		foreach ( array_keys( self::admin_ids() ) as $user_id ) {
			$manager = WP_Session_Tokens::get_instance( $user_id );
			if ( get_current_user_id() === $user_id ) {
				$manager->destroy_others( wp_get_session_token() );
			} else {
				$manager->destroy_all();
			}
			++$count;
		}
		return $count;
	}
}
