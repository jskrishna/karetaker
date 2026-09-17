<?php
/**
 * Optional WordPress.org-adjacent vulnerability lookup (opt-in).
 *
 * Contacts WPVulnerability when enabled; off by default.
 *
 * @package Karetaker
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches plugin vulnerability metadata for active installs.
 *
 * @since 1.0.0
 */
class Karetaker_Vulns {

	const ENDPOINT        = 'https://www.wpvulnerability.net/plugin/';
	const CACHE_TTL       = 43200;
	const HTTP_TIMEOUT    = 12;
	const PLUGINS_PER_RUN = 2;

	/**
	 * Whether the owner enabled outbound vulnerability checks.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) Karetaker_Settings::get( 'vuln_lookup_enabled' );
	}

	/**
	 * Lookup vulns for a plugin slug (cached).
	 *
	 * @since 1.0.0
	 * @param string $slug Plugin slug.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function fetch_plugin( $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return new WP_Error( 'karetaker_slug', 'Empty slug.' );
		}

		$cache_key = 'karetaker_vuln_' . md5( $slug );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url      = self::ENDPOINT . rawurlencode( $slug ) . '/';
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => self::HTTP_TIMEOUT,
				'sslverify'  => true,
				'user-agent' => 'Karetaker/' . KARETAKER_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $data ) || ! empty( $data['error'] ) ) {
			$out = array(
				'slug'   => $slug,
				'status' => 'unavailable',
			);
			set_transient( $cache_key, $out, HOUR_IN_SECONDS );
			return $out;
		}

		$vulns = array();
		if ( isset( $data['data']['vulnerability'] ) && is_array( $data['data']['vulnerability'] ) ) {
			$vulns = $data['data']['vulnerability'];
		}

		$out = array(
			'slug'   => $slug,
			'status' => 'ok',
			'vulns'  => $vulns,
			'name'   => isset( $data['data']['name'] ) ? (string) $data['data']['name'] : $slug,
		);
		set_transient( $cache_key, $out, self::CACHE_TTL );

		return $out;
	}

	/**
	 * Whether an installed version matches a vulnerability operator range.
	 *
	 * @since 1.0.0
	 * @param string               $installed Installed version.
	 * @param array<string, mixed> $operator  Operator block from the feed.
	 * @return bool
	 */
	public static function version_is_affected( $installed, array $operator ) {
		$installed = (string) $installed;
		if ( '' === $installed ) {
			return false;
		}

		$max_version  = isset( $operator['max_version'] ) ? (string) $operator['max_version'] : '';
		$max_operator = isset( $operator['max_operator'] ) ? (string) $operator['max_operator'] : '';
		$min_version  = isset( $operator['min_version'] ) ? (string) $operator['min_version'] : '';
		$min_operator = isset( $operator['min_operator'] ) ? (string) $operator['min_operator'] : '';

		$ok_max = true;
		$ok_min = true;

		if ( '' !== $max_version && '' !== $max_operator ) {
			$ok_max = (bool) version_compare( $installed, $max_version, self::map_operator( $max_operator ) );
		}
		if ( '' !== $min_version && '' !== $min_operator ) {
			$ok_min = (bool) version_compare( $installed, $min_version, self::map_operator( $min_operator ) );
		}

		return $ok_max && $ok_min;
	}

	/**
	 * Map feed operators to version_compare operators.
	 *
	 * @since 1.0.0
	 * @param string $op Feed operator (lt, le, gt, ge, eq).
	 * @return string
	 */
	private static function map_operator( $op ) {
		$map = array(
			'lt' => '<',
			'le' => '<=',
			'gt' => '>',
			'ge' => '>=',
			'eq' => '==',
		);
		$op  = strtolower( (string) $op );
		return isset( $map[ $op ] ) ? $map[ $op ] : '<';
	}

	/**
	 * Find vulns affecting an installed version; return compact list for events.
	 *
	 * @since 1.0.0
	 * @param string               $installed Installed version.
	 * @param array<string, mixed> $payload   fetch_plugin() result.
	 * @return array<int, array<string, string>>
	 */
	public static function matching_vulns( $installed, array $payload ) {
		$out = array();
		if ( empty( $payload['vulns'] ) || ! is_array( $payload['vulns'] ) ) {
			return $out;
		}

		foreach ( $payload['vulns'] as $vuln ) {
			if ( ! is_array( $vuln ) ) {
				continue;
			}
			$operator = isset( $vuln['operator'] ) && is_array( $vuln['operator'] ) ? $vuln['operator'] : array();
			if ( ! self::version_is_affected( $installed, $operator ) ) {
				continue;
			}

			$cve = '';
			if ( ! empty( $vuln['source'] ) && is_array( $vuln['source'] ) ) {
				foreach ( $vuln['source'] as $src ) {
					if ( is_array( $src ) && ! empty( $src['id'] ) && 0 === strpos( (string) $src['id'], 'CVE-' ) ) {
						$cve = (string) $src['id'];
						break;
					}
				}
			}

			$out[] = array(
				'uuid'  => isset( $vuln['uuid'] ) ? (string) $vuln['uuid'] : md5( (string) $vuln['name'] ),
				'name'  => isset( $vuln['name'] ) ? (string) $vuln['name'] : 'vulnerability',
				'cve'   => $cve,
				'fixed' => isset( $operator['max_version'] ) ? (string) $operator['max_version'] : '',
			);

			if ( count( $out ) >= 5 ) {
				break;
			}
		}

		return $out;
	}
}
