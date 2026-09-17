<?php
/**
 * Mark-as-expected baseline updates (noise control).
 *
 * @package Karetaker
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies owner-confirmed baselines so expected changes stop re-alerting.
 *
 * @since 1.0.0
 */
class Karetaker_Expected {

	/**
	 * Event codes that can update a scan baseline when marked expected.
	 *
	 * @since 1.0.0
	 * @return string[]
	 */
	public static function supported_codes() {
		return array(
			'muplugin_changed',
			'uploads_php_found',
			'uploads_config_changed',
			'option_changed',
			'orphan_cron_found',
			'file_hash_mismatch',
			'critical_file_changed',
			'admin_roster_changed',
			'hidden_admin_found',
			'hidden_plugin_found',
			'silent_plugin_found',
			'db_script_found',
			'option_denylist_hit',
			'option_name_suspect',
			'script_domain_new',
			'cloaking_suspected',
			'plugin_owner_changed',
			'plugin_update_flagged',
			'plugin_directory_closed',
			'plugin_directory_abandoned',
			'plugin_vuln_found',
			'user_enum_probe',
			'admin_login_new_ip',
			'admin_login_new_device',
		);
	}

	/**
	 * Whether this event code supports Mark as expected.
	 *
	 * @since 1.0.0
	 * @param string $code Event code.
	 * @return bool
	 */
	public static function supports( $code ) {
		return in_array( sanitize_key( $code ), self::supported_codes(), true );
	}

	/**
	 * Event ids already marked as expected, newest marks first.
	 *
	 * @since 1.0.0
	 * @param int $limit Max marks to read.
	 * @return array<int, true> Map of event id => true.
	 */
	public static function marked_ids( $limit = 500 ) {
		$rows = array_merge(
			Karetaker_Events::query(
				array(
					'code'  => 'event_marked_expected',
					'limit' => (int) $limit,
				)
			),
			Karetaker_Events::query(
				array(
					'code'  => 'event_unmarked_expected',
					'limit' => (int) $limit,
				)
			)
		);
		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $b->id - (int) $a->id;
			}
		);

		$ids  = array();
		$seen = array();
		foreach ( $rows as $row ) {
			$id = isset( $row->context['event_id'] ) ? (int) $row->context['event_id'] : 0;
			if ( $id < 1 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			if ( 'event_marked_expected' === $row->event_code ) {
				$ids[ $id ] = true;
			}
		}

		return $ids;
	}

	/**
	 * Takes an event back off the ignore list and loosens list-style baselines so it can alert again.
	 *
	 * Fingerprint baselines (critical files, MU-plugins, admin roster, watched options) cannot be
	 * rolled back to the old value; those only return to the attention lists and alert on the
	 * next real change.
	 *
	 * @since 1.0.0
	 * @param int $event_id Original event id.
	 * @return true|WP_Error
	 */
	public static function unmark( $event_id ) {
		$row = self::get_event( $event_id );
		if ( ! $row ) {
			return new WP_Error( 'karetaker_missing_event', __( 'Event not found.', 'karetaker' ) );
		}

		$code    = sanitize_key( (string) $row->event_code );
		$context = json_decode( (string) $row->context, true );
		$context = is_array( $context ) ? $context : array();
		$state   = Karetaker_Scanner::state();

		$drop = static function ( $key, array $items ) use ( &$state ) {
			if ( isset( $state[ $key ] ) && is_array( $state[ $key ] ) ) {
				$state[ $key ] = array_values( array_diff( array_map( 'strval', $state[ $key ] ), $items ) );
			}
		};

		switch ( $code ) {
			case 'uploads_php_found':
				$drop( 'uploads_found', self::string_list( isset( $context['files'] ) ? $context['files'] : array() ) );
				break;
			case 'orphan_cron_found':
				$drop( 'cron_reported', self::string_list( isset( $context['hooks'] ) ? $context['hooks'] : array() ) );
				break;
			case 'file_hash_mismatch':
				$subject = isset( $context['subject'] ) ? sanitize_key( (string) $context['subject'] ) : 'core';
				if ( isset( $state['checksum_expected'][ $subject ] ) && is_array( $state['checksum_expected'][ $subject ] ) ) {
					$state['checksum_expected'][ $subject ] = array_values( array_diff( $state['checksum_expected'][ $subject ], self::string_list( isset( $context['files'] ) ? $context['files'] : array() ) ) );
				}
				break;
			case 'hidden_plugin_found':
			case 'silent_plugin_found':
				$drop( 'plugin_expected_hidden', self::string_list( isset( $context['dirs'] ) ? $context['dirs'] : array() ) );
				break;
			case 'option_denylist_hit':
				$names = self::string_list( isset( $context['names'] ) ? $context['names'] : array() );
				$drop( 'option_denylist_expected', $names );
				$drop( 'option_denylist_reported', $names );
				break;
			case 'option_name_suspect':
				$names = self::string_list( isset( $context['names'] ) ? $context['names'] : array() );
				$drop( 'option_names_baseline', $names );
				$drop( 'option_names_reported', $names );
				break;
			case 'script_domain_new':
				$domains = self::string_list( isset( $context['domains'] ) ? $context['domains'] : array() );
				$drop( 'script_domains', $domains );
				$drop( 'script_domains_reported', $domains );
				break;
			case 'cloaking_suspected':
				$drop( 'cloaking_expected', self::string_list( isset( $context['domains'] ) ? $context['domains'] : array() ) );
				unset( $state['cloaking'] );
				break;
			case 'plugin_directory_closed':
			case 'plugin_directory_abandoned':
			case 'plugin_vuln_found':
				$slug = isset( $context['slug'] ) ? sanitize_title( (string) $context['slug'] ) : '';
				$key  = 'plugin_vuln_found' === $code ? 'vulns' : 'plugin_directory';
				if ( '' !== $slug ) {
					unset( $state[ $key ][ $slug ] );
				}
				break;
			case 'user_enum_probe':
				$via = isset( $context['via'] ) ? sanitize_key( (string) $context['via'] ) : '';
				unset( $state['enum_expected'][ $via ] );
				break;
			case 'admin_login_new_ip':
			case 'admin_login_new_device':
				$user = ! empty( $context['user_login'] ) ? get_user_by( 'login', (string) $context['user_login'] ) : false;
				if ( $user ) {
					$seen = get_user_meta( $user->ID, 'karetaker_login_seen', true );
					if ( is_array( $seen ) ) {
						unset( $seen['ips'][ isset( $context['ip'] ) ? (string) $context['ip'] : '' ] );
						if ( 'admin_login_new_device' === $code ) {
							unset( $seen['devices'][ isset( $context['device'] ) ? (string) $context['device'] : '' ] );
						}
						update_user_meta( $user->ID, 'karetaker_login_seen', $seen );
					}
				}
				break;
		}

		Karetaker_Scanner::save_state( $state );
		Karetaker_Events::record(
			'event_unmarked_expected',
			array(
				'event_id' => (int) $row->id,
				'code'     => $code,
			)
		);

		return true;
	}

	/**
	 * Load one event row by id.
	 *
	 * @since 1.0.0
	 * @param int $id Event id.
	 * @return object|null
	 */
	public static function get_event( $id ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id < 1 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Karetaker's own events table: it is not in any WordPress cache, and a stale read would hide fresh security events.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Karetaker_Schema::table(), $id ) );

		return $row ? $row : null;
	}

	/**
	 * Apply baseline updates for an event and record a log entry.
	 *
	 * @since 1.0.0
	 * @param int $event_id Event id.
	 * @return true|WP_Error
	 */
	public static function mark( $event_id ) {
		$row = self::get_event( $event_id );
		if ( ! $row ) {
			return new WP_Error( 'karetaker_missing_event', __( 'Event not found.', 'karetaker' ) );
		}

		$code = sanitize_key( (string) $row->event_code );
		if ( ! self::supports( $code ) ) {
			return new WP_Error( 'karetaker_unsupported', __( 'This event cannot be marked as expected.', 'karetaker' ) );
		}

		$context = array();
		if ( ! empty( $row->context ) ) {
			$decoded = json_decode( (string) $row->context, true );
			if ( is_array( $decoded ) ) {
				$context = $decoded;
			}
		}

		$state = Karetaker_Scanner::state();
		self::apply_to_state( $code, $context, $state );
		Karetaker_Scanner::save_state( $state );

		Karetaker_Events::record(
			'event_marked_expected',
			array(
				'event_id' => (int) $row->id,
				'code'     => $code,
			)
		);

		return true;
	}

	/**
	 * Mutate scan state so the signal is absorbed into the baseline.
	 *
	 * @since 1.0.0
	 * @param string               $code    Event code.
	 * @param array<string, mixed> $context Event context.
	 * @param array<string, mixed> $state   Scan state by reference.
	 * @return void
	 */
	public static function apply_to_state( $code, array $context, array &$state ) {
		switch ( $code ) {
			case 'muplugin_changed':
				self::merge_path_map( $state, 'muplugins', self::context_paths( $context, array( 'added', 'changed', 'removed' ) ) );
				break;

			case 'uploads_php_found':
				$files                  = self::string_list( isset( $context['files'] ) ? $context['files'] : array() );
				$known                  = isset( $state['uploads_found'] ) && is_array( $state['uploads_found'] ) ? $state['uploads_found'] : array();
				$state['uploads_found'] = array_values( array_unique( array_merge( $known, $files ) ) );
				break;

			case 'uploads_config_changed':
			case 'critical_file_changed':
				self::merge_fingerprint_map( $state, 'critical_files', $context );
				break;

			case 'option_changed':
				$name = isset( $context['option'] ) ? (string) $context['option'] : '';
				if ( '' !== $name ) {
					if ( ! isset( $state['options'] ) || ! is_array( $state['options'] ) ) {
						$state['options'] = array();
					}
					$state['options'][ $name ] = isset( $context['new'] ) ? $context['new'] : true;
				}
				break;

			case 'orphan_cron_found':
				$hooks                  = self::string_list( isset( $context['hooks'] ) ? $context['hooks'] : array() );
				$reported               = isset( $state['cron_reported'] ) && is_array( $state['cron_reported'] ) ? $state['cron_reported'] : array();
				$state['cron_reported'] = array_values( array_unique( array_merge( $reported, $hooks ) ) );
				break;

			case 'file_hash_mismatch':
				$files   = self::string_list( isset( $context['files'] ) ? $context['files'] : array() );
				$subject = isset( $context['subject'] ) ? sanitize_key( (string) $context['subject'] ) : 'core';
				if ( ! isset( $state['checksum_expected'] ) || ! is_array( $state['checksum_expected'] ) ) {
					$state['checksum_expected'] = array();
				}
				if ( ! isset( $state['checksum_expected'][ $subject ] ) || ! is_array( $state['checksum_expected'][ $subject ] ) ) {
					$state['checksum_expected'][ $subject ] = array();
				}
				$state['checksum_expected'][ $subject ] = array_values(
					array_unique( array_merge( $state['checksum_expected'][ $subject ], $files ) )
				);
				break;

			case 'admin_roster_changed':
			case 'hidden_admin_found':
				$roster = self::string_list( isset( $context['roster'] ) ? $context['roster'] : array() );
				if ( ! $roster ) {
					$roster = self::string_list( isset( $context['logins'] ) ? $context['logins'] : array() );
				}
				if ( $roster ) {
					$state['admin_roster'] = $roster;
				}
				break;

			case 'hidden_plugin_found':
			case 'silent_plugin_found':
				$dirs                            = self::string_list( isset( $context['dirs'] ) ? $context['dirs'] : array() );
				$known                           = isset( $state['plugin_dirs'] ) && is_array( $state['plugin_dirs'] ) ? $state['plugin_dirs'] : array();
				$state['plugin_dirs']            = array_values( array_unique( array_merge( $known, $dirs ) ) );
				$expected                        = isset( $state['plugin_expected_hidden'] ) && is_array( $state['plugin_expected_hidden'] ) ? $state['plugin_expected_hidden'] : array();
				$state['plugin_expected_hidden'] = array_values( array_unique( array_merge( $expected, $dirs ) ) );
				break;

			case 'db_script_found':
				$ids = self::string_list( isset( $context['ids'] ) ? $context['ids'] : array() );
				if ( ! isset( $state['db_scripts'] ) || ! is_array( $state['db_scripts'] ) ) {
					$state['db_scripts'] = array();
				}
				foreach ( $ids as $id ) {
					if ( ! isset( $state['db_scripts'][ $id ] ) ) {
						$state['db_scripts'][ $id ] = array(
							'option' => 'marked_expected',
							'hint'   => 'expected',
						);
					}
				}
				break;

			case 'option_denylist_hit':
				$names                             = self::string_list( isset( $context['names'] ) ? $context['names'] : array() );
				$exp                               = isset( $state['option_denylist_expected'] ) && is_array( $state['option_denylist_expected'] ) ? $state['option_denylist_expected'] : array();
				$rep                               = isset( $state['option_denylist_reported'] ) && is_array( $state['option_denylist_reported'] ) ? $state['option_denylist_reported'] : array();
				$state['option_denylist_expected'] = array_values( array_unique( array_merge( $exp, $names ) ) );
				$state['option_denylist_reported'] = array_values( array_unique( array_merge( $rep, $names ) ) );
				break;

			case 'option_name_suspect':
				$names                          = self::string_list( isset( $context['names'] ) ? $context['names'] : array() );
				$base                           = isset( $state['option_names_baseline'] ) && is_array( $state['option_names_baseline'] ) ? $state['option_names_baseline'] : array();
				$rep                            = isset( $state['option_names_reported'] ) && is_array( $state['option_names_reported'] ) ? $state['option_names_reported'] : array();
				$pend                           = isset( $state['option_names_pending'] ) && is_array( $state['option_names_pending'] ) ? $state['option_names_pending'] : array();
				$state['option_names_baseline'] = array_values( array_unique( array_merge( $base, $names ) ) );
				$state['option_names_reported'] = array_values( array_unique( array_merge( $rep, $names ) ) );
				$state['option_names_pending']  = array_values( array_diff( $pend, $names ) );
				break;

			case 'script_domain_new':
				$domains                          = self::string_list( isset( $context['domains'] ) ? $context['domains'] : array() );
				$base                             = isset( $state['script_domains'] ) && is_array( $state['script_domains'] ) ? $state['script_domains'] : array();
				$rep                              = isset( $state['script_domains_reported'] ) && is_array( $state['script_domains_reported'] ) ? $state['script_domains_reported'] : array();
				$state['script_domains']          = array_values( array_unique( array_merge( $base, $domains ) ) );
				$state['script_domains_reported'] = array_values( array_unique( array_merge( $rep, $domains ) ) );
				break;

			case 'cloaking_suspected':
				$domains                    = self::string_list( isset( $context['domains'] ) ? $context['domains'] : array() );
				$known                      = isset( $state['cloaking_expected'] ) && is_array( $state['cloaking_expected'] ) ? $state['cloaking_expected'] : array();
				$state['cloaking_expected'] = array_values( array_unique( array_merge( $known, $domains ) ) );
				break;

			case 'plugin_owner_changed':
				$slug = isset( $context['slug'] ) ? sanitize_title( (string) $context['slug'] ) : '';
				if ( '' !== $slug && isset( $state['plugin_directory'][ $slug ] ) && is_array( $state['plugin_directory'][ $slug ] ) ) {
					unset( $state['plugin_directory'][ $slug ]['owner_changed'] );
				}
				break;

			case 'plugin_directory_closed':
			case 'plugin_directory_abandoned':
				$slug = isset( $context['slug'] ) ? sanitize_title( (string) $context['slug'] ) : '';
				if ( '' !== $slug ) {
					if ( ! isset( $state['plugin_directory'] ) || ! is_array( $state['plugin_directory'] ) ) {
						$state['plugin_directory'] = array();
					}
					$status                             = ( 'plugin_directory_closed' === $code ) ? 'closed' : 'abandoned';
					$state['plugin_directory'][ $slug ] = array(
						'status' => $status,
						'at'     => time(),
					);
				}
				break;

			case 'plugin_vuln_found':
				$slug = isset( $context['slug'] ) ? sanitize_title( (string) $context['slug'] ) : '';
				if ( '' !== $slug ) {
					if ( ! isset( $state['vulns'] ) || ! is_array( $state['vulns'] ) ) {
						$state['vulns'] = array();
					}
					$baseline = isset( $context['baseline'] ) ? (string) $context['baseline'] : '';
					if ( '' === $baseline ) {
						$baseline = md5(
							implode(
								'|',
								array(
									isset( $context['vuln_uuid'] ) ? (string) $context['vuln_uuid'] : '',
									isset( $context['cve'] ) ? (string) $context['cve'] : '',
									isset( $context['version'] ) ? (string) $context['version'] : '',
								)
							)
						);
					}
					$state['vulns'][ $slug ] = $baseline;
				}
				break;

			case 'user_enum_probe':
				$via = isset( $context['via'] ) ? sanitize_key( (string) $context['via'] ) : '';
				if ( '' !== $via ) {
					if ( ! isset( $state['enum_expected'] ) || ! is_array( $state['enum_expected'] ) ) {
						$state['enum_expected'] = array();
					}
					$state['enum_expected'][ $via ] = true;
				}
				break;

			case 'admin_login_new_ip':
			case 'admin_login_new_device':
				$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;
				if ( $user_id < 1 && ! empty( $context['user_login'] ) ) {
					$u       = get_user_by( 'login', (string) $context['user_login'] );
					$user_id = $u ? (int) $u->ID : 0;
				}
				if ( $user_id < 1 ) {
					break;
				}
				$meta_key = 'karetaker_login_seen';
				$seen     = get_user_meta( $user_id, $meta_key, true );
				if ( ! is_array( $seen ) ) {
					$seen = array(
						'ips'     => array(),
						'devices' => array(),
					);
				}
				if ( ! isset( $seen['ips'] ) || ! is_array( $seen['ips'] ) ) {
					$seen['ips'] = array();
				}
				if ( ! isset( $seen['devices'] ) || ! is_array( $seen['devices'] ) ) {
					$seen['devices'] = array();
				}
				$now = time();
				if ( ! empty( $context['ip'] ) ) {
					$seen['ips'][ (string) $context['ip'] ] = $now;
				}
				if ( ! empty( $context['device'] ) ) {
					$seen['devices'][ (string) $context['device'] ] = array(
						'at' => $now,
						'ua' => isset( $context['ua'] ) ? substr( (string) $context['ua'], 0, 300 ) : '',
					);
				}
				update_user_meta( $user_id, $meta_key, $seen );
				break;
		}
	}

	/**
	 * Collect path-like strings from context keys.
	 *
	 * @param array<string, mixed> $context Context.
	 * @param string[]             $keys    Keys to read.
	 * @return string[]
	 */
	private static function context_paths( array $context, array $keys ) {
		$out = array();
		foreach ( $keys as $key ) {
			if ( ! isset( $context[ $key ] ) ) {
				continue;
			}
			$out = array_merge( $out, self::string_list( $context[ $key ] ) );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Normalize a list of strings from mixed context values.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private static function string_list( $value ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				$value = $decoded;
			} else {
				return array_values( array_filter( array_map( 'trim', explode( ', ', $value ) ), 'strlen' ) );
			}
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$out[] = (string) $item;
			}
		}
		return $out;
	}

	/**
	 * Refresh fingerprint map entries for listed paths using live files.
	 *
	 * @param array<string, mixed> $state   State.
	 * @param string               $key     State key.
	 * @param array<string, mixed> $context Context with paths or files.
	 * @return void
	 */
	private static function merge_fingerprint_map( array &$state, $key, array $context ) {
		$paths = self::string_list( isset( $context['paths'] ) ? $context['paths'] : array() );
		if ( ! $paths ) {
			$paths = self::string_list( isset( $context['files'] ) ? $context['files'] : array() );
		}
		if ( ! isset( $state[ $key ] ) || ! is_array( $state[ $key ] ) ) {
			$state[ $key ] = array();
		}
		foreach ( $paths as $rel ) {
			$fp = Karetaker_Scanner::fingerprint_relative( $rel );
			if ( null !== $fp ) {
				$state[ $key ][ $rel ] = $fp;
			} else {
				unset( $state[ $key ][ $rel ] );
			}
		}
	}

	/**
	 * Merge relative paths into a hash map by re-hashing live files when possible.
	 *
	 * @param array<string, mixed> $state State.
	 * @param string               $key   State key.
	 * @param string[]             $paths Relative paths.
	 * @return void
	 */
	private static function merge_path_map( array &$state, $key, array $paths ) {
		if ( ! isset( $state[ $key ] ) || ! is_array( $state[ $key ] ) ) {
			$state[ $key ] = array();
		}
		if ( 'muplugins' === $key && defined( 'WPMU_PLUGIN_DIR' ) ) {
			$state[ $key ] = Karetaker_Scanner::hash_dir( WPMU_PLUGIN_DIR );
			return;
		}
		foreach ( $paths as $rel ) {
			$state[ $key ][ $rel ] = true;
		}
	}
}
