<?php
/**
 * Email template shared by alerts, summaries and test messages.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and sends Karetaker emails with one consistent layout.
 *
 * Every email is sent as HTML with a plain-text alternative. The layout uses tables and
 * inline styles only, loads no remote images (the brand mark is drawn in HTML), and keeps
 * to the admin palette: tower #141414, lamp #f0c36d, stone #F2EFE8, amber #b36a1e.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Email {

	/**
	 * Plain-text body for the message being sent.
	 *
	 * @var string
	 */
	private static $text = '';

	/**
	 * Visual settings per level: lamp colour, pill text colour, pill background, label.
	 *
	 * @since 1.0.0
	 * @param string $level act, review or safe.
	 * @return array{lamp: string, ink: string, soft: string, label: string}
	 */
	public static function level( $level ) {
		$levels = array(
			'act'    => array(
				'lamp'  => '#e5484d',
				'ink'   => '#ffb4b5',
				'soft'  => '#3a1d1e',
				'label' => __( 'Act now', 'karetaker' ),
			),
			'review' => array(
				'lamp'  => '#e8a13a',
				'ink'   => '#f5cf8e',
				'soft'  => '#3a2c17',
				'label' => __( 'Check this', 'karetaker' ),
			),
			'safe'   => array(
				'lamp'  => '#f0c36d',
				'ink'   => '#b9e6c3',
				'soft'  => '#1d3322',
				'label' => __( 'All clear', 'karetaker' ),
			),
		);
		return isset( $levels[ $level ] ) ? $levels[ $level ] : $levels['safe'];
	}

	/**
	 * Site host used in subjects and headers.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function host() {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return is_string( $host ) && '' !== $host ? $host : home_url( '/' );
	}

	/**
	 * Subject line: status first, then what happened, then which site.
	 *
	 * Example: "Act now: A must-use plugin file changed · example.com".
	 *
	 * @since 1.0.0
	 * @param string $level act, review, safe, or '' for no status prefix.
	 * @param string $text  What happened.
	 * @return string
	 */
	public static function subject( $level, $text ) {
		$prefix = '' !== $level ? self::level( $level )['label'] . ': ' : '';
		return $prefix . $text . ' · ' . self::host();
	}

	/**
	 * Sends an email built from the template.
	 *
	 * @since 1.0.0
	 * @param string               $to      Recipient.
	 * @param string               $subject Subject line.
	 * @param array<string, mixed> $args    Template arguments, see render().
	 * @return bool
	 */
	public static function send( $to, $subject, array $args ) {
		if ( ! is_email( $to ) ) {
			return false;
		}
		$mail       = self::render( $args );
		self::$text = $mail['text'];

		add_action( 'phpmailer_init', array( __CLASS__, 'add_plain_text' ), 20 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ), 20 );

		$sent = wp_mail( $to, $subject, $mail['html'], array( 'Content-Type: text/html; charset=UTF-8' ) );

		remove_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ), 20 );
		remove_action( 'phpmailer_init', array( __CLASS__, 'add_plain_text' ), 20 );
		self::$text = '';

		return (bool) $sent;
	}

	/**
	 * Adds the plain-text alternative so every client has a readable version.
	 *
	 * @since 1.0.0
	 * @param object $phpmailer PHPMailer instance.
	 * @return void
	 */
	public static function add_plain_text( $phpmailer ) {
		if ( is_object( $phpmailer ) && '' !== self::$text && property_exists( $phpmailer, 'AltBody' ) ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
			$phpmailer->AltBody = self::$text;
		}
	}

	/**
	 * Sender name "Karetaker · Site name", unless another plugin set a custom name.
	 *
	 * @since 1.0.0
	 * @param string $name Current sender name.
	 * @return string
	 */
	public static function from_name( $name ) {
		if ( '' !== (string) $name && 'WordPress' !== (string) $name ) {
			return $name;
		}
		$site = trim( (string) get_bloginfo( 'name' ) );
		return '' !== $site ? 'Karetaker · ' . $site : 'Karetaker';
	}

	/**
	 * Renders the HTML and plain-text versions.
	 *
	 * Arguments: level (act, review or safe), eyebrow (label above the title, defaults to the
	 * level label), title, lead (also the inbox preview), meta (label => value facts), stats
	 * (list of [value, label]), steps (what to do, in order), events (list of [level, title,
	 * time, summary]), buttons (list of [label, url], the first is the main action), details
	 * (list of [label, value]) and reason (why the recipient gets this email).
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $args Template arguments.
	 * @return array{html: string, text: string}
	 */
	public static function render( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'level'   => 'safe',
				'eyebrow' => '',
				'title'   => '',
				'lead'    => '',
				'meta'    => array(),
				'stats'   => array(),
				'steps'   => array(),
				'events'  => array(),
				'buttons' => array(),
				'details' => array(),
				'reason'  => '',
			)
		);
		return array(
			'html' => self::html( $args ),
			'text' => self::text( $args ),
		);
	}

	/**
	 * The watchtower mark drawn with HTML so it shows without loading images.
	 *
	 * @since 1.0.0
	 * @param string $lamp Lamp colour.
	 * @param int    $size Box size in pixels.
	 * @return string
	 */
	private static function mark( $lamp, $size ) {
		$s    = $size / 48;
		$tier = static function ( $width, $top ) use ( $s ) {
			return '<div style="width:' . round( $width * $s ) . 'px;height:' . max( 2, round( 5 * $s ) ) . 'px;margin:' . round( $top * $s ) . 'px auto 0;background:#F2EFE8;font-size:0;line-height:0;">&nbsp;</div>';
		};
		return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="' . (int) $size . '" style="width:' . (int) $size . 'px;height:' . (int) $size . 'px;background:#141414;border-radius:' . round( 10 * $s ) . 'px;"><tr><td align="center" valign="middle" style="padding:' . round( 8 * $s ) . 'px 0;">'
			. '<div style="width:' . round( 9 * $s ) . 'px;height:' . round( 9 * $s ) . 'px;margin:0 auto;background:' . esc_attr( $lamp ) . ';border-radius:50%;font-size:0;line-height:0;">&nbsp;</div>'
			. $tier( 12, 4 ) . $tier( 20, 3 ) . $tier( 28, 3 )
			. '</td></tr></table>';
	}

	/**
	 * HTML version.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $a Arguments.
	 * @return string
	 */
	private static function html( array $a ) {
		$lv      = self::level( $a['level'] );
		$font    = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
		$mono    = 'ui-monospace,SFMono-Regular,Menlo,Consolas,monospace';
		$eyebrow = '' !== $a['eyebrow'] ? $a['eyebrow'] : $lv['label'];
		$label   = 'font-size:11px;line-height:16px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#8c8f94;';
		$h       = '';

		$h .= '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">';
		$h .= '<title>' . esc_html( $a['title'] ) . '</title></head>';
		$h .= '<body style="margin:0;padding:0;background:#f0f0f1;font-family:' . $font . ';color:#1d2327;-webkit-text-size-adjust:100%;">';

		// Inbox preview text, hidden in the message itself.
		$h .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f0f0f1;font-size:1px;line-height:1px;">' . esc_html( $a['lead'] ) . str_repeat( '&#8204;&nbsp;', 40 ) . '</div>';

		$h .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f0f0f1;"><tr><td align="center" style="padding:28px 12px 36px;">';
		$h .= '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;">';

		// Brand row.
		$h .= '<tr><td style="padding:0 4px 14px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>';
		$h .= '<td width="30" valign="middle" style="width:30px;">' . self::mark( '#f0c36d', 28 ) . '</td>';
		$h .= '<td valign="middle" style="padding-left:10px;font-size:15px;line-height:20px;font-weight:600;color:#1d2327;">Karetaker</td>';
		$h .= '<td align="right" valign="middle" style="font-size:13px;line-height:20px;color:#646970;"><a href="' . esc_url( home_url( '/' ) ) . '" style="color:#646970;text-decoration:none;">' . esc_html( self::host() ) . '</a></td>';
		$h .= '</tr></table></td></tr>';

		// Card.
		$h .= '<tr><td style="background:#ffffff;border:1px solid #dcdcde;border-radius:12px;">';

		// Hero: the watchtower band from the Home screen.
		$h .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#141414;border-radius:11px 11px 0 0;"><tr>';
		$h .= '<td width="72" valign="top" style="width:72px;padding:28px 0 26px 28px;">' . self::mark( $lv['lamp'], 48 ) . '</td>';
		$h .= '<td valign="top" style="padding:26px 28px 26px 18px;">';
		$h .= '<table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td style="background:' . esc_attr( $lv['soft'] ) . ';border-radius:999px;padding:3px 10px 3px 9px;font-size:12px;line-height:18px;font-weight:600;color:' . esc_attr( $lv['ink'] ) . ';"><span style="color:' . esc_attr( $lv['lamp'] ) . ';">&#9679;</span>&nbsp; ' . esc_html( $eyebrow ) . '</td></tr></table>';
		$h .= '<h1 style="margin:12px 0 0;font-size:24px;line-height:30px;font-weight:700;letter-spacing:-.01em;color:#ffffff;">' . esc_html( $a['title'] ) . '</h1>';
		if ( '' !== $a['lead'] ) {
			$h .= '<p style="margin:8px 0 0;font-size:15px;line-height:23px;color:#c9c5bc;">' . esc_html( $a['lead'] ) . '</p>';
		}
		$h .= '</td></tr></table>';

		$h .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td style="padding:24px 28px 28px;">';

		// Facts: site, when, reference.
		if ( $a['meta'] ) {
			$h .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #f0f0f1;border-radius:8px;background:#f6f7f7;"><tr>';
			foreach ( $a['meta'] as $key => $value ) {
				$h .= '<td valign="top" style="padding:12px 14px;"><div style="' . $label . '">' . esc_html( (string) $key ) . '</div><div style="margin-top:2px;font-size:14px;line-height:20px;color:#1d2327;word-break:break-word;">' . esc_html( (string) $value ) . '</div></td>';
			}
			$h .= '</tr></table>';
		}

		// Figures.
		if ( $a['stats'] ) {
			$h .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:' . ( $a['meta'] ? '16' : '0' ) . 'px;"><tr>';
			$i  = 0;
			foreach ( $a['stats'] as $stat ) {
				$h .= ( $i ? '<td width="10" style="width:10px;font-size:0;">&nbsp;</td>' : '' );
				$h .= '<td valign="top" style="border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;"><div style="font-size:24px;line-height:30px;font-weight:700;color:#1d2327;">' . esc_html( (string) $stat[0] ) . '</div><div style="font-size:12px;line-height:17px;color:#646970;">' . esc_html( (string) $stat[1] ) . '</div></td>';
				++$i;
			}
			$h .= '</tr></table>';
		}

		// What to do.
		if ( $a['steps'] ) {
			$h .= '<div style="margin:26px 0 12px;' . $label . '">' . esc_html__( 'What to do', 'karetaker' ) . '</div>';
			$h .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">';
			$n  = 1;
			foreach ( $a['steps'] as $step ) {
				$h .= '<tr><td width="34" valign="top" style="width:34px;padding:0 0 12px;"><div style="width:24px;height:24px;border-radius:50%;background:#f7efe3;color:#945716;font-size:12px;line-height:24px;font-weight:700;text-align:center;">' . (int) $n . '</div></td>';
				$h .= '<td valign="top" style="padding:2px 0 12px;font-size:15px;line-height:22px;color:#1d2327;">' . esc_html( (string) $step ) . '</td></tr>';
				++$n;
			}
			$h .= '</table>';
		}

		// Event list (summaries).
		if ( $a['events'] ) {
			$h .= '<div style="margin:26px 0 8px;' . $label . '">' . esc_html__( 'What happened', 'karetaker' ) . '</div>';
			$h .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">';
			foreach ( $a['events'] as $event ) {
				$dot = 'act' === $event[0] ? '#d63638' : ( 'review' === $event[0] ? '#dba617' : '#8c8f94' );
				$h  .= '<tr><td width="20" valign="top" style="width:20px;padding:14px 0 12px;border-top:1px solid #f0f0f1;"><div style="width:9px;height:9px;margin-top:6px;border-radius:50%;background:' . esc_attr( $dot ) . ';font-size:0;line-height:0;">&nbsp;</div></td>';
				$h  .= '<td valign="top" style="padding:12px 0;border-top:1px solid #f0f0f1;"><div style="font-size:15px;line-height:22px;font-weight:600;color:#1d2327;">' . esc_html( (string) $event[1] ) . '</div>';
				if ( ! empty( $event[3] ) ) {
					$h .= '<div style="margin-top:2px;font-size:14px;line-height:21px;color:#646970;">' . esc_html( (string) $event[3] ) . '</div>';
				}
				$h .= '</td><td align="right" valign="top" style="padding:14px 0 12px 12px;border-top:1px solid #f0f0f1;font-size:12px;line-height:20px;color:#8c8f94;white-space:nowrap;">' . esc_html( (string) $event[2] ) . '</td></tr>';
			}
			$h .= '</table>';
		}

		// Buttons.
		if ( $a['buttons'] ) {
			$h .= '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-top:22px;"><tr>';
			foreach ( array_values( $a['buttons'] ) as $i => $button ) {
				if ( $i ) {
					$h .= '<td width="10" style="width:10px;font-size:0;">&nbsp;</td>';
				}
				$primary = 0 === $i;
				$h      .= '<td style="border-radius:6px;background:' . ( $primary ? '#b36a1e' : '#ffffff' ) . ';border:1px solid ' . ( $primary ? '#b36a1e' : '#dcdcde' ) . ';"><a href="' . esc_url( (string) $button[1] ) . '" style="display:inline-block;padding:11px 18px;font-size:14px;line-height:18px;font-weight:600;color:' . ( $primary ? '#ffffff' : '#1d2327' ) . ';text-decoration:none;border-radius:6px;">' . esc_html( (string) $button[0] ) . '</a></td>';
			}
			$h .= '</tr></table>';
		}

		// Technical details.
		if ( $a['details'] ) {
			$h .= '<div style="margin:28px 0 8px;' . $label . '">' . esc_html__( 'Technical details', 'karetaker' ) . '</div>';
			$h .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #f0f0f1;border-radius:8px;">';
			foreach ( array_values( $a['details'] ) as $i => $row ) {
				$border = $i ? 'border-top:1px solid #f0f0f1;' : '';
				$h     .= '<tr><td width="130" valign="top" style="width:130px;padding:9px 12px;' . $border . 'font-size:13px;line-height:19px;color:#646970;">' . esc_html( (string) $row[0] ) . '</td>';
				$h     .= '<td valign="top" style="padding:9px 12px;' . $border . 'font-family:' . $mono . ';font-size:12.5px;line-height:19px;color:#1d2327;word-break:break-word;">' . esc_html( (string) $row[1] ) . '</td></tr>';
			}
			$h .= '</table>';
		}

		$h .= '</td></tr></table>';
		$h .= '</td></tr>';

		// Footer.
		$settings = admin_url( 'admin.php?page=karetaker&tab=settings' );
		$h       .= '<tr><td style="padding:18px 8px 0;font-size:12px;line-height:18px;color:#8c8f94;">';
		if ( '' !== $a['reason'] ) {
			$h .= esc_html( $a['reason'] ) . ' ';
		}
		$h .= '<a href="' . esc_url( $settings ) . '" style="color:#646970;text-decoration:underline;">' . esc_html__( 'Change alert settings', 'karetaker' ) . '</a>';
		$h .= '<div style="margin-top:10px;color:#a7aaad;">' . esc_html__( 'Karetaker · A watchtower, not a wall.', 'karetaker' ) . '</div>';
		$h .= '</td></tr>';

		$h .= '</table></td></tr></table></body></html>';

		return $h;
	}

	/**
	 * Plain-text version with the same structure.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $a Arguments.
	 * @return string
	 */
	private static function text( array $a ) {
		$lv      = self::level( $a['level'] );
		$eyebrow = '' !== $a['eyebrow'] ? $a['eyebrow'] : $lv['label'];
		$rule    = str_repeat( '-', 48 );
		$lines   = array( strtoupper( $eyebrow ), $a['title'] );

		if ( '' !== $a['lead'] ) {
			$lines[] = '';
			$lines[] = $a['lead'];
		}
		if ( $a['meta'] ) {
			$lines[] = '';
			foreach ( $a['meta'] as $key => $value ) {
				$lines[] = $key . ': ' . $value;
			}
		}
		if ( $a['stats'] ) {
			$lines[] = '';
			foreach ( $a['stats'] as $stat ) {
				$lines[] = $stat[1] . ': ' . $stat[0];
			}
		}
		if ( $a['steps'] ) {
			$lines[] = '';
			$lines[] = __( 'What to do', 'karetaker' );
			$lines[] = $rule;
			$n       = 1;
			foreach ( $a['steps'] as $step ) {
				$lines[] = $n . '. ' . $step;
				++$n;
			}
		}
		if ( $a['events'] ) {
			$lines[] = '';
			$lines[] = __( 'What happened', 'karetaker' );
			$lines[] = $rule;
			foreach ( $a['events'] as $event ) {
				$lines[] = '- ' . $event[1] . ' (' . $event[2] . ')';
				if ( ! empty( $event[3] ) ) {
					$lines[] = '  ' . $event[3];
				}
			}
		}
		if ( $a['buttons'] ) {
			$lines[] = '';
			foreach ( $a['buttons'] as $button ) {
				$lines[] = $button[0] . ': ' . $button[1];
			}
		}
		if ( $a['details'] ) {
			$lines[] = '';
			$lines[] = __( 'Technical details', 'karetaker' );
			$lines[] = $rule;
			foreach ( $a['details'] as $row ) {
				$lines[] = $row[0] . ': ' . $row[1];
			}
		}
		$lines[] = '';
		$lines[] = $rule;
		if ( '' !== $a['reason'] ) {
			$lines[] = $a['reason'];
		}
		$lines[] = __( 'Change alert settings:', 'karetaker' ) . ' ' . admin_url( 'admin.php?page=karetaker&tab=settings' );
		$lines[] = __( 'Karetaker · A watchtower, not a wall.', 'karetaker' );

		return implode( "\n", $lines );
	}
}
