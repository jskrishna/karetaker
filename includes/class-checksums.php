<?php
/**
 * Verification against the checksums WordPress.org publishes.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Karetaker_Checksums {

	const CORE_ENDPOINT   = 'https://api.wordpress.org/core/checksums/1.0/';
	const PLUGIN_ENDPOINT = 'https://downloads.wordpress.org/plugin-checksums/';

	const CACHE_TTL       = 43200;
	const HTTP_TIMEOUT    = 15;
	const PLUGINS_PER_RUN = 3;

	const SANITY_RATIO = 0.2;
	const SANITY_FLOOR = 25;

	public static function fetch_json( $url, $cache_key ) {
		$cached = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => self::HTTP_TIMEOUT,
				'sslverify'  => true,
				'user-agent' => 'Karetaker/' . KARETAKER_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'karetaker_http', $response->get_error_message() );
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'karetaker_http_status', (string) wp_remote_retrieve_response_code( $response ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'karetaker_json', 'Unparseable response.' );
		}

		set_transient( $cache_key, $data, self::CACHE_TTL );

		return $data;
	}

	public static function hash_matches( $expected, $actual ) {
		foreach ( (array) $expected as $candidate ) {
			if ( is_string( $candidate ) && hash_equals( $candidate, $actual ) ) {
				return true;
			}
		}

		return false;
	}

	public static function verify_core( $deadline = 0, $offset = 0, array $carry = array(), $carry_checked = 0 ) {
		$version = get_bloginfo( 'version' );
		$locale  = get_locale();

		$url = add_query_arg(
			array(
				'version' => $version,
				'locale'  => $locale,
			),
			self::CORE_ENDPOINT
		);

		$data = self::fetch_json( $url, 'karetaker_core_cs_' . md5( $version . $locale ) );

		if ( is_wp_error( $data ) ) {
			return array( 'status' => 'unavailable', 'reason' => $data->get_error_code() );
		}

		$files = isset( $data['checksums'] ) && is_array( $data['checksums'] ) ? $data['checksums'] : array();

		if ( ! $files && 'en_US' !== $locale ) {
			$url  = add_query_arg( array( 'version' => $version, 'locale' => 'en_US' ), self::CORE_ENDPOINT );
			$data = self::fetch_json( $url, 'karetaker_core_cs_' . md5( $version . 'en_US' ) );

			if ( ! is_wp_error( $data ) && isset( $data['checksums'] ) ) {
				$files = (array) $data['checksums'];
			}
		}

		if ( ! $files ) {
			return array( 'status' => 'unavailable', 'reason' => 'empty' );
		}

		$modified = $carry;
		$checked  = (int) $carry_checked;
		$index    = 0;

		foreach ( $files as $rel => $expected ) {
			++$index;

			if ( $index <= $offset ) {
				continue;
			}

			if ( $deadline && microtime( true ) > $deadline ) {
				return array(
					'status'   => 'partial',
					'checked'  => $checked,
					'modified' => $modified,
					'offset'   => $index - 1,
					'total'    => count( $files ),
				);
			}

			if ( 0 === strpos( $rel, 'wp-content/' ) ) {
				continue;
			}

			$path = ABSPATH . $rel;

			if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
				continue;
			}

			++$checked;

			if ( ! self::hash_matches( $expected, md5_file( $path ) ) ) {
				$modified[] = $rel;
			}
		}

		return array(
			'status'   => 'complete',
			'checked'  => $checked,
			'modified' => $modified,
			'offset'   => 0,
			'total'    => count( $files ),
		);
	}

	public static function plugin_slug( $plugin_file ) {
		$parts = explode( '/', $plugin_file );

		return count( $parts ) > 1 ? $parts[0] : basename( $plugin_file, '.php' );
	}

	public static function verify_plugin( $plugin_file, $deadline = 0 ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$slug    = self::plugin_slug( $plugin_file );
		$data    = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
		$version = isset( $data['Version'] ) ? $data['Version'] : '';

		if ( '' === $version ) {
			return array( 'status' => 'skipped', 'reason' => 'no_version' );
		}

		$url  = self::PLUGIN_ENDPOINT . rawurlencode( $slug ) . '/' . rawurlencode( $version ) . '.json';
		$json = self::fetch_json( $url, 'karetaker_pl_cs_' . md5( $slug . $version ) );

		if ( is_wp_error( $json ) ) {
			return array( 'status' => 'unavailable', 'reason' => $json->get_error_code(), 'slug' => $slug );
		}

		$files = isset( $json['files'] ) && is_array( $json['files'] ) ? $json['files'] : array();

		if ( ! $files ) {
			return array( 'status' => 'unavailable', 'reason' => 'empty', 'slug' => $slug );
		}

		$dir      = WP_PLUGIN_DIR . '/' . $slug;
		$modified = array();
		$checked  = 0;

		foreach ( $files as $rel => $hashes ) {
			if ( $deadline && microtime( true ) > $deadline ) {
				return array( 'status' => 'partial', 'slug' => $slug, 'checked' => $checked, 'modified' => $modified );
			}

			$path = $dir . '/' . $rel;

			if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
				continue;
			}

			++$checked;

			$expected = isset( $hashes['sha256'] ) ? $hashes['sha256'] : ( isset( $hashes['md5'] ) ? $hashes['md5'] : null );

			if ( null === $expected ) {
				continue;
			}

			$actual = isset( $hashes['sha256'] ) ? hash_file( 'sha256', $path ) : md5_file( $path );

			if ( ! self::hash_matches( $expected, $actual ) ) {
				$modified[] = $rel;
			}
		}

		return array(
			'status'   => 'complete',
			'slug'     => $slug,
			'version'  => $version,
			'checked'  => $checked,
			'modified' => $modified,
		);
	}

	public static function looks_broken( $checked, $modified_count ) {
		if ( $modified_count < self::SANITY_FLOOR ) {
			return false;
		}

		if ( $checked < 1 ) {
			return true;
		}

		return ( $modified_count / $checked ) > self::SANITY_RATIO;
	}

	public static function report( $subject, $result ) {
		if ( ! isset( $result['modified'] ) || ! $result['modified'] ) {
			return false;
		}

		$checked = isset( $result['checked'] ) ? (int) $result['checked'] : 0;
		$count   = count( $result['modified'] );

		if ( self::looks_broken( $checked, $count ) ) {
			Karetaker_Events::record(
				'scan_ran',
				array(
					'check'    => 'checksums',
					'subject'  => $subject,
					'outcome'  => 'suppressed_implausible',
					'modified' => $count,
					'checked'  => $checked,
				),
				0
			);

			return false;
		}

		Karetaker_Events::record(
			'file_hash_mismatch',
			array(
				'subject' => $subject,
				'files'   => array_slice( $result['modified'], 0, 10 ),
				'count'   => $count,
				'checked' => $checked,
			),
			0
		);

		return true;
	}
}
