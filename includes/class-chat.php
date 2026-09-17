<?php
/**
 * Optional Slack and Telegram ACT alerts (opt-in).
 *
 * @package Karetaker
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Posts short ACT notices to Slack Incoming Webhooks and/or Telegram Bot API.
 *
 * @since 1.0.0
 */
class Karetaker_Chat {

	/**
	 * When true (test alerts), requests wait for the reply and only a 2xx counts as sent.
	 *
	 * @var bool
	 */
	public static $verify = false;

	/**
	 * Whether a request was accepted: dispatched for normal alerts, answered 2xx when verifying.
	 *
	 * @since 1.0.0
	 * @param array|WP_Error $response HTTP response.
	 * @return bool
	 */
	private static function accepted( $response ) {
		if ( is_wp_error( $response ) ) {
			return false;
		}
		if ( ! self::$verify ) {
			return true;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	const DEDUPE_TTL = DAY_IN_SECONDS;

	/**
	 * Listens for recorded ACT events.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_action( 'karetaker_event_recorded', array( __CLASS__, 'maybe_deliver' ), 25, 4 );
	}

	/**
	 * Delivers Slack and/or Telegram notices for an ACT event.
	 *
	 * @since 1.0.0
	 * @param int    $id Event row ID.
	 * @param string $code Event code.
	 * @param int    $severity Severity constant.
	 * @param array  $context Event context.
	 * @return void
	 */
	public static function maybe_deliver( $id, $code, $severity, $context ) {
		if ( karetaker_is_disabled() ) {
			return;
		}

		$code    = sanitize_key( $code );
		$context = is_array( $context ) ? $context : array();

		if ( 'guard_tripped' === $code && isset( $context['check'] ) && 'mail_failed' === $context['check'] ) {
			return;
		}

		$text = self::message_for( $code, $context, (int) $id );

		if ( Karetaker_Routing::should_send( 'slack', (int) $severity ) ) {
			self::deliver_slack( $code, $context, $text );
		}

		if ( Karetaker_Routing::should_send( 'telegram', (int) $severity ) ) {
			self::deliver_telegram( $code, $context, $text );
		}

		if ( Karetaker_Routing::should_send( 'discord', (int) $severity ) ) {
			self::deliver_discord( $code, $context, $text );
		}

		if ( Karetaker_Routing::should_send( 'teams', (int) $severity ) ) {
			self::deliver_teams( $code, $context, $text );
		}
	}

	/**
	 * Text used for test alerts on every channel.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function test_message() {
		$site = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$site = is_string( $site ) && '' !== $site ? $site : home_url( '/' );

		return sprintf(
			/* translators: %s: site hostname */
			__( 'Karetaker test alert for %s. If you can read this, Act now alerts will reach you here.', 'karetaker' ),
			$site
		);
	}

	/**
	 * Sends a test message to Slack and/or Telegram when they are turned on.
	 *
	 * @since 1.0.0
	 * @return string[] Channel names a test was dispatched to.
	 */
	public static function send_test() {
		$sent    = array();
		$context = array( 'test' => microtime( true ) );
		if ( Karetaker_Routing::connected( 'slack' ) && self::deliver_slack( 'karetaker_test', $context, self::test_message() ) ) {
			$sent[] = 'Slack';
		}
		if ( Karetaker_Routing::connected( 'telegram' ) && self::deliver_telegram( 'karetaker_test', $context, self::test_message() ) ) {
			$sent[] = 'Telegram';
		}
		if ( Karetaker_Routing::connected( 'discord' ) && self::deliver_discord( 'karetaker_test', $context, self::test_message() ) ) {
			$sent[] = 'Discord';
		}
		if ( Karetaker_Routing::connected( 'teams' ) && self::deliver_teams( 'karetaker_test', $context, self::test_message() ) ) {
			$sent[] = 'Microsoft Teams';
		}
		return $sent;
	}

	/**
	 * Sends plain text to one chat channel without dedupe (used for summaries).
	 *
	 * @since 1.0.0
	 * @param string $channel telegram, slack, discord, or teams.
	 * @param string $text    Message.
	 * @return bool
	 */
	public static function send_text( $channel, $text ) {
		$context = array( 'summary' => microtime( true ) );
		switch ( $channel ) {
			case 'slack':
				return self::deliver_slack( 'karetaker_summary', $context, $text );
			case 'telegram':
				return self::deliver_telegram( 'karetaker_summary', $context, $text );
			case 'discord':
				return self::deliver_discord( 'karetaker_summary', $context, $text );
			case 'teams':
				return self::deliver_teams( 'karetaker_summary', $context, $text );
		}
		return false;
	}

	/**
	 * Builds a short plain-text notice.
	 *
	 * @since 1.0.0
	 * @param string               $code Event code.
	 * @param array<string, mixed> $context Event context.
	 * @param int                  $id Event ID.
	 * @return string
	 */
	public static function message_for( $code, array $context, $id ) {
		$subject = 'Karetaker: ' . Karetaker_Alerts::headline_for( $code, $context );
		$site    = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$site    = is_string( $site ) && '' !== $site ? $site : home_url( '/' );
		$link    = add_query_arg(
			array(
				'page' => 'karetaker',
				'tab'  => 'activity',
			),
			admin_url( 'admin.php' )
		);

		$lines = array(
			$subject,
			sprintf(
				/* translators: %s: site hostname */
				__( 'Site: %s', 'karetaker' ),
				$site
			),
			sprintf(
				/* translators: %d: event id */
				__( 'Event #%d', 'karetaker' ),
				(int) $id
			),
			$link,
		);

		return implode( "\n", $lines );
	}

	/**
	 * Posts to a Slack Incoming Webhook when configured.
	 *
	 * @since 1.0.0
	 * @param string               $code Event code.
	 * @param array<string, mixed> $context Event context.
	 * @param string               $text Message body.
	 * @return bool
	 */
	private static function deliver_slack( $code, array $context, $text ) {
		$url = self::sanitize_slack_url( (string) Karetaker_Settings::get( 'slack_webhook_url' ) );
		if ( '' === $url ) {
			return false;
		}

		$key = 'karetaker_slack_' . md5( $code . '|' . wp_json_encode( $context ) );
		if ( Karetaker_Routing::repeat_ttl() && get_transient( $key ) ) {
			return false;
		}

		$body = wp_json_encode(
			array(
				'text' => $text,
			)
		);
		if ( false === $body ) {
			return false;
		}

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'  => 5,
				'blocking' => ! self::$verify,
				'headers'  => array(
					'Content-Type' => 'application/json; charset=utf-8',
				),
				'body'     => $body,
			)
		);

		$ok = self::accepted( $response );
		if ( $ok && Karetaker_Routing::repeat_ttl() ) {
			set_transient( $key, 1, Karetaker_Routing::repeat_ttl() );
		}

		return $ok;
	}

	/**
	 * Posts to Telegram Bot API when configured.
	 *
	 * @since 1.0.0
	 * @param string               $code Event code.
	 * @param array<string, mixed> $context Event context.
	 * @param string               $text Message body.
	 * @return bool
	 */
	private static function deliver_telegram( $code, array $context, $text ) {
		$token   = self::sanitize_telegram_token( (string) Karetaker_Settings::get( 'telegram_bot_token' ) );
		$chat_id = self::sanitize_telegram_chat_id( (string) Karetaker_Settings::get( 'telegram_chat_id' ) );
		if ( '' === $token || '' === $chat_id ) {
			return false;
		}

		$key = 'karetaker_tg_' . md5( $code . '|' . wp_json_encode( $context ) );
		if ( Karetaker_Routing::repeat_ttl() && get_transient( $key ) ) {
			return false;
		}

		$url  = 'https://api.telegram.org/bot' . $token . '/sendMessage';
		$body = wp_json_encode(
			array(
				'chat_id'                  => $chat_id,
				'text'                     => $text,
				'disable_web_page_preview' => true,
			)
		);
		if ( false === $body ) {
			return false;
		}

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'  => 5,
				'blocking' => ! self::$verify,
				'headers'  => array(
					'Content-Type' => 'application/json; charset=utf-8',
				),
				'body'     => $body,
			)
		);

		$ok = self::accepted( $response );
		if ( $ok && Karetaker_Routing::repeat_ttl() ) {
			set_transient( $key, 1, Karetaker_Routing::repeat_ttl() );
		}

		return $ok;
	}

	/**
	 * Posts a JSON body once per event per day to a validated webhook URL.
	 *
	 * @since 1.0.0
	 * @param string               $prefix  Dedupe key prefix.
	 * @param string               $url     Webhook URL (already sanitized).
	 * @param array<string, mixed> $payload JSON payload.
	 * @param string               $code    Event code.
	 * @param array<string, mixed> $context Event context.
	 * @return bool
	 */
	private static function deliver_json( $prefix, $url, array $payload, $code, array $context ) {
		if ( '' === $url ) {
			return false;
		}

		$key = $prefix . md5( $code . '|' . wp_json_encode( $context ) );
		if ( Karetaker_Routing::repeat_ttl() && get_transient( $key ) ) {
			return false;
		}

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return false;
		}

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'  => 5,
				'blocking' => ! self::$verify,
				'headers'  => array(
					'Content-Type' => 'application/json; charset=utf-8',
				),
				'body'     => $body,
			)
		);

		$ok = self::accepted( $response );
		if ( $ok && Karetaker_Routing::repeat_ttl() ) {
			set_transient( $key, 1, Karetaker_Routing::repeat_ttl() );
		}

		return $ok;
	}

	/**
	 * Posts to a Discord channel webhook when configured.
	 *
	 * @since 1.0.0
	 * @param string               $code Event code.
	 * @param array<string, mixed> $context Event context.
	 * @param string               $text Message body.
	 * @return bool
	 */
	private static function deliver_discord( $code, array $context, $text ) {
		return self::deliver_json(
			'karetaker_dc_',
			self::sanitize_discord_url( (string) Karetaker_Settings::get( 'discord_webhook_url' ) ),
			array(
				'content'          => substr( $text, 0, 1900 ),
				'allowed_mentions' => array( 'parse' => array() ),
			),
			$code,
			$context
		);
	}

	/**
	 * Posts to a Microsoft Teams workflow or incoming webhook when configured.
	 *
	 * @since 1.0.0
	 * @param string               $code Event code.
	 * @param array<string, mixed> $context Event context.
	 * @param string               $text Message body.
	 * @return bool
	 */
	private static function deliver_teams( $code, array $context, $text ) {
		return self::deliver_json(
			'karetaker_ms_',
			self::sanitize_teams_url( (string) Karetaker_Settings::get( 'teams_webhook_url' ) ),
			array(
				'type'        => 'message',
				'text'        => $text,
				'attachments' => array(
					array(
						'contentType' => 'application/vnd.microsoft.card.adaptive',
						'content'     => array(
							'type'    => 'AdaptiveCard',
							'version' => '1.4',
							'body'    => array(
								array(
									'type' => 'TextBlock',
									'text' => $text,
									'wrap' => true,
								),
							),
						),
					),
				),
			),
			$code,
			$context
		);
	}

	/**
	 * Accepts Discord channel webhook HTTPS URLs only.
	 *
	 * @since 1.0.0
	 * @param string $url Candidate URL.
	 * @return string Empty when invalid.
	 */
	public static function sanitize_discord_url( $url ) {
		$url   = esc_url_raw( trim( (string) $url ), array( 'https' ) );
		$parts = wp_parse_url( $url );
		if ( ! $url || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return '';
		}
		$host = strtolower( $parts['host'] );
		if ( ! in_array( $host, array( 'discord.com', 'discordapp.com', 'ptb.discord.com', 'canary.discord.com' ), true ) || 0 !== strpos( $parts['path'], '/api/webhooks/' ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Accepts Microsoft Teams webhook HTTPS URLs (Workflows or legacy connectors) only.
	 *
	 * @since 1.0.0
	 * @param string $url Candidate URL.
	 * @return string Empty when invalid.
	 */
	public static function sanitize_teams_url( $url ) {
		$url  = esc_url_raw( trim( (string) $url ), array( 'https' ) );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return '';
		}
		foreach ( array( '.webhook.office.com', '.logic.azure.com', '.powerplatform.com', '.powerautomate.com' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return $url;
			}
		}
		return '';
	}

	/**
	 * Accepts Slack Incoming Webhook HTTPS URLs only.
	 *
	 * @since 1.0.0
	 * @param string $url Candidate URL.
	 * @return string Empty when invalid.
	 */
	public static function sanitize_slack_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return '';
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( 'hooks.slack.com' !== $host && 'hooks.slack-gov.com' !== $host ) {
			return '';
		}
		if ( 0 !== strpos( $path, '/services/' ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Bot tokens look like 123456:ABC-DEF…
	 *
	 * @since 1.0.0
	 * @param string $token Raw token.
	 * @return string
	 */
	public static function sanitize_telegram_token( $token ) {
		$token = trim( (string) $token );
		if ( ! preg_match( '/^\d{6,}:[A-Za-z0-9_-]{20,}$/', $token ) ) {
			return '';
		}
		return $token;
	}

	/**
	 * Numeric chat IDs or @channel usernames.
	 *
	 * @since 1.0.0
	 * @param string $chat_id Raw chat id.
	 * @return string
	 */
	public static function sanitize_telegram_chat_id( $chat_id ) {
		$chat_id = trim( (string) $chat_id );
		if ( preg_match( '/^-?\d{5,}$/', $chat_id ) ) {
			return $chat_id;
		}
		if ( preg_match( '/^@[A-Za-z0-9_]{5,}$/', $chat_id ) ) {
			return $chat_id;
		}
		return '';
	}
}
