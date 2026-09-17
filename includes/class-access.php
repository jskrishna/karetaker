<?php
/**
 * Who can use Karetaker, and API tokens for outside dashboards.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Role permissions (through the user_has_cap filter, no role edits) and hashed API tokens.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Access {

	const TOKENS_OPTION = 'karetaker_api_tokens';
	const CAPS          = array( 'karetaker_view', 'karetaker_view_log', 'karetaker_resolve' );
	const SCOPES        = array( 'status', 'events' );

	/**
	 * Hooks the capability filter and migrates the single agency token.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_caps' ), 10, 3 );
		self::migrate_agency_token();
	}

	/**
	 * Roles that can be given access, keyed by role slug.
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	public static function roles() {
		$out = array();
		foreach ( array( 'editor', 'shop_manager' ) as $slug ) {
			$role = get_role( $slug );
			if ( $role ) {
				$names        = wp_roles()->get_names();
				$out[ $slug ] = isset( $names[ $slug ] ) ? translate_user_role( $names[ $slug ] ) : $slug;
			}
		}
		return $out;
	}

	/**
	 * Stored role matrix.
	 *
	 * @since 1.0.0
	 * @return array<string, array<string, bool>>
	 */
	public static function matrix() {
		$stored = Karetaker_Settings::get( 'role_caps' );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();
		foreach ( array_keys( self::roles() ) as $role ) {
			foreach ( self::CAPS as $cap ) {
				$out[ $role ][ $cap ] = ! empty( $stored[ $role ][ $cap ] );
			}
		}
		return $out;
	}

	/**
	 * Saves the role matrix. Viewing the log or resolving implies viewing status.
	 *
	 * @since 1.0.0
	 * @param array<string, array<string, mixed>> $matrix Raw matrix.
	 * @return void
	 */
	public static function save_matrix( array $matrix ) {
		$clean = array();
		foreach ( array_keys( self::roles() ) as $role ) {
			foreach ( self::CAPS as $cap ) {
				$clean[ $role ][ $cap ] = ! empty( $matrix[ $role ][ $cap ] );
			}
			if ( $clean[ $role ]['karetaker_view_log'] || $clean[ $role ]['karetaker_resolve'] ) {
				$clean[ $role ]['karetaker_view'] = true;
			}
		}
		Karetaker_Settings::update( array( 'role_caps' => $clean ) );
	}

	/**
	 * Grants Karetaker capabilities: everything to managers, and the role matrix to others
	 * unless "Only administrators can see Karetaker" is on.
	 *
	 * @since 1.0.0
	 * @param array<string, bool> $allcaps All capabilities of the user.
	 * @param string[]            $caps    Required primitive caps.
	 * @param array               $args    Capability check args.
	 * @return array<string, bool>
	 */
	public static function grant_caps( $allcaps, $caps, $args ) {
		if ( ! array_intersect( (array) $caps, self::CAPS ) ) {
			return $allcaps;
		}
		if ( ! empty( $allcaps['manage_options'] ) ) {
			foreach ( self::CAPS as $cap ) {
				$allcaps[ $cap ] = true;
			}
			return $allcaps;
		}
		if ( self::admins_only() ) {
			return $allcaps;
		}
		$user   = isset( $args[1] ) ? get_userdata( (int) $args[1] ) : false;
		$matrix = self::matrix();
		foreach ( $user ? (array) $user->roles : array() as $role ) {
			if ( ! isset( $matrix[ $role ] ) ) {
				continue;
			}
			foreach ( $matrix[ $role ] as $cap => $on ) {
				if ( $on ) {
					$allcaps[ $cap ] = true;
				}
			}
		}
		return $allcaps;
	}

	/**
	 * Whether Karetaker is limited to administrators (default on).
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function admins_only() {
		$value = Karetaker_Settings::get( 'admins_only' );
		return null === $value ? true : (bool) $value;
	}

	/**
	 * Stored tokens (hashes only).
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function tokens() {
		$tokens = get_option( self::TOKENS_OPTION, array() );
		return is_array( $tokens ) ? array_values( $tokens ) : array();
	}

	/**
	 * Hash for a plain token.
	 *
	 * @since 1.0.0
	 * @param string $token Plain token.
	 * @return string
	 */
	private static function hash( $token ) {
		return hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) );
	}

	/**
	 * Creates a token and returns the plain value once.
	 *
	 * @since 1.0.0
	 * @param string   $name    Label.
	 * @param string[] $scopes  status and/or events.
	 * @param string[] $ips     Allowed IPs or CIDRs (empty = any).
	 * @param int      $days    Expiry in days (0 = never).
	 * @return string Plain token.
	 */
	public static function create( $name, array $scopes, array $ips, $days ) {
		$plain    = 'kt_' . wp_generate_password( 40, false, false );
		$tokens   = self::tokens();
		$tokens[] = array(
			'id'        => wp_generate_uuid4(),
			'name'      => '' !== trim( $name ) ? sanitize_text_field( $name ) : __( 'API token', 'karetaker' ),
			'hash'      => self::hash( $plain ),
			'hint'      => substr( $plain, -4 ),
			'scopes'    => array_values( array_intersect( self::SCOPES, $scopes ) ) ? array_values( array_intersect( self::SCOPES, $scopes ) ) : array( 'status' ),
			'ips'       => array_values( array_filter( array_map( 'trim', $ips ) ) ),
			'created'   => time(),
			'last_used' => 0,
			'expires'   => $days > 0 ? time() + (int) $days * DAY_IN_SECONDS : 0,
			'by'        => get_current_user_id(),
		);
		update_option( self::TOKENS_OPTION, $tokens, false );
		return $plain;
	}

	/**
	 * Replaces a token's secret and returns the new plain value.
	 *
	 * @since 1.0.0
	 * @param string $id Token id.
	 * @return string Empty when not found.
	 */
	public static function rotate( $id ) {
		$tokens = self::tokens();
		foreach ( $tokens as $i => $token ) {
			if ( $token['id'] === $id ) {
				$plain                   = 'kt_' . wp_generate_password( 40, false, false );
				$tokens[ $i ]['hash']    = self::hash( $plain );
				$tokens[ $i ]['hint']    = substr( $plain, -4 );
				$tokens[ $i ]['created'] = time();
				update_option( self::TOKENS_OPTION, $tokens, false );
				return $plain;
			}
		}
		return '';
	}

	/**
	 * Deletes a token.
	 *
	 * @since 1.0.0
	 * @param string $id Token id.
	 * @return void
	 */
	public static function revoke( $id ) {
		$tokens = array_values(
			array_filter(
				self::tokens(),
				static function ( $token ) use ( $id ) {
					return $token['id'] !== $id;
				}
			)
		);
		update_option( self::TOKENS_OPTION, $tokens, false );
	}

	/**
	 * Checks a plain token for a scope and client IP, recording last use.
	 *
	 * @since 1.0.0
	 * @param string $plain Plain token from the request.
	 * @param string $scope status or events.
	 * @return bool
	 */
	public static function verify( $plain, $scope ) {
		if ( '' === (string) $plain ) {
			return false;
		}
		$hash   = self::hash( $plain );
		$ip     = Karetaker_Events::client_ip();
		$tokens = self::tokens();
		foreach ( $tokens as $i => $token ) {
			if ( ! hash_equals( (string) $token['hash'], $hash ) ) {
				continue;
			}
			if ( ! in_array( $scope, (array) $token['scopes'], true ) ) {
				return false;
			}
			if ( ! empty( $token['expires'] ) && (int) $token['expires'] < time() ) {
				return false;
			}
			if ( ! empty( $token['ips'] ) ) {
				$allowed = false;
				foreach ( (array) $token['ips'] as $rule ) {
					if ( $ip === $rule || ( false !== strpos( $rule, '/' ) && Karetaker_Events::ip_in_cidr( $ip, $rule ) ) ) {
						$allowed = true;
						break;
					}
				}
				if ( ! $allowed ) {
					return false;
				}
			}
			if ( time() - (int) $token['last_used'] > 5 * MINUTE_IN_SECONDS ) {
				$tokens[ $i ]['last_used'] = time();
				update_option( self::TOKENS_OPTION, $tokens, false );
			}
			return true;
		}
		return false;
	}

	/**
	 * Moves a pre-2.4 single agency token into the token list (hashed) once.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function migrate_agency_token() {
		$legacy = (string) Karetaker_Settings::get( 'agency_token' );
		if ( '' === $legacy ) {
			return;
		}
		$tokens   = self::tokens();
		$tokens[] = array(
			'id'        => wp_generate_uuid4(),
			'name'      => __( 'Agency dashboard', 'karetaker' ),
			'hash'      => self::hash( $legacy ),
			'hint'      => substr( $legacy, -4 ),
			'scopes'    => array( 'status' ),
			'ips'       => array(),
			'created'   => time(),
			'last_used' => 0,
			'expires'   => 0,
			'by'        => 0,
		);
		update_option( self::TOKENS_OPTION, $tokens, false );
		Karetaker_Settings::update( array( 'agency_token' => '' ) );
	}

	/**
	 * Changes people made to Karetaker itself, newest first.
	 *
	 * @since 1.0.0
	 * @param int $limit Max rows.
	 * @return array<int, array{when: string, user: string, change: string}>
	 */
	public static function audit_trail( $limit = 25 ) {
		$codes = array( 'setting_changed', 'event_marked_expected', 'event_unmarked_expected', 'issue_updated', 'case_updated', 'token_updated' );
		$rows  = array();
		foreach ( $codes as $code ) {
			foreach ( Karetaker_Events::query(
				array(
					'code'  => $code,
					'limit' => $limit,
				)
			) as $row ) {
				if ( (int) $row->user_id > 0 ) {
					$rows[] = $row;
				}
			}
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $b->id - (int) $a->id;
			}
		);
		$out = array();
		foreach ( array_slice( $rows, 0, $limit ) as $row ) {
			$user  = get_userdata( (int) $row->user_id );
			$out[] = array(
				'when'   => Karetaker_Admin_Data::when( (string) $row->event_time ),
				'user'   => $user ? (string) $user->user_login : '#' . (int) $row->user_id,
				'change' => self::describe( $row ),
			);
		}
		return $out;
	}

	/**
	 * Readable sentence for an audit row.
	 *
	 * @since 1.0.0
	 * @param object $row Event row.
	 * @return string
	 */
	private static function describe( $row ) {
		$c = is_array( $row->context ) ? $row->context : array();
		switch ( (string) $row->event_code ) {
			case 'issue_updated':
				/* translators: 1: action, 2: event code */
				return sprintf( __( 'Issue %1$s: %2$s', 'karetaker' ), isset( $c['action'] ) ? (string) $c['action'] : '', isset( $c['code'] ) ? (string) $c['code'] : '' );
			case 'case_updated':
				/* translators: 1: case id, 2: action */
				return sprintf( __( 'Incident %1$s: %2$s', 'karetaker' ), isset( $c['case'] ) ? (string) $c['case'] : '', isset( $c['action'] ) ? (string) $c['action'] : '' );
			case 'token_updated':
				/* translators: 1: action, 2: token name */
				return sprintf( __( 'API token %1$s: %2$s', 'karetaker' ), isset( $c['action'] ) ? (string) $c['action'] : '', isset( $c['name'] ) ? (string) $c['name'] : '' );
			case 'event_marked_expected':
				/* translators: %s: event code */
				return sprintf( __( 'Marked as expected: %s', 'karetaker' ), isset( $c['code'] ) ? (string) $c['code'] : '' );
			case 'event_unmarked_expected':
				/* translators: %s: event code */
				return sprintf( __( 'Removed from ignore list: %s', 'karetaker' ), isset( $c['code'] ) ? (string) $c['code'] : '' );
		}
		/* translators: 1: setting, 2: value */
		return sprintf( __( 'Changed %1$s to %2$s', 'karetaker' ), isset( $c['option'] ) ? (string) $c['option'] : '', isset( $c['value'] ) ? (string) $c['value'] : '' );
	}
}
