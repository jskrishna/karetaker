<?php
/**
 * REST endpoints used by the admin screens (cookie auth + REST nonce).
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small JSON actions behind the admin UI: onboarding, toggles, issues, incidents, tokens.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Admin_Rest {

	const NS = 'karetaker/v1';

	/**
	 * Hooks route registration.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Permission callback factory.
	 *
	 * @since 1.0.0
	 * @param string $cap Capability.
	 * @return callable
	 */
	private static function can( $cap ) {
		return static function () use ( $cap ) {
			return current_user_can( $cap );
		};
	}

	/**
	 * Registers routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register() {
		$routes = array(
			'first-check' => array( 'POST', 'first_check', 'manage_options' ),
			'onboarding'  => array( 'POST', 'onboarding', 'manage_options' ),
			'harden'      => array( 'POST', 'harden', 'manage_options' ),
			'setting'     => array( 'POST', 'setting', 'manage_options' ),
			'scan'        => array( 'POST', 'scan', 'manage_options' ),
			'pause'       => array( 'POST', 'pause', 'manage_options' ),
			'channel'     => array( 'POST', 'channel', 'manage_options' ),
			'test-alert'  => array( 'POST', 'test_alert', 'manage_options' ),
			'issue'       => array( 'POST', 'issue', 'karetaker_resolve' ),
			'feed'        => array( 'GET', 'feed', 'karetaker_view_log' ),
			'sessions'    => array( 'POST', 'sessions', 'manage_options' ),
		);
		foreach ( $routes as $path => $route ) {
			register_rest_route(
				self::NS,
				'/admin/' . $path,
				array(
					'methods'             => $route[0],
					'callback'            => array( __CLASS__, $route[1] ),
					'permission_callback' => self::can( $route[2] ),
				)
			);
		}
	}

	/**
	 * Runs a full check for the setup screen and returns the grouped results.
	 *
	 * @since 1.0.0
	 * @return WP_REST_Response
	 */
	public static function first_check() {
		// The scan keeps its own time budget and resumes where it stopped, so a short PHP limit is safe.
		Karetaker_Scanner::run( 20 );
		$checks = Karetaker_Admin_Data::checks();
		$found  = count(
			array_filter(
				$checks,
				static function ( $row ) {
					return '' !== $row['level'];
				}
			)
		);
		return rest_ensure_response(
			array(
				'checks' => array_slice( $checks, 0, 6 ),
				'found'  => $found,
			)
		);
	}

	/**
	 * Saves the setup answers and marks setup done.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function onboarding( $request ) {
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		$type  = sanitize_key( (string) $request->get_param( 'site_type' ) );
		$later = (bool) $request->get_param( 'later' );

		if ( ! $later ) {
			$changes = array();
			if ( is_email( $email ) ) {
				$changes['alert_email'] = $email;
			}
			if ( in_array( $type, array( 'blog', 'business', 'store', 'client' ), true ) ) {
				$changes['site_type'] = $type;
			}
			Karetaker_Settings::update( $changes );

			$harden = (array) $request->get_param( 'harden' );
			$next   = Karetaker_Settings::harden();
			foreach ( array( 'user_enum', 'xmlrpc', 'file_editor' ) as $key ) {
				if ( array_key_exists( $key, $harden ) ) {
					$next[ $key ] = (bool) $harden[ $key ];
				}
			}
			Karetaker_Settings::update( array( 'harden' => $next ) );

			if ( 'client' === $type ) {
				Karetaker_Routing::set( 'email', 'weekly', true );
			}
		}

		update_option( 'karetaker_onboarded', time(), false );
		Karetaker_Events::record( 'onboarding_finished', array( 'skipped' => $later ? 1 : 0 ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Switches one protection on or off.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function harden( $request ) {
		$key = sanitize_key( (string) $request->get_param( 'key' ) );
		if ( ! in_array( $key, Karetaker_Harden::KEYS, true ) ) {
			return new WP_Error( 'karetaker_bad_key', __( 'Unknown protection.', 'karetaker' ), array( 'status' => 400 ) );
		}
		$on           = (bool) $request->get_param( 'on' );
		$next         = Karetaker_Settings::harden();
		$was          = ! empty( $next[ $key ] );
		$next[ $key ] = $on;
		Karetaker_Settings::update( array( 'harden' => $next ) );
		if ( $was !== $on ) {
			Karetaker_Events::record(
				'setting_changed',
				array(
					'option' => 'harden.' . $key,
					'value'  => $on ? '1' : '0',
				)
			);
		}
		return rest_ensure_response( array( 'on' => $on ) );
	}

	/**
	 * Saves one simple setting.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function setting( $request ) {
		$key   = sanitize_key( (string) $request->get_param( 'key' ) );
		$value = $request->get_param( 'value' );
		switch ( $key ) {
			case 'vuln_lookup_enabled':
				$clean = (bool) $value;
				break;
			case 'site_type':
				$clean = in_array( $value, array( 'blog', 'business', 'store', 'client' ), true ) ? $value : '';
				break;
			case 'alert_email':
				$clean = sanitize_email( (string) $value );
				if ( '' !== trim( (string) $value ) && ! is_email( $clean ) ) {
					return new WP_Error( 'karetaker_bad_email', __( 'That email address doesn’t look right.', 'karetaker' ), array( 'status' => 400 ) );
				}
				break;
			case 'weekly_slot':
				$clean = 'fri17' === $value ? 'fri17' : 'mon09';
				break;
			case 'row_cap':
				$clean = max( Karetaker_Settings::ROW_CAP_MIN, min( Karetaker_Settings::ROW_CAP_MAX, absint( $value ) ) );
				break;
			case 'trusted_proxies':
				$clean = self::proxy_lines( sanitize_textarea_field( (string) $value ) );
				break;
			case 'weekly_summary':
				Karetaker_Routing::set( 'email', 'weekly', (bool) $value );
				self::log( 'routes.email.weekly', $value ? '1' : '0' );
				return rest_ensure_response( array( 'ok' => true ) );
			default:
				/**
				 * Lets add-ons handle their own settings on this endpoint.
				 *
				 * Return a clean value to save it, a WP_Error to reject it, or a WP_REST_Response
				 * when the add-on saved it itself. Leave null for unknown keys.
				 *
				 * @since 1.1.0
				 * @param mixed           $result  Null.
				 * @param string          $key     Setting key.
				 * @param WP_REST_Request $request Request.
				 */
				$clean = apply_filters( 'karetaker_admin_setting', null, $key, $request );
				if ( null === $clean ) {
					return new WP_Error( 'karetaker_bad_setting', __( 'Unknown setting.', 'karetaker' ), array( 'status' => 400 ) );
				}
				if ( is_wp_error( $clean ) || $clean instanceof WP_REST_Response ) {
					return $clean;
				}
		}
		Karetaker_Settings::update( array( $key => $clean ) );
		if ( 'weekly_slot' === $key ) {
			Karetaker_Summary::sync_schedule();
		}
		if ( is_bool( $clean ) ) {
			$logged = $clean ? '1' : '0';
		} else {
			$logged = is_array( $clean ) ? implode( ', ', $clean ) : (string) $clean;
		}
		self::log( $key, $logged );
		return rest_ensure_response(
			array(
				'ok'    => true,
				'value' => $clean,
			)
		);
	}

	/**
	 * Gives a model error a 400 status (404 when something is missing) so it is not reported as a server fault.
	 *
	 * @since 1.0.0
	 * @param WP_Error $error Error.
	 * @return WP_Error
	 */
	private static function error( WP_Error $error ) {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			$error->add_data( array( 'status' => false !== strpos( (string) $error->get_error_code(), 'missing' ) ? 404 : 400 ) );
		}
		return $error;
	}

	/**
	 * Parses trusted proxy lines into valid IPs and CIDRs.
	 *
	 * @since 1.0.0
	 * @param string $raw One IP or CIDR per line.
	 * @return string[]
	 */
	private static function proxy_lines( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) $raw ) as $line ) {
			$line  = trim( $line );
			$parts = explode( '/', $line, 2 );
			if ( '' === $line || ! filter_var( $parts[0], FILTER_VALIDATE_IP ) ) {
				continue;
			}
			if ( isset( $parts[1] ) ) {
				$max = false !== strpos( $parts[0], ':' ) ? 128 : 32;
				if ( ! ctype_digit( $parts[1] ) || (int) $parts[1] > $max ) {
					continue;
				}
			}
			$out[] = $line;
		}
		return array_slice( $out, 0, 50 );
	}

	/**
	 * Audit entry for a setting change.
	 *
	 * @since 1.0.0
	 * @param string $option Option key.
	 * @param string $value  New value.
	 * @return void
	 */
	private static function log( $option, $value ) {
		Karetaker_Events::record(
			'setting_changed',
			array(
				'option' => $option,
				'value'  => $value,
			)
		);
	}

	/**
	 * Runs a check now.
	 *
	 * @since 1.0.0
	 * @return WP_REST_Response
	 */
	public static function scan() {
		Karetaker_Scanner::run();
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Pauses or resumes alerts.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function pause( $request ) {
		$hours = (int) $request->get_param( 'hours' );
		$hours = in_array( $hours, array( 1, 2, 4 ), true ) ? $hours : 0;
		$until = Karetaker_Summary::set_pause( $hours );
		self::log( 'alerts_paused_until', $until ? gmdate( 'Y-m-d H:i:s', $until ) : 'resumed' );
		return rest_ensure_response( array( 'until' => $until ) );
	}

	/**
	 * Connects, edits or removes an alert channel.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function channel( $request ) {
		$channel = sanitize_key( (string) $request->get_param( 'channel' ) );
		$remove  = (bool) $request->get_param( 'remove' );
		$f       = (array) $request->get_param( 'fields' );
		$changes = array();
		switch ( $channel ) {
			case 'telegram':
				$changes['telegram_bot_token'] = $remove ? '' : Karetaker_Chat::sanitize_telegram_token( isset( $f['token'] ) ? (string) $f['token'] : '' );
				$changes['telegram_chat_id']   = $remove ? '' : Karetaker_Chat::sanitize_telegram_chat_id( isset( $f['chat_id'] ) ? (string) $f['chat_id'] : '' );
				if ( ! $remove && '' === $changes['telegram_bot_token'] ) {
					$changes['telegram_bot_token'] = (string) Karetaker_Settings::get( 'telegram_bot_token' );
				}
				$ok = $remove || ( '' !== $changes['telegram_bot_token'] && '' !== $changes['telegram_chat_id'] );
				break;
			case 'slack':
				$changes['slack_webhook_url'] = $remove ? '' : Karetaker_Chat::sanitize_slack_url( isset( $f['url'] ) ? (string) $f['url'] : '' );
				$ok                           = $remove || '' !== $changes['slack_webhook_url'];
				break;
			case 'discord':
				$changes['discord_webhook_url'] = $remove ? '' : Karetaker_Chat::sanitize_discord_url( isset( $f['url'] ) ? (string) $f['url'] : '' );
				$ok                             = $remove || '' !== $changes['discord_webhook_url'];
				break;
			case 'teams':
				$changes['teams_webhook_url'] = $remove ? '' : Karetaker_Chat::sanitize_teams_url( isset( $f['url'] ) ? (string) $f['url'] : '' );
				$ok                           = $remove || '' !== $changes['teams_webhook_url'];
				break;
			case 'webhook':
				$url                       = $remove ? '' : esc_url_raw( isset( $f['url'] ) ? (string) $f['url'] : '', array( 'https', 'http' ) );
				$changes['webhook_url']    = $url && wp_http_validate_url( $url ) ? $url : '';
				$changes['webhook_secret'] = $remove ? '' : sanitize_text_field( isset( $f['secret'] ) ? (string) $f['secret'] : '' );
				$ok                        = $remove || '' !== $changes['webhook_url'];
				break;
			case 'email':
				$email                  = sanitize_email( isset( $f['email'] ) ? (string) $f['email'] : '' );
				$changes['alert_email'] = is_email( $email ) ? $email : '';
				$ok                     = true;
				break;
			default:
				$ok = false;
		}
		if ( ! $ok ) {
			return new WP_Error( 'karetaker_bad_channel', __( 'Those details don’t look right. Check the URL or token and try again.', 'karetaker' ), array( 'status' => 400 ) );
		}
		Karetaker_Settings::update( $changes );
		if ( 'email' !== $channel ) {
			Karetaker_Routing::set( $channel, 'act', ! $remove );
		}
		self::log( 'channel.' . $channel, $remove ? 'removed' : 'connected' );
		return rest_ensure_response( array( 'connected' => Karetaker_Routing::connected( $channel ) ) );
	}

	/**
	 * Sends a test alert to every connected channel with any rule on.
	 *
	 * @since 1.0.0
	 * @return WP_REST_Response
	 */
	public static function test_alert() {
		Karetaker_Chat::$verify    = true;
		Karetaker_Webhook::$verify = true;
		$sent                      = array();
		$routes                    = Karetaker_Routing::routes();
		if ( Karetaker_Routing::connected( 'email' ) && Karetaker_Alerts::send_test() ) {
			$sent[] = __( 'Email', 'karetaker' );
		}
		foreach ( array( 'telegram', 'slack', 'discord', 'teams' ) as $channel ) {
			if ( Karetaker_Routing::connected( $channel ) && in_array( true, $routes[ $channel ], true ) && Karetaker_Chat::send_text( $channel, Karetaker_Chat::test_message() ) ) {
				$sent[] = Karetaker_Routing::labels()[ $channel ];
			}
		}
		if ( Karetaker_Routing::connected( 'webhook' ) && Karetaker_Webhook::send_test() ) {
			$sent[] = __( 'Webhook', 'karetaker' );
		}
		return rest_ensure_response( array( 'sent' => $sent ) );
	}

	/**
	 * Acknowledge, resolve, reopen, assign, or mark an issue expected.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function issue( $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( 'expected' === $action && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'karetaker_forbidden', __( 'Only administrators can change the baseline.', 'karetaker' ), array( 'status' => 403 ) );
		}
		$issue = Karetaker_Issues::act( (int) $request->get_param( 'id' ), $action, (int) $request->get_param( 'owner' ) );
		return is_wp_error( $issue ) ? self::error( $issue ) : rest_ensure_response( array( 'issue' => $issue ) );
	}

	/**
	 * Day-grouped activity feed page.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function feed( $request ) {
		$mode = 'all' === $request->get_param( 'mode' ) ? 'all' : 'important';
		$page = max( 1, (int) $request->get_param( 'page' ) );
		return rest_ensure_response( Karetaker_Admin_Data::feed( $mode, $page ) );
	}

	/**
	 * Ends one session or every administrator session but this one.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function sessions( $request ) {
		if ( 'all' === $request->get_param( 'scope' ) ) {
			$count = Karetaker_Admin_Watch::end_all_admin_sessions();
			self::log( 'admin_sessions', 'signed_out_all:' . $count );
		} else {
			$user_id = (int) $request->get_param( 'user_id' );
			$admins  = Karetaker_Admin_Watch::admin_ids();
			if ( isset( $admins[ $user_id ] ) ) {
				Karetaker_Admin_Watch::end_session( $user_id, sanitize_key( (string) $request->get_param( 'verifier' ) ) );
				self::log( 'admin_sessions', 'signed_out_one:' . $admins[ $user_id ] );
			}
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
