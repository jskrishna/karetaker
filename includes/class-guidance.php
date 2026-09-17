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
 * @since 1.0.0
 */
class Karetaker_Guidance {

	/**
	 * Guidance payload for a recorded event.
	 *
	 * @since 1.0.0
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
			'admin_user_added'           => array(
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
			'role_escalated'             => array(
				'title'      => __( 'A user was made administrator', 'karetaker' ),
				'summary'    => __( 'An existing account gained the administrator role. Confirm this was intentional.', 'karetaker' ),
				'steps'      => array(
					__( 'Check which user changed role in the event context.', 'karetaker' ),
					__( 'If unexpected, demote them and rotate passwords for remaining admins.', 'karetaker' ),
				),
				'link'       => admin_url( 'users.php' ),
				'link_label' => __( 'Open Users', 'karetaker' ),
			),
			'registration_opened'        => array(
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
			'muplugin_changed'           => array(
				'title'      => __( 'A must-use plugin changed', 'karetaker' ),
				'summary'    => __( 'Files under mu-plugins auto-load and never appear on the Plugins screen. Unexpected changes are a common backdoor pattern.', 'karetaker' ),
				'steps'      => array(
					__( 'Compare wp-content/mu-plugins with your last known-good copy or deploy.', 'karetaker' ),
					__( 'Remove unknown PHP files and rotate all administrator passwords.', 'karetaker' ),
				),
				'link'       => admin_url( 'plugins.php' ),
				'link_label' => __( 'Open Plugins', 'karetaker' ),
			),
			'uploads_php_found'          => array(
				'title'      => __( 'Executable PHP under uploads', 'karetaker' ),
				'summary'    => __( 'A PHP (or similar) file appeared where media should live. Web servers often execute these files directly, bypassing WordPress.', 'karetaker' ),
				'steps'      => array(
					__( 'Locate the path in the event context and delete the file via SFTP or the host file manager.', 'karetaker' ),
					__( 'Check how it was uploaded (forms, vulnerable plugins) and update or remove that path.', 'karetaker' ),
				),
				'link'       => admin_url( 'upload.php' ),
				'link_label' => __( 'Open Media', 'karetaker' ),
			),
			'file_hash_mismatch'         => array(
				'title'      => __( 'Plugin or theme files do not match wordpress.org', 'karetaker' ),
				'summary'    => __( 'File contents no longer match the official package. That can be a legitimate fork, or a modified plugin used as a backdoor.', 'karetaker' ),
				'steps'      => array(
					__( 'Note the subject in the event context (core / plugin slug).', 'karetaker' ),
					__( 'Reinstall from wordpress.org or your trusted source if you did not edit those files.', 'karetaker' ),
					__( 'If the host locks those files, Mark as expected after confirming they are host-managed.', 'karetaker' ),
				),
				'link'       => admin_url( 'update-core.php' ),
				'link_label' => __( 'Open Updates', 'karetaker' ),
			),
			'critical_file_changed'      => array(
				'title'      => __( 'A critical site file changed', 'karetaker' ),
				'summary'    => __( 'wp-config, root index/settings, or root .htaccess/.user.ini fingerprint changed. Content is never stored; only size, mtime, and hash.', 'karetaker' ),
				'steps'      => array(
					__( 'Compare the listed path with your last known-good deploy or backup.', 'karetaker' ),
					__( 'If you changed it on purpose, use Mark as expected.', 'karetaker' ),
				),
				'link'       => admin_url( 'admin.php?page=karetaker&tab=activity' ),
				'link_label' => __( 'Open Activity', 'karetaker' ),
			),
			'uploads_config_changed'     => array(
				'title'      => __( 'Uploads .htaccess or .user.ini changed', 'karetaker' ),
				'summary'    => __( 'A config file under uploads appeared or changed. Attackers use these to enable PHP execution in media directories.', 'karetaker' ),
				'steps'      => array(
					__( 'Inspect the path in the event context via SFTP or the host file manager.', 'karetaker' ),
					__( 'Remove unknown rules, or Mark as expected if your host requires them.', 'karetaker' ),
				),
				'link'       => admin_url( 'upload.php' ),
				'link_label' => __( 'Open Media', 'karetaker' ),
			),
			'admin_roster_changed'       => array(
				'title'      => __( 'Administrator roster changed in the database', 'karetaker' ),
				'summary'    => __( 'Direct SQL comparison of administrator capabilities found a new or removed admin login.', 'karetaker' ),
				'steps'      => array(
					__( 'Open Users and verify every administrator.', 'karetaker' ),
					__( 'If unexpected, demote or delete the account and rotate passwords.', 'karetaker' ),
				),
				'link'       => admin_url( 'users.php' ),
				'link_label' => __( 'Open Users', 'karetaker' ),
			),
			'hidden_admin_found'         => array(
				'title'      => __( 'A hidden administrator was found', 'karetaker' ),
				'summary'    => __( 'The database lists an administrator that WordPress user queries do not show. That matches known backdoor hiding patterns.', 'karetaker' ),
				'steps'      => array(
					__( 'Inspect usermeta capabilities for the listed login via phpMyAdmin or WP-CLI.', 'karetaker' ),
					__( 'Remove the hidden admin and rotate all remaining admin passwords.', 'karetaker' ),
				),
				'link'       => admin_url( 'users.php' ),
				'link_label' => __( 'Open Users', 'karetaker' ),
			),
			'hidden_plugin_found'        => array(
				'title'      => __( 'A hidden plugin folder was found', 'karetaker' ),
				'summary'    => __( 'A directory exists under wp-content/plugins that get_plugins() does not list. Fake plugins often hide from the Plugins screen.', 'karetaker' ),
				'steps'      => array(
					__( 'Inspect the listed folder on disk and remove it if you do not recognise it.', 'karetaker' ),
					__( 'If it is intentional tooling, Mark as expected.', 'karetaker' ),
				),
				'link'       => admin_url( 'plugins.php' ),
				'link_label' => __( 'Open Plugins', 'karetaker' ),
			),
			'silent_plugin_found'        => array(
				'title'      => __( 'A plugin folder appeared without an install event', 'karetaker' ),
				'summary'    => __( 'A new plugins directory showed up since the last scan without a normal WordPress install/activate trail. That is common for FTP-dropped backdoors.', 'karetaker' ),
				'steps'      => array(
					__( 'Check who has SFTP/host access and whether a deploy added the folder.', 'karetaker' ),
					__( 'Remove unknown code, or Mark as expected after you verify it.', 'karetaker' ),
				),
				'link'       => admin_url( 'plugins.php' ),
				'link_label' => __( 'Open Plugins', 'karetaker' ),
			),
			'db_script_found'            => array(
				'title'      => __( 'Script-like code found in the database', 'karetaker' ),
				'summary'    => __( 'An option used for widgets, snippets, theme mods, checkout customizations, or autoload storage matches patterns often used to hide PHP (eval, base64, PHP tags). This is a signal to inspect, not a malware verdict.', 'karetaker' ),
				'steps'      => array(
					__( 'Open the listed option name via a database tool or a snippet/widget screen.', 'karetaker' ),
					__( 'Remove unknown PHP if you did not put it there; rotate admin passwords if it looks hostile.', 'karetaker' ),
					__( 'If it is intentional (rare), use Mark as expected.', 'karetaker' ),
				),
				'link'       => admin_url( 'widgets.php' ),
				'link_label' => __( 'Open Widgets', 'karetaker' ),
			),
			'option_denylist_hit'        => array(
				'title'      => __( 'Known suspicious option name found', 'karetaker' ),
				'summary'    => __( 'An option name matches a small curated list associated with database-layer malware campaigns. This is a strong signal to inspect that row, not an automatic cleanup.', 'karetaker' ),
				'steps'      => array(
					__( 'Open the named option in a database tool and inspect who created it.', 'karetaker' ),
					__( 'If you did not put it there, remove it only after you understand the site state, and rotate administrator passwords.', 'karetaker' ),
					__( 'If it is intentional and safe, use Mark as expected.', 'karetaker' ),
				),
				'link'       => admin_url( 'options.php' ),
				'link_label' => __( 'Open All Settings', 'karetaker' ),
			),
			'option_name_suspect'        => array(
				'title'      => __( 'Unusual new autoload option name', 'karetaker' ),
				'summary'    => __( 'A new autoloaded option name looks odd (for example leading underscore or no active plugin prefix). This is worth a look, not a malware verdict.', 'karetaker' ),
				'steps'      => array(
					__( 'Confirm whether an active plugin or custom code owns that option name.', 'karetaker' ),
					__( 'If unknown, inspect the row and recent file changes together.', 'karetaker' ),
					__( 'If intentional, use Mark as expected.', 'karetaker' ),
				),
				'link'       => admin_url( 'admin.php?page=karetaker&tab=activity' ),
				'link_label' => __( 'Open Activity', 'karetaker' ),
			),
			'script_domain_new'          => array(
				'title'      => __( 'New external script domain', 'karetaker' ),
				'summary'    => __( 'A JavaScript host appeared that was not in the local baseline (home, cart/checkout when WooCommerce is active, or script tags in widgets / theme mods / snippet-style options). This catches many checkout skimmers, but it is not a malware verdict.', 'karetaker' ),
				'steps'      => array(
					__( 'Confirm whether a plugin, theme, or tag manager you trust added the domain.', 'karetaker' ),
					__( 'If you do not recognize it, remove the script source and rotate passwords if checkout was involved.', 'karetaker' ),
					__( 'If intentional, use Mark as expected.', 'karetaker' ),
				),
				'link'       => admin_url( 'admin.php?page=karetaker&tab=activity' ),
				'link_label' => __( 'Open Activity', 'karetaker' ),
			),
			'cloaking_suspected'         => array(
				'title'      => __( 'Google sees different links than visitors', 'karetaker' ),
				'summary'    => __( 'Your home page showed links to outside sites when fetched as Googlebot that a normal visitor does not see. Hidden spam links for search engines are a common sign of a hacked site.', 'karetaker' ),
				'steps'      => array(
					__( 'Look at the listed domains. If they are casinos, pharmacies or other spam, treat the site as compromised and start the hacked-site checklist.', 'karetaker' ),
					__( 'Check your theme header and footer files, mu-plugins and recently changed files for code that checks the user agent.', 'karetaker' ),
					__( 'If a plugin you trust shows different links to search engines on purpose, use Mark as expected.', 'karetaker' ),
				),
				'link'       => admin_url( 'admin.php?page=karetaker&tab=watch&view=scripts' ),
				'link_label' => __( 'Open What we watch', 'karetaker' ),
			),
			'plugin_owner_changed'       => array(
				'title'      => __( 'A plugin you use has a new owner', 'karetaker' ),
				'summary'    => __( 'The author listed on WordPress.org changed for an active plugin. Most handovers are fine, but sold plugins have shipped backdoors in later updates.', 'karetaker' ),
				'steps'      => array(
					__( 'Read the next few updates of this plugin before installing them.', 'karetaker' ),
					__( 'If the plugin is small and you do not recognise the new owner, consider a replacement.', 'karetaker' ),
					__( 'If you already know about the handover, use Mark as expected.', 'karetaker' ),
				),
				'link'       => admin_url( 'admin.php?page=karetaker&tab=watch&view=plugins' ),
				'link_label' => __( 'Open plugin risk', 'karetaker' ),
			),
			'plugin_update_flagged'      => array(
				'title'      => __( 'A plugin update added new outside connections', 'karetaker' ),
				'summary'    => __( 'After this update the plugin contains new outside domains or risky functions such as base64_decode or eval. Many updates do this for good reasons; it is worth a quick look.', 'karetaker' ),
				'steps'      => array(
					__( 'Open the update receipt to see which lines appeared.', 'karetaker' ),
					__( 'If you don’t trust the change, roll back to the previous version or remove the plugin.', 'karetaker' ),
					__( 'If it is expected, use Mark as expected.', 'karetaker' ),
				),
				'link'       => admin_url( 'admin.php?page=karetaker&tab=monitoring&view=plugins' ),
				'link_label' => __( 'Open plugin risk', 'karetaker' ),
			),
			'plugin_directory_closed'    => array(
				'title'      => __( 'An active plugin was closed on WordPress.org', 'karetaker' ),
				'summary'    => __( 'The directory lists this slug as closed (security, guidelines, or author request). Running closed plugins is a common persistence risk.', 'karetaker' ),
				'steps'      => array(
					__( 'Open Plugins and identify the closed slug from the event context.', 'karetaker' ),
					__( 'Replace it with a maintained alternative, or remove it if unused.', 'karetaker' ),
					__( 'If you already accepted the risk, Mark as expected.', 'karetaker' ),
				),
				'link'       => admin_url( 'plugins.php' ),
				'link_label' => __( 'Open Plugins', 'karetaker' ),
			),
			'plugin_directory_abandoned' => array(
				'title'      => __( 'An active plugin looks abandoned on WordPress.org', 'karetaker' ),
				'summary'    => __( 'The plugin is still listed but has not been updated for a long time (about two years or more). That raises the chance of unpatched issues.', 'karetaker' ),
				'steps'      => array(
					__( 'Check whether a newer maintained fork or alternative exists.', 'karetaker' ),
					__( 'Plan a replacement if the vendor is gone; Mark as expected only if you still accept the risk.', 'karetaker' ),
				),
				'link'       => admin_url( 'plugins.php' ),
				'link_label' => __( 'Open Plugins', 'karetaker' ),
			),
			'plugin_vuln_found'          => array(
				'title'      => __( 'A known vulnerability may affect an active plugin', 'karetaker' ),
				'summary'    => __( 'Optional lookup matched your installed version against public vulnerability data (WPVulnerability). Update or replace the plugin when a fix exists.', 'karetaker' ),
				'steps'      => array(
					__( 'Open Plugins and update that plugin if an update is available.', 'karetaker' ),
					__( 'If no update exists yet, reduce exposure (disable if unused) and watch for a patched release.', 'karetaker' ),
					__( 'Mark as expected only if you already accept running this version until a fix ships.', 'karetaker' ),
				),
				'link'       => admin_url( 'plugins.php' ),
				'link_label' => __( 'Open Plugins', 'karetaker' ),
			),
			'user_enum_probe'            => array(
				'title'      => __( 'Someone probed user enumeration URLs', 'karetaker' ),
				'summary'    => __( 'A guest hit ?author=ID or author_name on the front end. That is a common username discovery technique before password guessing.', 'karetaker' ),
				'steps'      => array(
					__( 'Turn on Harden → Stop user enumeration if it is off.', 'karetaker' ),
					__( 'Review failed-login bursts around the same time.', 'karetaker' ),
					__( 'Mark as expected only if you recognise the traffic (monitors, uptime bots).', 'karetaker' ),
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
			'admin_login_new_ip'         => array(
				'title'      => __( 'Administrator signed in from a new IP', 'karetaker' ),
				'summary'    => __( 'An admin account authenticated from an address not seen on recent logins for that user. That is normal on travel or a new office; otherwise treat it as stolen credentials.', 'karetaker' ),
				'steps'      => array(
					__( 'Confirm with the admin whether they logged in from a new place or VPN.', 'karetaker' ),
					__( 'If unexpected, reset that password, review users, and check Harden toggles.', 'karetaker' ),
					__( 'Mark as expected after you accept this IP for that account.', 'karetaker' ),
				),
				'link'       => admin_url( 'users.php' ),
				'link_label' => __( 'Open Users', 'karetaker' ),
			),
			'admin_login_new_device'     => array(
				'title'      => __( 'Administrator signed in from a new device', 'karetaker' ),
				'summary'    => __( 'The browser fingerprint (User-Agent) for an admin login was new for that account. VPNs can change IP without changing device; a new device with a new IP is more suspicious.', 'karetaker' ),
				'steps'      => array(
					__( 'Ask the admin if they used a new browser, phone, or computer.', 'karetaker' ),
					__( 'If unexpected, reset the password and review active sessions where the host allows it.', 'karetaker' ),
					__( 'Mark as expected once the device is trusted.', 'karetaker' ),
				),
				'link'       => admin_url( 'users.php' ),
				'link_label' => __( 'Open Users', 'karetaker' ),
			),
			'event_marked_expected'      => array(
				'title'      => __( 'Change marked as expected', 'karetaker' ),
				'summary'    => __( 'An administrator updated the scan baseline so this change stops re-alerting.', 'karetaker' ),
				'steps'      => array(),
				'link'       => '',
				'link_label' => '',
			),
			'issue_updated'              => array(
				'title'      => __( 'Issue updated', 'karetaker' ),
				'summary'    => __( 'Someone acknowledged, resolved, reopened or assigned an issue.', 'karetaker' ),
				'steps'      => array(),
				'link'       => '',
				'link_label' => '',
			),
			'case_updated'               => array(
				'title'      => __( 'Incident updated', 'karetaker' ),
				'summary'    => __( 'An incident case was opened, changed or closed.', 'karetaker' ),
				'steps'      => array(),
				'link'       => '',
				'link_label' => '',
			),
			'token_updated'              => array(
				'title'      => __( 'API token changed', 'karetaker' ),
				'summary'    => __( 'An API token was created, rotated or revoked.', 'karetaker' ),
				'steps'      => array(),
				'link'       => '',
				'link_label' => '',
			),
			'onboarding_finished'        => array(
				'title'      => __( 'Setup finished', 'karetaker' ),
				'summary'    => __( 'The one-minute setup was completed.', 'karetaker' ),
				'steps'      => array(),
				'link'       => '',
				'link_label' => '',
			),
			'plugin_updated'             => array(
				'title'      => __( 'Plugin updated', 'karetaker' ),
				'summary'    => __( 'A plugin was updated. Karetaker compares its files with WordPress.org on the next checks.', 'karetaker' ),
				'steps'      => array(),
				'link'       => admin_url( 'admin.php?page=karetaker&tab=watch&view=plugins' ),
				'link_label' => __( 'Open plugin risk', 'karetaker' ),
			),
			'event_unmarked_expected'    => array(
				'title'      => __( 'Expected mark removed', 'karetaker' ),
				'summary'    => __( 'An administrator removed an item from the ignore list, so it can alert again.', 'karetaker' ),
				'steps'      => array(),
				'link'       => '',
				'link_label' => '',
			),
			'alerts_summary_sent'        => array(
				'title'      => __( 'Summary email sent', 'karetaker' ),
				'summary'    => __( 'Karetaker sent a weekly or end-of-pause summary email.', 'karetaker' ),
				'steps'      => array(),
				'link'       => '',
				'link_label' => '',
			),
			'incident_check_ran'         => array(
				'title'      => __( 'Incident check finished', 'karetaker' ),
				'summary'    => __( 'An administrator ran the “I think we were hacked” pass: deeper scans plus a checklist of facts to review.', 'karetaker' ),
				'steps'      => array(
					__( 'Open Overview and work through any flagged checklist rows.', 'karetaker' ),
					__( 'Review Act-now Activity for the last week.', 'karetaker' ),
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
				'summary'    => __( 'Someone updated Karetaker\'s own settings. Logged for the audit trail.', 'karetaker' ),
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
	 * @since 1.0.0
	 * @param string               $check Guard check key.
	 * @param array<string, mixed> $context Event context.
	 * @return array{title: string, summary: string, steps: string[], link: string, link_label: string}
	 */
	private static function for_guard( $check, array $context ) {
		$map = array(
			'blog_public'         => array(
				'title'      => __( 'Search engines were discouraged', 'karetaker' ),
				'summary'    => __( 'Settings → Reading has "Discourage search engines from indexing this site" turned on. That can wipe months of ranking if left on a live site.', 'karetaker' ),
				'steps'      => array(
					__( 'Open Settings → Reading.', 'karetaker' ),
					__( 'Uncheck "Discourage search engines from indexing this site" if this site should be public.', 'karetaker' ),
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
	 * @since 1.0.0
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
