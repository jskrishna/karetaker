<?php
/**
 * Human guidance for Activity events (what happened, what to do).
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps event codes to owner-facing titles, next steps, and admin links.
 *
 * @since 0.1.0
 */
class Karetaker_Guidance {

	/**
	 * Guidance payload for a recorded event.
	 *
	 * @since 0.1.0
	 * @param string               $code Event code.
	 * @param array<string, mixed> $context Event context.
	 * @return array{title: string, summary: string, steps: string[], link: string, link_label: string}
	 */
	public static function for_event( $code, array $context = array() ) {
		$code  = sanitize_key( $code );
		$check = isset( $context['check'] ) ? sanitize_key( (string) $context['check'] ) : '';

		if ( 'guard_tripped' === $code ) {
			return self::for_guard( $check, $context );
		}

		$map = array(
			'admin_user_added'    => array(
				'title'      => __( 'A new administrator appeared', 'karetaker' ),
				'summary'    => __( 'Someone created an account with the administrator role. That is normal if you did it; otherwise treat it as a compromise signal.', 'karetaker' ),
				'steps'      => array(
					__( 'Open Users and find the new administrator.', 'karetaker' ),
					__( 'If you do not recognise them, delete or demote the account and change your own password.', 'karetaker' ),
					__( 'Review recent plugin/theme installs and the Activity log for related events.', 'karetaker' ),
				),
				'link'       => admin_url( 'users.php' ),
				'link_label' => __( 'Open Users', 'karetaker' ),
			),
			'role_escalated'      => array(
				'title'      => __( 'A user was made administrator', 'karetaker' ),
				'summary'    => __( 'An existing account gained the administrator role. Confirm this was intentional.', 'karetaker' ),
				'steps'      => array(
					__( 'Check which user changed role in the event context.', 'karetaker' ),
					__( 'If unexpected, demote them and rotate passwords for remaining admins.', 'karetaker' ),
				),
				'link'       => admin_url( 'users.php' ),
				'link_label' => __( 'Open Users', 'karetaker' ),
			),
			'registration_opened' => array(
				'title'      => __( 'Anyone can register', 'karetaker' ),
				'summary'    => __( 'Open registration (or a dangerous default role) was detected. That is how large spam and takeover campaigns often start.', 'karetaker' ),
				'steps'      => array(
					__( 'Go to Settings → General and turn membership off unless you need it.', 'karetaker' ),
					__( 'Confirm the New User Default Role is Subscriber (or similar), never Administrator.', 'karetaker' ),
					__( 'Optional: enable Harden → Close registration.', 'karetaker' ),
				),
				'link'       => admin_url( 'options-general.php' ),
				'link_label' => __( 'Open Settings → General', 'karetaker' ),
			),
			'muplugin_changed'    => array(
				'title'      => __( 'A must-use plugin changed', 'karetaker' ),
				'summary'    => __( 'Files under mu-plugins auto-load and never appear on the Plugins screen. Unexpected changes are a common backdoor pattern.', 'karetaker' ),
				'steps'      => array(
					__( 'Compare wp-content/mu-plugins with your last known-good copy or deploy.', 'karetaker' ),
					__( 'Remove unknown PHP files and rotate all administrator passwords.', 'karetaker' ),
				),
				'link'       => admin_url( 'plugins.php' ),
				'link_label' => __( 'Open Plugins', 'karetaker' ),
			),
			'uploads_php_found'   => array(
				'title'      => __( 'Executable PHP under uploads', 'karetaker' ),
				'summary'    => __( 'A PHP (or similar) file appeared where media should live. Web servers often execute these files directly, bypassing WordPress.', 'karetaker' ),
				'steps'      => array(
					__( 'Locate the path in the event context and delete the file via SFTP or the host file manager.', 'karetaker' ),
					__( 'Check how it was uploaded (forms, vulnerable plugins) and update or remove that path.', 'karetaker' ),
				),
				'link'       => admin_url( 'upload.php' ),
				'link_label' => __( 'Open Media', 'karetaker' ),
			),
			'file_hash_mismatch'  => array(
				'title'      => __( 'Plugin or theme files do not match wordpress.org', 'karetaker' ),
				'summary'    => __( 'File contents no longer match the official package. That can be a legitimate fork, or a modified plugin used as a backdoor.', 'karetaker' ),
				'steps'      => array(
					__( 'Note the subject in the event context (core / plugin slug).', 'karetaker' ),
					__( 'Reinstall from wordpress.org or your trusted source if you did not edit those files.', 'karetaker' ),
				),
				'link'       => admin_url( 'update-core.php' ),
				'link_label' => __( 'Open Updates', 'karetaker' ),
			),
		);

		if ( isset( $map[ $code ] ) ) {
			return self::normalize( $map[ $code ] );
		}

		$watch = array(
			'orphan_cron_found'   => array(
				'title'      => __( 'Orphan cron job detected', 'karetaker' ),
				'summary'    => __( 'A scheduled hook has no matching callback. Often leftover from a removed plugin.', 'karetaker' ),
				'steps'      => array(
					__( 'Note the hook name in the event details.', 'karetaker' ),
					__( 'Remove it with WP-CLI or a cron manager if the plugin is gone.', 'karetaker' ),
				),
				'link'       => add_query_arg(
					array(
						'page' => 'karetaker',
						'tab'  => 'overview',
					),
					admin_url( 'admin.php' )
				),
				'link_label' => __( 'Open Overview', 'karetaker' ),
			),
			'option_changed'      => array(
				'title'      => __( 'A watched option changed', 'karetaker' ),
				'summary'    => __( 'A setting Karetaker tracks was updated. Confirm it was intentional.', 'karetaker' ),
				'steps'      => array(
					__( 'Compare old and new values in the event details.', 'karetaker' ),
					__( 'If unexpected, reverse the change and review who was logged in.', 'karetaker' ),
				),
				'link'       => '',
				'link_label' => '',
			),
			'file_editor_used'    => array(
				'title'      => __( 'Theme or plugin editor was used', 'karetaker' ),
				'summary'    => __( 'Someone edited code through the WordPress file editor. Prefer SFTP/Git for real changes.', 'karetaker' ),
				'steps'      => array(
					__( 'Confirm who made the edit in the event details.', 'karetaker' ),
					__( 'Optional: enable Harden → Disable file editor.', 'karetaker' ),
				),
				'link'       => add_query_arg(
					array(
						'page' => 'karetaker',
						'tab'  => 'harden',
					),
					admin_url( 'admin.php' )
				),
				'link_label' => __( 'Open Harden', 'karetaker' ),
			),
			'admin_email_changed' => array(
				'title'      => __( 'Admin email changed', 'karetaker' ),
				'summary'    => __( 'The site administration email address was updated.', 'karetaker' ),
				'steps'      => array(
					__( 'Confirm the new address in Settings → General.', 'karetaker' ),
					__( 'If unexpected, change it back and rotate admin passwords.', 'karetaker' ),
				),
				'link'       => admin_url( 'options-general.php' ),
				'link_label' => __( 'Open Settings → General', 'karetaker' ),
			),
			'login_failure_burst' => array(
				'title'      => __( 'Many failed logins', 'karetaker' ),
				'summary'    => __( 'Karetaker saw a burst of failed sign-ins. Often bots; sometimes a forgotten password.', 'karetaker' ),
				'steps'      => array(
					__( 'Check the IP and username in the event details.', 'karetaker' ),
					__( 'If it is your team, reset the password; if not, consider host-level IP blocks.', 'karetaker' ),
				),
				'link'       => '',
				'link_label' => '',
			),
		);

		if ( isset( $watch[ $code ] ) ) {
			return self::normalize( $watch[ $code ] );
		}

		$log = array(
			'scan_ran'           => array(
				'title'      => __( 'Integrity scan finished', 'karetaker' ),
				'summary'    => __( 'A scheduled or manual scan completed. This is a receipt, not an alert.', 'karetaker' ),
				'steps'      => array(
					__( 'Skim the results in the event details if you want the slice summary.', 'karetaker' ),
				),
				'link'       => add_query_arg(
					array(
						'page' => 'karetaker',
						'tab'  => 'overview',
					),
					admin_url( 'admin.php' )
				),
				'link_label' => __( 'Open Overview', 'karetaker' ),
			),
			'setting_changed'    => array(
				'title'      => __( 'Karetaker setting changed', 'karetaker' ),
				'summary'    => __( 'Someone updated Karetaker’s own settings. Logged for the audit trail.', 'karetaker' ),
				'steps'      => array(),
				'link'       => add_query_arg(
					array(
						'page' => 'karetaker',
						'tab'  => 'harden',
					),
					admin_url( 'admin.php' )
				),
				'link_label' => __( 'Open Harden & Settings', 'karetaker' ),
			),
			'plugin_activated'   => array(
				'title'      => __( 'Plugin activated', 'karetaker' ),
				'summary'    => __( 'A plugin was turned on. Normal during maintenance; review if unexpected.', 'karetaker' ),
				'steps'      => array(),
				'link'       => admin_url( 'plugins.php' ),
				'link_label' => __( 'Open Plugins', 'karetaker' ),
			),
			'plugin_deactivated' => array(
				'title'      => __( 'Plugin deactivated', 'karetaker' ),
				'summary'    => __( 'A plugin was turned off. Normal during maintenance; review if unexpected.', 'karetaker' ),
				'steps'      => array(),
				'link'       => admin_url( 'plugins.php' ),
				'link_label' => __( 'Open Plugins', 'karetaker' ),
			),
			'theme_switched'     => array(
				'title'      => __( 'Theme switched', 'karetaker' ),
				'summary'    => __( 'The active theme changed.', 'karetaker' ),
				'steps'      => array(),
				'link'       => admin_url( 'themes.php' ),
				'link_label' => __( 'Open Themes', 'karetaker' ),
			),
			'user_login'         => array(
				'title'      => __( 'Administrator signed in', 'karetaker' ),
				'summary'    => __( 'An administrator logged in successfully.', 'karetaker' ),
				'steps'      => array(),
				'link'       => '',
				'link_label' => '',
			),
			'user_created'       => array(
				'title'      => __( 'User account created', 'karetaker' ),
				'summary'    => __( 'A new user was added. Check the role if this was unexpected.', 'karetaker' ),
				'steps'      => array(),
				'link'       => admin_url( 'users.php' ),
				'link_label' => __( 'Open Users', 'karetaker' ),
			),
			'user_deleted'       => array(
				'title'      => __( 'User account deleted', 'karetaker' ),
				'summary'    => __( 'A user was removed from the site.', 'karetaker' ),
				'steps'      => array(),
				'link'       => admin_url( 'users.php' ),
				'link_label' => __( 'Open Users', 'karetaker' ),
			),
		);

		if ( isset( $log[ $code ] ) ) {
			return self::normalize( $log[ $code ] );
		}

		$severity = Karetaker_Events::severity_for( $code );

		if ( Karetaker_Events::SEVERITY_ACT === $severity ) {
			return self::normalize(
				array(
					'title'      => __( 'Something needs your attention', 'karetaker' ),
					'summary'    => __( 'This is an act-now event. Review the details below and confirm whether the change was intentional.', 'karetaker' ),
					'steps'      => array(
						__( 'Read the context JSON for who/what changed.', 'karetaker' ),
						__( 'If unexpected, treat it as a security incident and rotate admin credentials.', 'karetaker' ),
					),
					'link'       => '',
					'link_label' => '',
				)
			);
		}

		if ( Karetaker_Events::SEVERITY_ATTENTION === $severity ) {
			return self::normalize(
				array(
					'title'      => __( 'Worth a look', 'karetaker' ),
					'summary'    => __( 'This event is on the watch list, not an emergency, but confirm it was intentional.', 'karetaker' ),
					'steps'      => array(
						__( 'Read the event details.', 'karetaker' ),
					),
					'link'       => '',
					'link_label' => '',
				)
			);
		}

		return self::normalize(
			array(
				'title'      => __( 'Logged activity', 'karetaker' ),
				'summary'    => __( 'Recorded for the audit trail. No action required unless something looks off.', 'karetaker' ),
				'steps'      => array(),
				'link'       => '',
				'link_label' => '',
			)
		);
	}

	/**
	 * Guidance for Guard check keys.
	 *
	 * @since 0.1.0
	 * @param string               $check Guard check key.
	 * @param array<string, mixed> $context Event context.
	 * @return array{title: string, summary: string, steps: string[], link: string, link_label: string}
	 */
	private static function for_guard( $check, array $context ) {
		$map = array(
			'blog_public'         => array(
				'title'      => __( 'Search engines were discouraged', 'karetaker' ),
				'summary'    => __( 'Settings → Reading has “Discourage search engines from indexing this site” turned on. That can wipe months of ranking if left on a live site.', 'karetaker' ),
				'steps'      => array(
					__( 'Open Settings → Reading.', 'karetaker' ),
					__( 'Uncheck “Discourage search engines from indexing this site” if this site should be public.', 'karetaker' ),
					__( 'Request re-indexing in Search Console after you fix it.', 'karetaker' ),
				),
				'link'       => admin_url( 'options-reading.php' ),
				'link_label' => __( 'Open Settings → Reading', 'karetaker' ),
			),
			'mail_failed'         => array(
				'title'      => __( 'WordPress could not send email', 'karetaker' ),
				'summary'    => __( 'A real wp_mail send failed. Forms, password resets, and Karetaker alerts may all be broken until mail works again.', 'karetaker' ),
				'steps'      => array(
					__( 'Check the error in the event details (often SMTP authentication).', 'karetaker' ),
					__( 'Fix your SMTP / transactional mail plugin credentials, or ask the host to allow PHP mail.', 'karetaker' ),
					__( 'Send a test email (or submit a contact form) after fixing. Guard clears when mail starts succeeding again.', 'karetaker' ),
				),
				'link'       => admin_url( 'plugins.php' ),
				'link_label' => __( 'Open Plugins', 'karetaker' ),
			),
			'admin_email_invalid' => array(
				'title'      => __( 'Admin email address is invalid', 'karetaker' ),
				'summary'    => __( 'The site admin email is empty or not a valid address. Critical notices may go nowhere.', 'karetaker' ),
				'steps'      => array(
					__( 'Open Settings → General and set a working admin email you control.', 'karetaker' ),
					__( 'Confirm the confirmation email if WordPress asks to verify the change.', 'karetaker' ),
				),
				'link'       => admin_url( 'options-general.php' ),
				'link_label' => __( 'Open Settings → General', 'karetaker' ),
			),
			'no_administrator'    => array(
				'title'      => __( 'No administrator remains', 'karetaker' ),
				'summary'    => __( 'The site has no user with manage_options. You can lose the ability to recover through the dashboard.', 'karetaker' ),
				'steps'      => array(
					__( 'Restore an administrator via WP-CLI (`wp user update … --role=administrator`) or the host database tools.', 'karetaker' ),
					__( 'Then log in and audit Users for unexpected accounts.', 'karetaker' ),
				),
				'link'       => admin_url( 'users.php' ),
				'link_label' => __( 'Open Users', 'karetaker' ),
			),
		);

		if ( isset( $map[ $check ] ) ) {
			$item = $map[ $check ];
			if ( 'mail_failed' === $check && ! empty( $context['error'] ) && is_scalar( $context['error'] ) ) {
				$item['summary'] .= ' ' . sprintf(
					/* translators: %s: mail error message */
					__( 'Reported error: %s', 'karetaker' ),
					(string) $context['error']
				);
			}
			return self::normalize( $item );
		}

		return self::normalize(
			array(
				'title'      => __( 'A site setting needs attention', 'karetaker' ),
				'summary'    => __( 'A Guard check tripped. Review the check key in the details and reverse the change if it was unintentional.', 'karetaker' ),
				'steps'      => array(
					__( 'Identify the check in context.', 'karetaker' ),
					__( 'Fix the underlying setting, then run a scan from Overview.', 'karetaker' ),
				),
				'link'       => add_query_arg(
					array(
						'page' => 'karetaker',
						'tab'  => 'overview',
					),
					admin_url( 'admin.php' )
				),
				'link_label' => __( 'Open Overview', 'karetaker' ),
			)
		);
	}

	/**
	 * Ensures a complete guidance array.
	 *
	 * @since 0.1.0
	 * @param array<string, mixed> $item Partial guidance.
	 * @return array{title: string, summary: string, steps: string[], link: string, link_label: string}
	 */
	private static function normalize( array $item ) {
		$steps = isset( $item['steps'] ) && is_array( $item['steps'] ) ? $item['steps'] : array();
		$clean = array();
		foreach ( $steps as $step ) {
			$step = (string) $step;
			if ( '' !== $step ) {
				$clean[] = $step;
			}
		}

		return array(
			'title'      => isset( $item['title'] ) ? (string) $item['title'] : '',
			'summary'    => isset( $item['summary'] ) ? (string) $item['summary'] : '',
			'steps'      => $clean,
			'link'       => isset( $item['link'] ) ? (string) $item['link'] : '',
			'link_label' => isset( $item['link_label'] ) ? (string) $item['link_label'] : '',
		);
	}
}
