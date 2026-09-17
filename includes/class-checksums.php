<?php
/**
 * Verification against the checksums WordPress.org publishes.
 *
 * @package Karetaker
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches official checksums and compares on-disk files for core and plugins.
 *
 * @since 1.0.0
 */
class Karetaker_Checksums {

	const CORE_ENDPOINT   = 'https://api.wordpress.org/core/checksums/1.0/';
	const PLUGIN_ENDPOINT = 'https://downloads.wordpress.org/plugin-checksums/';
	const PLUGIN_INFO_API = 'https://api.wordpress.org/plugins/info/1.2/';

	const CACHE_TTL         = 43200;
	const HTTP_TIMEOUT      = 15;
	const PLUGINS_PER_RUN   = 3;
	const DIRECTORY_PER_RUN = 3;
	const ABANDONED_DAYS    = 730;

	const SANITY_RATIO = 0.2;
	const SANITY_FLOOR = 25;

	/**
	 * GETs JSON from a URL with transient caching.
	 *
	 * @since 1.0.0
	 * @param string $url       Request URL.
	 * @param string $cache_key Transient key for the decoded array.
	 * @return array<string, mixed>|WP_Error Decoded JSON or error.
	 */
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

	/**
	 * Whether the actual hash matches any expected hash using timing-safe compare.
	 *
	 * @since 1.0.0
	 * @param string|array<int, string> $expected One or more expected hash strings.
	 * @param string                    $actual   Computed file hash.
	 * @return bool
	 */
	public static function hash_matches( $expected, $actual ) {
		foreach ( (array) $expected as $candidate ) {
			if ( is_string( $candidate ) && hash_equals( $candidate, $actual ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compares core files under ABSPATH to WordPress.org checksums for this version.
	 *
	 * @since 1.0.0
	 * @param float              $deadline      Unix microtime stop; 0 for no limit.
	 * @param int                $offset        Skip file index through this position (resume).
	 * @param array<int, string> $carry         Relative paths already flagged modified.
	 * @param int                $carry_checked Files already counted in a partial run.
	 * @return array<string, mixed> Result with status complete|partial|unavailable and counts.
	 */
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
			return array(
				'status' => 'unavailable',
				'reason' => $data->get_error_code(),
			);
		}

		$files = isset( $data['checksums'] ) && is_array( $data['checksums'] ) ? $data['checksums'] : array();

		if ( ! $files && 'en_US' !== $locale ) {
			$url  = add_query_arg(
				array(
					'version' => $version,
					'locale'  => 'en_US',
				),
				self::CORE_ENDPOINT
			);
			$data = self::fetch_json( $url, 'karetaker_core_cs_' . md5( $version . 'en_US' ) );

			if ( ! is_wp_error( $data ) && isset( $data['checksums'] ) ) {
				$files = (array) $data['checksums'];
			}
		}

		if ( ! $files ) {
			return array(
				'status' => 'unavailable',
				'reason' => 'empty',
			);
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

		$extra = self::find_unexpected_core_php( array_keys( $files ), $deadline );
		if ( is_wp_error( $extra ) ) {
			return array(
				'status'   => 'partial',
				'checked'  => $checked,
				'modified' => $modified,
				'offset'   => count( $files ),
				'total'    => count( $files ),
			);
		}

		if ( $extra ) {
			$modified = array_values( array_unique( array_merge( $modified, $extra ) ) );
		}

		return array(
			'status'   => 'complete',
			'checked'  => $checked,
			'modified' => $modified,
			'unknown'  => $extra,
			'offset'   => 0,
			'total'    => count( $files ),
		);
	}

	/**
	 * Finds unexpected .php files under wp-admin / wp-includes not in core checksums.
	 *
	 * @since 1.0.0
	 * @param string[] $known Relative paths from the checksum map.
	 * @param float    $deadline Unix microtime stop; 0 for no limit.
	 * @return string[]|WP_Error Relative unexpected paths, or WP_Error when out of time.
	 */
	public static function find_unexpected_core_php( array $known, $deadline = 0 ) {
		$known_map = array_fill_keys( $known, true );
		$extra     = array();

		foreach ( array( 'wp-admin', 'wp-includes' ) as $root ) {
			$dir = ABSPATH . $root;
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$items = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $items as $item ) {
				if ( $deadline && microtime( true ) > $deadline ) {
					return new WP_Error( 'karetaker_timeout', 'Budget exhausted during unexpected-file walk.' );
				}

				if ( ! $item->isFile() ) {
					continue;
				}

				$name = $item->getFilename();
				if ( ! preg_match( '/\.php$/i', $name ) ) {
					continue;
				}

				$abs = $item->getPathname();
				$rel = ltrim( str_replace( ABSPATH, '', $abs ), '/\\' );
				$rel = str_replace( '\\', '/', $rel );

				if ( isset( $known_map[ $rel ] ) ) {
					continue;
				}

				$extra[] = $rel;
			}
		}

		return $extra;
	}

	/**
	 * Looks up WordPress.org directory status for a plugin slug.
	 *
	 * @since 1.0.0
	 * @param string $slug Plugin slug.
	 * @return array<string, mixed>|WP_Error Status payload or transport error.
	 */
	public static function directory_status( $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return new WP_Error( 'karetaker_slug', 'Empty slug.' );
		}

		$cache_key = 'karetaker_pi_' . md5( $slug );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url = self::PLUGIN_INFO_API . '?action=plugin_information&request%5Bslug%5D=' . rawurlencode( $slug );

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
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'karetaker_json', 'Unparseable plugin info.' );
		}

		$out = array(
			'slug'   => $slug,
			'status' => 'listed',
		);

		if ( ! empty( $data['error'] ) ) {
			$err = is_string( $data['error'] ) ? strtolower( $data['error'] ) : '';
			if ( 'closed' === $err || ! empty( $data['closed'] ) ) {
				$out['status']      = 'closed';
				$out['name']        = isset( $data['name'] ) ? (string) $data['name'] : $slug;
				$out['reason']      = isset( $data['reason'] ) ? (string) $data['reason'] : '';
				$out['closed_date'] = isset( $data['closed_date'] ) ? (string) $data['closed_date'] : '';
			} else {
				$out['status'] = 'not_found';
				$out['error']  = is_scalar( $data['error'] ) ? (string) $data['error'] : 'not_found';
			}
		} else {
			$out['name']         = isset( $data['name'] ) ? (string) $data['name'] : $slug;
			$out['author']       = isset( $data['author'] ) ? trim( wp_strip_all_tags( (string) $data['author'] ) ) : '';
			$out['last_updated'] = isset( $data['last_updated'] ) ? (string) $data['last_updated'] : '';
			$out['version']      = isset( $data['version'] ) ? (string) $data['version'] : '';
			if ( $out['last_updated'] ) {
				$ts = strtotime( $out['last_updated'] );
				if ( $ts && ( time() - $ts ) > ( self::ABANDONED_DAYS * DAY_IN_SECONDS ) ) {
					$out['status'] = 'abandoned';
					$out['days']   = (int) floor( ( time() - $ts ) / DAY_IN_SECONDS );
				}
			}
		}

		// Cache closed/listed/abandoned; shorter cache on transport oddities.
		$ttl = ( 200 === $code || 404 === $code ) ? self::CACHE_TTL : HOUR_IN_SECONDS;
		set_transient( $cache_key, $out, $ttl );

		return $out;
	}

	/**
	 * Derives the plugin slug directory from a plugin bootstrap file path.
	 *
	 * @since 1.0.0
	 * @param string $plugin_file Plugin path relative to wp-content/plugins.
	 * @return string
	 */
	public static function plugin_slug( $plugin_file ) {
		$parts = explode( '/', $plugin_file );

		return count( $parts ) > 1 ? $parts[0] : basename( $plugin_file, '.php' );
	}

	/**
	 * Compares a plugin's files to wordpress.org checksums for its reported version.
	 *
	 * @since 1.0.0
	 * @param string $plugin_file Plugin path relative to wp-content/plugins.
	 * @param float  $deadline    Unix microtime stop; 0 for no limit.
	 * @return array<string, mixed> Result with status complete|partial|skipped|unavailable.
	 */
	public static function verify_plugin( $plugin_file, $deadline = 0 ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$slug    = self::plugin_slug( $plugin_file );
		$data    = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
		$version = isset( $data['Version'] ) ? $data['Version'] : '';

		if ( '' === $version ) {
			return array(
				'status' => 'skipped',
				'reason' => 'no_version',
			);
		}

		$url  = self::PLUGIN_ENDPOINT . rawurlencode( $slug ) . '/' . rawurlencode( $version ) . '.json';
		$json = self::fetch_json( $url, 'karetaker_pl_cs_' . md5( $slug . $version ) );

		if ( is_wp_error( $json ) ) {
			return array(
				'status' => 'unavailable',
				'reason' => $json->get_error_code(),
				'slug'   => $slug,
			);
		}

		$files = isset( $json['files'] ) && is_array( $json['files'] ) ? $json['files'] : array();

		if ( ! $files ) {
			return array(
				'status' => 'unavailable',
				'reason' => 'empty',
				'slug'   => $slug,
			);
		}

		$dir      = WP_PLUGIN_DIR . '/' . $slug;
		$modified = array();
		$checked  = 0;

		foreach ( $files as $rel => $hashes ) {
			if ( $deadline && microtime( true ) > $deadline ) {
				return array(
					'status'   => 'partial',
					'slug'     => $slug,
					'checked'  => $checked,
					'modified' => $modified,
				);
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

	/**
	 * Whether mismatch counts suggest a bad checksum fetch rather than real tampering.
	 *
	 * @since 1.0.0
	 * @param int $checked        Files compared.
	 * @param int $modified_count Paths that failed hash match.
	 * @return bool
	 */
	public static function looks_broken( $checked, $modified_count ) {
		if ( $modified_count < self::SANITY_FLOOR ) {
			return false;
		}

		if ( $checked < 1 ) {
			return true;
		}

		return ( $modified_count / $checked ) > self::SANITY_RATIO;
	}

	/**
	 * Splits mismatched paths into alertable and host-managed, dropping ones marked expected.
	 *
	 * @since 1.0.0
	 * @param string               $subject  Checked target label.
	 * @param string[]             $modified Mismatched relative paths.
	 * @param array<string, mixed> $state    Scan state.
	 * @return array{0: string[], 1: string[]} Alertable paths, host-managed paths.
	 */
	public static function split_modified( $subject, array $modified, array $state ) {
		$subject_key = sanitize_key( (string) $subject );
		$expected    = array();
		if ( isset( $state['checksum_expected'][ $subject_key ] ) && is_array( $state['checksum_expected'][ $subject_key ] ) ) {
			$expected = array_map( 'strval', $state['checksum_expected'][ $subject_key ] );
		}

		$host_managed = array();
		$alertable    = array();
		foreach ( array_map( 'strval', $modified ) as $rel ) {
			if ( in_array( $rel, $expected, true ) ) {
				continue;
			}
			$abs = ABSPATH . ltrim( str_replace( '\\', '/', $rel ), '/' );
			if ( file_exists( $abs ) && ! is_writable( $abs ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only probe; no write.
				$host_managed[] = $rel;
				continue;
			}
			$alertable[] = $rel;
		}

		return array( $alertable, $host_managed );
	}

	/**
	 * Emits file_hash_mismatch or a suppressed scan_ran event for checksum results.
	 *
	 * @since 1.0.0
	 * @param string               $subject Label for the scanned target (e.g. core or plugin).
	 * @param array<string, mixed> $result  verify_core or verify_plugin result array.
	 * @return bool True when file_hash_mismatch was recorded; false otherwise.
	 */
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

		$state                            = Karetaker_Scanner::state();
		list( $alertable, $host_managed ) = self::split_modified( $subject, $result['modified'], $state );
		if ( $host_managed ) {
			$known                 = isset( $state['host_managed'] ) && is_array( $state['host_managed'] ) ? $state['host_managed'] : array();
			$state['host_managed'] = array_values( array_unique( array_merge( $known, $host_managed ) ) );
			Karetaker_Scanner::save_state( $state );
		}

		if ( ! $alertable ) {
			Karetaker_Events::record(
				'scan_ran',
				array(
					'check'        => 'checksums',
					'subject'      => $subject,
					'outcome'      => $host_managed ? 'host_managed_only' : 'expected_only',
					'host_managed' => count( $host_managed ),
					'checked'      => $checked,
				),
				0
			);

			return false;
		}

		Karetaker_Events::record(
			'file_hash_mismatch',
			array(
				'subject'      => $subject,
				'files'        => array_slice( $alertable, 0, 10 ),
				'count'        => count( $alertable ),
				'checked'      => $checked,
				'host_managed' => count( $host_managed ),
			),
			0
		);

		return true;
	}
}
