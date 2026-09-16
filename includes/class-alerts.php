<?php
/**
 * Scarce ACT-now email alerts.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scarce ACT-severity email alerts with daily dedupe.
 *
 * @since 0.1.0
 * @package Karetaker
 */
class Karetaker_Alerts {

	const DEDUPE_TTL = DAY_IN_SECONDS;

	/**
	 * Plain-text AltBody attached for the current wp_mail send.
	 *
	 * @var string
	 */
	private static $alt_body = '';

	/**
	 * Whether the current send should embed the logo as a CID image.
	 *
	 * @var bool
	 */
	private static $embed_logo = false;

	/**
	 * Listens for recorded events and sends ACT emails when enabled.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function init() {
		add_action( 'karetaker_event_recorded', array( __CLASS__, 'maybe_send' ), 10, 4 );
	}

	/**
	 * Sends a deduped ACT alert email for a newly recorded event.
	 *
	 * @since 0.1.0
	 * @param int    $id Event row ID.
	 * @param string $code Event code.
	 * @param int    $severity Severity constant.
	 * @param array  $context Event context.
	 * @return bool
	 */
	public static function maybe_send( $id, $code, $severity, $context ) {
		if ( karetaker_is_disabled() ) {
			return false;
		}

		if ( Karetaker_Events::SEVERITY_ACT !== (int) $severity ) {
			return false;
		}

		if ( ! Karetaker_Settings::get( 'alerts_enabled' ) ) {
			return false;
		}

		$code    = sanitize_key( $code );
		$context = is_array( $context ) ? $context : array();

		// Mail is already broken; sending another ACT email would recurse on wp_mail_failed.
		if ( 'guard_tripped' === $code && isset( $context['check'] ) && 'mail_failed' === $context['check'] ) {
			return false;
		}

		$key = self::dedupe_key( $code, $context );

		if ( get_transient( $key ) ) {
			return false;
		}

		$to = Karetaker_Settings::alert_email();
		if ( ! is_email( $to ) ) {
			return false;
		}

		$subject = self::subject_for( $code, $context );
		$plain   = self::body_for( $code, $context, (int) $id );
		$html    = self::html_body_for( $code, $context, (int) $id );

		self::$alt_body   = $plain;
		self::$embed_logo = true;
		add_action( 'phpmailer_init', array( __CLASS__, 'phpmailer_prepare' ), 20 );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$sent    = wp_mail( $to, $subject, $html, $headers );

		remove_action( 'phpmailer_init', array( __CLASS__, 'phpmailer_prepare' ), 20 );
		self::$alt_body   = '';
		self::$embed_logo = false;

		if ( $sent ) {
			set_transient( $key, 1, self::DEDUPE_TTL );
		}

		return (bool) $sent;
	}

	/**
	 * Attaches plain-text AltBody and embeds the logo for multipart HTML mail.
	 *
	 * @since 0.1.3
	 * @param PHPMailer $phpmailer Mailer instance.
	 * @return void
	 */
	public static function phpmailer_prepare( $phpmailer ) {
		if ( ! is_object( $phpmailer ) ) {
			return;
		}

		if ( '' !== self::$alt_body && property_exists( $phpmailer, 'AltBody' ) ) {
			// PHPMailer public API uses camelCase AltBody.
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$phpmailer->AltBody = self::$alt_body;
		}

		if ( ! self::$embed_logo || ! method_exists( $phpmailer, 'addEmbeddedImage' ) ) {
			return;
		}

		$path = self::logo_path();
		if ( '' === $path ) {
			return;
		}

		$phpmailer->addEmbeddedImage( $path, 'karetaker-logo', 'email-logo.png', 'base64', 'image/png' );
	}

	/**
	 * Absolute filesystem path to the shipped email logo, or empty.
	 *
	 * @since 0.1.3
	 * @return string
	 */
	private static function logo_path() {
		if ( ! defined( 'KARETAKER_DIR' ) ) {
			return '';
		}

		$path = KARETAKER_DIR . 'assets/email-logo.png';
		return is_readable( $path ) ? $path : '';
	}

	/**
	 * Logo URL/CID for the HTML header.
	 *
	 * Uses a CID when sending so clients do not need a remote fetch (no third-party host).
	 * Falls back to the plugin asset URL for on-screen previews.
	 *
	 * @since 0.1.3
	 * @return string
	 */
	private static function logo_src() {
		if ( '' === self::logo_path() ) {
			return '';
		}

		if ( self::$embed_logo ) {
			return 'cid:karetaker-logo';
		}

		if ( ! defined( 'KARETAKER_FILE' ) ) {
			return '';
		}

		return plugins_url( 'assets/email-logo.png', KARETAKER_FILE );
	}

	/**
	 * Builds a transient key for alert deduplication.
	 *
	 * @since 0.1.0
	 * @param string $code Event code.
	 * @param array  $context Event context.
	 * @return string
	 */
	public static function dedupe_key( $code, array $context ) {
		ksort( $context );
		return 'karetaker_alert_' . md5( $code . '|' . wp_json_encode( $context ) );
	}

	/**
	 * Human subject line for an event code.
	 *
	 * @since 0.1.0
	 * @param string $code Event code.
	 * @param array  $context Event context.
	 * @return string
	 */
	public static function subject_for( $code, array $context ) {
		$check = isset( $context['check'] ) ? (string) $context['check'] : '';

		$map = array(
			'admin_user_added'    => __( 'A new administrator account appeared', 'karetaker' ),
			'role_escalated'      => __( 'A user was made administrator', 'karetaker' ),
			'registration_opened' => __( 'Anyone can register on your site', 'karetaker' ),
			'muplugin_changed'    => __( 'A must-use plugin file changed', 'karetaker' ),
			'uploads_php_found'   => __( 'Executable PHP appeared under uploads', 'karetaker' ),
			'file_hash_mismatch'  => __( 'A plugin or theme no longer matches wordpress.org', 'karetaker' ),
			'guard_tripped'       => self::guard_subject( $check ),
		);

		$verdict = isset( $map[ $code ] ) ? $map[ $code ] : __( 'Something needs your attention', 'karetaker' );

		return '[Karetaker] ' . $verdict;
	}

	/**
	 * Headline without the [Karetaker] prefix.
	 *
	 * @since 0.1.3
	 * @param string $code Event code.
	 * @param array  $context Event context.
	 * @return string
	 */
	public static function headline_for( $code, array $context ) {
		$subject = self::subject_for( $code, $context );
		return preg_replace( '/^\[Karetaker\]\s*/', '', $subject );
	}

	/**
	 * Subject fragment for a Guard check key.
	 *
	 * @since 0.1.0
	 * @param string $check Guard check key.
	 * @return string
	 */
	private static function guard_subject( $check ) {
		$map = array(
			'blog_public'         => __( 'Search engines were discouraged', 'karetaker' ),
			'mail_failed'         => __( 'WordPress could not send email', 'karetaker' ),
			'admin_email_invalid' => __( 'The admin email address is invalid', 'karetaker' ),
			'no_administrator'    => __( 'No administrator remains on the site', 'karetaker' ),
		);
		return isset( $map[ $check ] ) ? $map[ $check ] : __( 'A site setting needs attention', 'karetaker' );
	}

	/**
	 * Plain-text email body for an ACT alert.
	 *
	 * @since 0.1.0
	 * @param string $code Event code.
	 * @param array  $context Event context.
	 * @param int    $id Event row ID.
	 * @return string
	 */
	public static function body_for( $code, array $context, $id ) {
		$home     = home_url( '/' );
		$tools    = admin_url( 'admin.php?page=karetaker&tab=activity' );
		$guidance = Karetaker_Guidance::for_event( $code, $context );

		$lines   = array();
		$lines[] = self::subject_for( $code, $context );
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: site home URL */
			__( 'Site: %s', 'karetaker' ),
			$home
		);
		$lines[] = sprintf(
			/* translators: 1: event code, 2: event ID */
			__( 'Event: %1$s (#%2$d)', 'karetaker' ),
			$code,
			(int) $id
		);
		if ( ! empty( $guidance['summary'] ) ) {
			$lines[] = '';
			$lines[] = $guidance['summary'];
		}
		if ( ! empty( $guidance['steps'] ) ) {
			$lines[] = '';
			$lines[] = __( 'What you should do:', 'karetaker' );
			$step_n  = 1;
			foreach ( $guidance['steps'] as $step ) {
				$lines[] = $step_n . '. ' . $step;
				++$step_n;
			}
		}
		if ( ! empty( $guidance['link'] ) ) {
			$lines[] = '';
			$label   = ! empty( $guidance['link_label'] ) ? $guidance['link_label'] : __( 'Open related screen', 'karetaker' );
			$lines[] = $label . ': ' . $guidance['link'];
		}
		if ( $context ) {
			$lines[] = '';
			$lines[] = __( 'Technical details:', 'karetaker' ) . ' ' . wp_json_encode( $context );
		}
		$lines[] = '';
		$lines[] = __( 'Open Karetaker Activity:', 'karetaker' ) . ' ' . $tools;
		$lines[] = '';
		$lines[] = __( 'Turn off alerts anytime in Karetaker → Settings.', 'karetaker' );

		return implode( "\n", $lines );
	}

	/**
	 * HTML email body (Watchtower template) for an ACT alert.
	 *
	 * @since 0.1.3
	 * @param string $code Event code.
	 * @param array  $context Event context.
	 * @param int    $id Event row ID.
	 * @return string
	 */
	public static function html_body_for( $code, array $context, $id ) {
		$home     = home_url( '/' );
		$host     = wp_parse_url( $home, PHP_URL_HOST );
		$host     = is_string( $host ) && '' !== $host ? $host : $home;
		$activity = admin_url( 'admin.php?page=karetaker&tab=activity' );
		$guidance = Karetaker_Guidance::for_event( $code, $context );
		$headline = self::headline_for( $code, $context );
		$summary  = ! empty( $guidance['summary'] ) ? (string) $guidance['summary'] : '';
		$when     = wp_date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ) );

		$steps_html = '';
		if ( ! empty( $guidance['steps'] ) && is_array( $guidance['steps'] ) ) {
			$steps_html .= '<ol style="margin:0 0 24px;padding-left:20px;font-size:15px;line-height:1.55;color:#1d2327;">';
			foreach ( $guidance['steps'] as $step ) {
				$steps_html .= '<li style="margin-bottom:8px;">' . esc_html( (string) $step ) . '</li>';
			}
			$steps_html .= '</ol>';
		}

		$primary_label = ! empty( $guidance['link_label'] ) ? (string) $guidance['link_label'] : __( 'Open related screen', 'karetaker' );
		$primary_url   = ! empty( $guidance['link'] ) ? (string) $guidance['link'] : $activity;

		$detail_line = self::context_line( $context );
		$logo_src    = self::logo_src();

		$html  = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /></head>';
		$html .= '<body style="margin:0;padding:0;background:#f5f4f0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1d2327;">';
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f4f0;padding:32px 16px;"><tr><td align="center">';
		$html .= '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background:#ffffff;border:1px solid #e2e0da;border-radius:8px;overflow:hidden;">';

		$html .= '<tr><td style="height:3px;background-color:#b36a1e;font-size:0;line-height:0;">&nbsp;</td></tr>';

		$html .= '<tr><td style="padding:20px 24px;border-bottom:1px solid #e2e0da;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>';
		if ( '' !== $logo_src ) {
			$html .= '<td style="vertical-align:middle;width:48px;padding-right:8px;"><img src="' . esc_url( $logo_src, array( 'http', 'https', 'cid' ) ) . '" width="48" height="48" alt="Karetaker" style="display:block;border:0;border-radius:8px;background:#141414;" /></td>';
		}
		$html .= '<td style="vertical-align:middle;"><div style="font-size:18px;font-weight:600;line-height:1.25;color:#1d2327;">Karetaker</div>';
		$html .= '<div style="font-size:13px;line-height:1.35;color:#646970;margin-top:3px;">' . esc_html__( 'A watchtower for WordPress', 'karetaker' ) . '</div></td>';
		$html .= '<td align="right" style="vertical-align:middle;"><span style="display:inline-block;background:#fcf0f1;color:#8a2424;font-size:11px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;padding:6px 10px;border-radius:8px;">' . esc_html__( 'Act-now', 'karetaker' ) . '</span></td>';
		$html .= '</tr></table></td></tr>';

		$html .= '<tr><td style="padding:28px 24px 24px;">';
		$html .= '<h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;font-weight:650;color:#141414;">' . esc_html( $headline ) . '</h1>';
		if ( '' !== $summary ) {
			$html .= '<p style="margin:0 0 22px;font-size:15px;line-height:1.55;color:#5c5c5c;">' . esc_html( $summary ) . '</p>';
		}

		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 24px;background:#fafaf8;border:1px solid #e2e0da;border-radius:8px;"><tr><td style="padding:14px 16px;font-size:13px;line-height:1.55;color:#646970;">';
		$html .= '<a href="' . esc_url( $home ) . '" style="color:#b36a1e;text-decoration:none;font-weight:500;">' . esc_html( $host ) . '</a>';
		$html .= ' <span style="color:#c3c4c7;">·</span> ';
		$html .= '<span style="font-family:ui-monospace,Menlo,Consolas,monospace;color:#1d2327;">' . esc_html( $code . ' (#' . (int) $id . ')' ) . '</span>';
		$html .= ' <span style="color:#c3c4c7;">·</span> ';
		$html .= esc_html( (string) $when );
		$html .= '</td></tr></table>';

		if ( '' !== $steps_html ) {
			$html .= '<div style="margin:0 0 10px;font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#646970;">' . esc_html__( 'Do this', 'karetaker' ) . '</div>';
			$html .= $steps_html;
		}

		$html .= '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 28px;"><tr>';
		$html .= '<td style="border-radius:8px;background:#b36a1e;"><a href="' . esc_url( $primary_url ) . '" style="display:inline-block;padding:11px 16px;font-size:13px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">' . esc_html( $primary_label ) . '</a></td>';
		$html .= '<td width="10">&nbsp;</td>';
		$html .= '<td style="border-radius:8px;border:1px solid #c3c4c7;background:#ffffff;"><a href="' . esc_url( $activity ) . '" style="display:inline-block;padding:11px 16px;font-size:13px;font-weight:600;color:#1d2327;text-decoration:none;border-radius:8px;">' . esc_html__( 'Activity', 'karetaker' ) . '</a></td>';
		$html .= '</tr></table>';

		if ( '' !== $detail_line ) {
			$html .= '<div style="margin:0 0 6px;font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#646970;">' . esc_html__( 'Details', 'karetaker' ) . '</div>';
			$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f7f7;border:1px solid #e2e0da;border-radius:8px;"><tr><td style="padding:12px 14px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;line-height:1.5;color:#50575e;word-break:break-word;">' . esc_html( $detail_line ) . '</td></tr></table>';
		}

		$html .= '</td></tr>';
		$html .= '</table>';

		$html .= '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;margin-top:12px;"><tr>';
		$html .= '<td style="font-size:12px;line-height:1.45;color:#8c8f94;vertical-align:middle;">' . esc_html__( 'Turn off alerts in Karetaker → Settings.', 'karetaker' ) . '</td>';
		$html .= '<td align="right" style="font-size:12px;line-height:1.45;color:#8c8f94;vertical-align:middle;">' . esc_html__( 'A watchtower for WordPress', 'karetaker' ) . '</td>';
		$html .= '</tr></table>';

		$html .= '</td></tr></table></body></html>';

		return $html;
	}

	/**
	 * Flattens event context into one quiet detail line (no JSON dump).
	 *
	 * @since 0.1.3
	 * @param array $context Event context.
	 * @return string
	 */
	private static function context_line( array $context ) {
		if ( ! $context ) {
			return '';
		}

		$parts = array();
		foreach ( $context as $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			} elseif ( is_bool( $value ) ) {
				$value = $value ? '1' : '0';
			} elseif ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Builds an on-screen sample ACT email (not sent).
	 *
	 * @since 0.1.2
	 * @return array{code:string,subject:string,body:string,html:string,to:string}
	 */
	public static function sample_preview() {
		$code    = 'admin_user_added';
		$context = array(
			'user_login' => 'newadmin',
			'user_email' => 'newadmin@example.com',
			'roles'      => array( 'administrator' ),
		);

		$to = Karetaker_Settings::alert_email();
		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}

		return array(
			'code'    => $code,
			'subject' => self::subject_for( $code, $context ),
			'body'    => self::body_for( $code, $context, 1 ),
			'html'    => self::html_body_for( $code, $context, 1 ),
			'to'      => $to,
		);
	}
}
