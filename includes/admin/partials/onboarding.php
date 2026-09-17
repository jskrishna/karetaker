<?php
/**
 * One-minute setup shown on first visit (and from Settings → Run setup again).
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karetaker_email = Karetaker_Settings::alert_email();
$karetaker_type  = (string) Karetaker_Settings::get( 'site_type' );
$karetaker_woo   = class_exists( 'WooCommerce' );
if ( '' === $karetaker_type ) {
	$karetaker_type = $karetaker_woo ? 'store' : 'business';
}
$karetaker_types  = array(
	'blog'     => array( __( 'Blog', 'karetaker' ), __( 'Posts and pages', 'karetaker' ), '<path d="M4 20h4L19 9l-4-4L4 16z"/>' ),
	'business' => array( __( 'Business', 'karetaker' ), __( 'Company or services', 'karetaker' ), '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>' ),
	'store'    => array( __( 'Online store', 'karetaker' ), __( 'Also watches checkout', 'karetaker' ), '<path d="M3 4h2l2.4 11h10.2L20 7H6.2"/><circle cx="9" cy="19" r="1.5"/><circle cx="17" cy="19" r="1.5"/>' ),
	'client'   => array( __( 'Client site', 'karetaker' ), __( 'I manage it for someone', 'karetaker' ), '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.5 3.4-5.5 6.5-5.5s5.7 2 6.5 5.5M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5c2 .6 3.2 2.5 3.5 5.5"/>' ),
);
$karetaker_harden = Karetaker_Settings::harden();
$karetaker_first  = get_option( 'karetaker_onboarded' ) ? false : true;
$karetaker_quick  = array(
	'user_enum'   => array( __( 'Hide your usernames', 'karetaker' ), __( 'Makes it harder for bots to guess logins.', 'karetaker' ) ),
	'xmlrpc'      => array( __( 'Turn off XML-RPC', 'karetaker' ), __( 'An old remote door that bots use for password guessing.', 'karetaker' ) ),
	'file_editor' => array( __( 'Block the code editor', 'karetaker' ), __( 'Nobody can edit plugin or theme code from the dashboard.', 'karetaker' ) ),
);
$karetaker_runs   = array( __( 'Important files', 'karetaker' ), __( 'Administrators', 'karetaker' ), __( 'Plugins', 'karetaker' ), __( 'WordPress core files', 'karetaker' ), __( 'Plugin files', 'karetaker' ), __( 'Uploads folder', 'karetaker' ) );
?>
<div class="onb" id="onb" data-home="<?php echo esc_url( Karetaker_Admin::admin_page_url( 'home' ) ); ?>">
	<div class="card">
		<div class="brand">
			<?php Karetaker_Admin::brand_mark(); ?>
			<b><?php echo esc_html__( 'Karetaker', 'karetaker' ); ?></b><span class="muted">· <?php echo esc_html__( 'Setup takes about a minute', 'karetaker' ); ?></span>
		</div>
		<div class="steps" aria-hidden="true"><i class="on"></i><i></i><i></i><i></i></div>

		<section data-step="1">
			<h2><?php echo esc_html__( 'Where should we tell you about problems?', 'karetaker' ); ?></h2>
			<p class="lead"><?php echo esc_html__( 'We only send a message when something needs you. No newsletters, no daily noise.', 'karetaker' ); ?></p>
			<div class="field">
				<label for="onbEmail"><?php echo esc_html__( 'Email', 'karetaker' ); ?></label>
				<input type="email" id="onbEmail" value="<?php echo esc_attr( $karetaker_email ); ?>">
				<span class="hint"><?php echo esc_html__( 'You can add Telegram, Slack or Discord later in Settings.', 'karetaker' ); ?></span>
			</div>
		</section>

		<section data-step="2" hidden>
			<h2><?php echo esc_html__( 'What kind of site is this?', 'karetaker' ); ?></h2>
			<p class="lead"><?php echo esc_html__( 'We\'ll pick sensible settings for you. You can change them anytime.', 'karetaker' ); ?></p>
			<div class="types" role="radiogroup" aria-label="<?php echo esc_attr__( 'Site type', 'karetaker' ); ?>" id="types">
				<?php foreach ( $karetaker_types as $karetaker_key => $karetaker_row ) : ?>
					<button type="button" class="type" role="radio" aria-checked="<?php echo $karetaker_key === $karetaker_type ? 'true' : 'false'; ?>" data-type="<?php echo esc_attr( $karetaker_key ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><?php echo wp_kses( $karetaker_row[2], Karetaker_Admin::svg_kses() ); ?></svg><b><?php echo esc_html( $karetaker_row[0] ); ?></b><span><?php echo esc_html( $karetaker_row[1] ); ?></span></button>
				<?php endforeach; ?>
			</div>
			<?php if ( $karetaker_woo ) : ?>
				<p class="hint" style="margin-top:10px">
					<?php
					/* translators: %s: "Online store" */
					printf( esc_html__( 'We noticed WooCommerce, so we picked %s.', 'karetaker' ), '<b>' . esc_html__( 'Online store', 'karetaker' ) . '</b>' );
					?>
				</p>
			<?php endif; ?>
		</section>

		<section data-step="3" hidden>
			<h2><?php echo esc_html__( 'Taking a first look at your site', 'karetaker' ); ?></h2>
			<p class="lead"><?php echo esc_html__( 'This runs once now, then twice a day in the background.', 'karetaker' ); ?></p>
			<ul class="run" id="run">
				<?php foreach ( $karetaker_runs as $karetaker_run ) : ?>
					<li><span class="st"></span><?php echo esc_html( $karetaker_run ); ?><span class="r"></span></li>
				<?php endforeach; ?>
			</ul>
			<div class="result" id="runResult" hidden></div>
		</section>

		<section data-step="4" hidden>
			<h2><?php echo esc_html__( 'Turn on a few easy protections?', 'karetaker' ); ?></h2>
			<p class="lead"><?php echo esc_html__( 'These are safe for most sites and can\'t lock you out. Skip if you\'re not sure.', 'karetaker' ); ?></p>
			<div class="rows card" style="border-radius:8px">
				<?php foreach ( $karetaker_quick as $karetaker_key => $karetaker_row ) : ?>
					<div><div><h3><?php echo esc_html( $karetaker_row[0] ); ?></h3><p><?php echo esc_html( $karetaker_row[1] ); ?></p></div><label class="switch"><input type="checkbox" data-harden="<?php echo esc_attr( $karetaker_key ); ?>"<?php checked( $karetaker_first || ! empty( $karetaker_harden[ $karetaker_key ] ) ); ?> aria-label="<?php echo esc_attr( $karetaker_row[0] ); ?>"><span></span></label></div>
				<?php endforeach; ?>
			</div>
		</section>

		<div class="foot">
			<button type="button" class="btn link" id="onbBack" hidden><?php echo esc_html__( 'Back', 'karetaker' ); ?></button>
			<span class="grow"></span>
			<button type="button" class="btn link" id="onbSkip" hidden><?php echo esc_html__( 'Skip', 'karetaker' ); ?></button>
			<button type="button" class="btn primary big" id="onbNext"><?php echo esc_html__( 'Continue', 'karetaker' ); ?></button>
		</div>
	</div>
	<p class="hint" style="text-align:center;margin-top:12px"><button type="button" class="btn link" id="onbLater"><?php echo esc_html__( 'Set up later', 'karetaker' ); ?></button></p>
</div>
