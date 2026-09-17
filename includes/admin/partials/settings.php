<?php
/**
 * Settings: where alerts go, site type, visibility, Advanced mode, credits.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karetaker_routes  = Karetaker_Routing::routes();
$karetaker_paused  = Karetaker_Settings::alerts_paused_until();
$karetaker_scan    = Karetaker_Scanner::state();
$karetaker_mail    = ! empty( $karetaker_scan['guard']['mail_failed']['bad'] );
$karetaker_type    = (string) Karetaker_Settings::get( 'site_type' );
$karetaker_chips   = array(
	'telegram' => __( 'Telegram', 'karetaker' ),
	'slack'    => __( 'Slack', 'karetaker' ),
	'discord'  => __( 'Discord', 'karetaker' ),
	'teams'    => __( 'Teams', 'karetaker' ),
);
$karetaker_types   = array(
	''         => __( 'Not set', 'karetaker' ),
	'blog'     => __( 'Blog', 'karetaker' ),
	'business' => __( 'Business', 'karetaker' ),
	'store'    => __( 'Online store', 'karetaker' ),
	'client'   => __( 'Client site', 'karetaker' ),
);
$karetaker_credits = array(
	array( 'Team Krikir', 'https://www.krikir.com/', __( 'Made Karetaker', 'karetaker' ) ),
	array( 'WordPress', 'https://wordpress.org/', __( 'Checksums, plugin directory and security keys APIs', 'karetaker' ) ),
	array( 'Simple Icons', 'https://simpleicons.org/', __( 'Destination logos (CC0 1.0)', 'karetaker' ) ),
	array( 'WPVulnerability', 'https://www.wpvulnerability.com/', __( 'Vulnerability data (optional)', 'karetaker' ) ),
	array( 'Slack', 'https://slack.com/', __( 'Slack incoming webhooks (optional)', 'karetaker' ) ),
	array( 'Telegram', 'https://telegram.org/', __( 'Telegram Bot API (optional)', 'karetaker' ) ),
	array( 'Discord', 'https://discord.com/', __( 'Discord webhooks (optional)', 'karetaker' ) ),
	array( 'Microsoft Teams', 'https://www.microsoft.com/microsoft-teams/', __( 'Microsoft Teams workflows (optional)', 'karetaker' ) ),
);
?>
<section data-page="settings">
	<div class="stack">
		<div class="card rows">
			<div style="grid-template-columns:1fr">
				<div class="field">
					<label for="setEmail"><?php echo esc_html__( 'Send alerts to', 'karetaker' ); ?></label>
					<input type="email" id="setEmail" data-kt-setting="alert_email" value="<?php echo esc_attr( Karetaker_Settings::alert_email() ); ?>">
					<?php if ( $karetaker_mail ) : ?>
						<span class="hint warn-text">
							<?php echo esc_html__( 'WordPress could not send email recently, so alerts may not arrive.', 'karetaker' ); ?>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=karetaker_dismiss_mail_failed' ), 'karetaker_dismiss_mail_failed' ) ); ?>"><?php echo esc_html__( 'Dismiss', 'karetaker' ); ?></a>
						</span>
					<?php endif; ?>
				</div>
				<div class="chips" style="margin-top:12px">
					<?php
					foreach ( $karetaker_chips as $karetaker_ch => $karetaker_label ) :
						$karetaker_on = Karetaker_Routing::connected( $karetaker_ch );
						?>
						<button type="button" class="chipbtn<?php echo $karetaker_on ? ' on' : ''; ?>" data-connect="<?php echo esc_attr( $karetaker_ch ); ?>"><?php self::the_brand_icon( $karetaker_ch ); ?><?php echo esc_html( $karetaker_label ); ?><?php if ( $karetaker_on ) : ?><span class="chip-ok" aria-label="<?php echo esc_attr__( 'Connected', 'karetaker' ); ?>">✓</span><?php endif; ?></button>
					<?php endforeach; ?>
				</div>
			</div>
			<div>
				<div>
					<h3><?php echo esc_html__( 'Weekly summary', 'karetaker' ); ?></h3>
					<p><?php echo 'fri17' === Karetaker_Settings::get( 'weekly_slot' ) ? esc_html__( 'A short email every Friday, even when everything is fine.', 'karetaker' ) : esc_html__( 'A short email every Monday, even when everything is fine.', 'karetaker' ); ?></p>
				</div>
				<label class="switch"><input type="checkbox" data-kt-setting="weekly_summary"<?php checked( $karetaker_routes['email']['weekly'] ); ?> aria-label="<?php echo esc_attr__( 'Weekly summary', 'karetaker' ); ?>"><span></span></label>
			</div>
			<div>
				<div>
					<h3><?php echo esc_html__( 'I\'m working on the site', 'karetaker' ); ?></h3>
					<p>
						<?php
						if ( $karetaker_paused ) {
							/* translators: %s: time alerts resume */
							printf( esc_html__( 'Alerts are paused until %s. Everything is still recorded.', 'karetaker' ), esc_html( wp_date( (string) get_option( 'time_format' ), $karetaker_paused ) ) );
						} else {
							echo esc_html__( 'Pause alerts for 1 hour while you update plugins. Everything is still recorded.', 'karetaker' );
						}
						?>
					</p>
				</div>
				<?php if ( $karetaker_paused ) : ?>
					<button type="button" class="btn" data-kt-pause="0"><?php echo esc_html__( 'Resume alerts', 'karetaker' ); ?></button>
				<?php else : ?>
					<button type="button" class="btn" data-kt-pause="1"><?php echo esc_html__( 'Pause 1 hour', 'karetaker' ); ?></button>
				<?php endif; ?>
			</div>
		</div>

		<div class="card rows">
			<div style="grid-template-columns:1fr">
				<div class="field">
					<label for="setType"><?php echo esc_html__( 'Site type', 'karetaker' ); ?></label>
					<select id="setType" data-kt-setting="site_type">
						<?php foreach ( $karetaker_types as $karetaker_key => $karetaker_label ) : ?>
							<option value="<?php echo esc_attr( $karetaker_key ); ?>"<?php selected( $karetaker_type, $karetaker_key ); ?>><?php echo esc_html( $karetaker_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="hint"><?php echo esc_html__( 'Changes which checks run and how sensitive they are.', 'karetaker' ); ?></span>
				</div>
			</div>
			<div>
				<div><h3><?php echo esc_html__( 'Only administrators can see Karetaker', 'karetaker' ); ?></h3><p><?php echo esc_html__( 'Hides Karetaker from editors, shop managers and clients.', 'karetaker' ); ?></p></div>
				<label class="switch"><input type="checkbox" data-kt-setting="admins_only"<?php checked( Karetaker_Access::admins_only() ); ?> aria-label="<?php echo esc_attr__( 'Only administrators can see Karetaker', 'karetaker' ); ?>"><span></span></label>
			</div>
		</div>

		<div class="card rows">
			<div>
				<div><h3><?php echo esc_html__( 'Advanced mode', 'karetaker' ); ?> <span class="tag"><?php echo esc_html__( 'For agencies & developers', 'karetaker' ); ?></span></h3><p><?php echo esc_html__( 'Adds issue tracking, detailed monitoring, incidents, client reports and API access.', 'karetaker' ); ?></p></div>
				<label class="switch"><input type="checkbox" id="advToggle" data-kt-setting="advanced_mode" data-reload="1"<?php checked( self::advanced() ); ?> aria-label="<?php echo esc_attr__( 'Advanced mode', 'karetaker' ); ?>"><span></span></label>
			</div>
			<div>
				<div><h3><?php echo esc_html__( 'Run setup again', 'karetaker' ); ?></h3><p><?php echo esc_html__( 'Go through the one-minute setup from the start.', 'karetaker' ); ?></p></div>
				<a class="btn" href="<?php echo esc_url( self::admin_page_url( 'home', array( 'setup' => '1' ) ) ); ?>"><?php echo esc_html__( 'Start setup', 'karetaker' ); ?></a>
			</div>
			<div>
				<div><h3><?php echo esc_html__( 'Emergency off switch', 'karetaker' ); ?></h3><p><?php echo esc_html__( 'If Karetaker ever causes trouble, add', 'karetaker' ); ?> <code>define( 'KARETAKER_DISABLE', true );</code> <?php echo esc_html__( 'to wp-config.php.', 'karetaker' ); ?></p></div>
				<span></span>
			</div>
		</div>

		<?php
	/**
	 * Fires after the built-in Settings cards, before Credits.
	 *
	 * @since 1.0.2
	 */
	do_action( 'karetaker_settings_cards' );
	?>

	<div class="card rows" id="credits">
			<div style="grid-template-columns:1fr">
				<div><h3><?php echo esc_html__( 'Credits', 'karetaker' ); ?></h3><p><?php echo esc_html__( 'Karetaker is built by Team Krikir and relies on these projects.', 'karetaker' ); ?></p></div>
				<ul class="credits">
					<?php foreach ( $karetaker_credits as $karetaker_credit ) : ?>
						<li><a href="<?php echo esc_url( $karetaker_credit[1] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $karetaker_credit[0] ); ?></a><span><?php echo esc_html( $karetaker_credit[2] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
				<p class="hint"><?php echo esc_html__( 'WordPress is a registered trademark of the WordPress Foundation. Microsoft and Microsoft Teams are trademarks of the Microsoft group of companies. Slack is a trademark of Slack Technologies, LLC. Discord is a trademark of Discord Inc. Google and Googlebot are trademarks of Google LLC. Telegram and all other trademarks are the property of their respective owners. Karetaker is not created, endorsed, sponsored or certified by any of these companies.', 'karetaker' ); ?></p>
			</div>
		</div>
	</div>
</section>
