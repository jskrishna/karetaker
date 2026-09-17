<?php
/**
 * Who can use Karetaker.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Karetaker capabilities through the user_has_cap filter (no role edits). Managers get all of
 * them; add-ons can grant them to other roles with the karetaker_grant_caps filter.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Access {

	const CAPS = array( 'karetaker_view', 'karetaker_view_log', 'karetaker_resolve' );

	/**
	 * Hooks the capability filter.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_caps' ), 10, 3 );
	}

	/**
	 * Grants Karetaker capabilities: everything to managers; other users only through
	 * the karetaker_grant_caps filter.
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
		/**
		 * Filters Karetaker capabilities for users who are not managers.
		 *
		 * @since 1.0.2
		 * @param array $allcaps All capabilities of the user.
		 * @param array $caps    Required primitive caps.
		 * @param array $args    Capability check args.
		 */
		return (array) apply_filters( 'karetaker_grant_caps', $allcaps, (array) $caps, (array) $args );
	}
}
