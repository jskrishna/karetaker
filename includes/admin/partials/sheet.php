<?php
/**
 * Side sheet: issue guidance ("Show me what to do") and connecting an alert destination.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karetaker_s        = Karetaker_Settings::all();
$karetaker_channels = array(
	'telegram' => array(
		'label'  => __( 'Telegram', 'karetaker' ),
		'help'   => __( 'Create a bot with @BotFather, add it to your chat, then paste the bot token and chat ID.', 'karetaker' ),
		'fields' => array(
			array( 'token', __( 'Bot token', 'karetaker' ), 'password', '' !== (string) $karetaker_s['telegram_bot_token'] ? __( 'Saved. Leave empty to keep it.', 'karetaker' ) : '123456:ABC…' ),
			array( 'chat_id', __( 'Chat ID', 'karetaker' ), 'text', '-1001234567890', (string) $karetaker_s['telegram_chat_id'] ),
		),
	),
	'slack'    => array(
		'label'  => __( 'Slack', 'karetaker' ),
		'help'   => __( 'In Slack, add an Incoming Webhook to the channel and paste its URL.', 'karetaker' ),
		'fields' => array( array( 'url', __( 'Webhook URL', 'karetaker' ), 'url', 'https://hooks.slack.com/services/…', (string) $karetaker_s['slack_webhook_url'] ) ),
	),
	'discord'  => array(
		'label'  => __( 'Discord', 'karetaker' ),
		'help'   => __( 'In Discord, open channel settings → Integrations → Webhooks, create one and paste its URL.', 'karetaker' ),
		'fields' => array( array( 'url', __( 'Webhook URL', 'karetaker' ), 'url', 'https://discord.com/api/webhooks/…', (string) $karetaker_s['discord_webhook_url'] ) ),
	),
	'teams'    => array(
		'label'  => __( 'Microsoft Teams', 'karetaker' ),
		'help'   => __( 'In Teams, add the "Post to a channel when a webhook request is received" workflow and paste its URL.', 'karetaker' ),
		'fields' => array( array( 'url', __( 'Workflow URL', 'karetaker' ), 'url', 'https://…', (string) $karetaker_s['teams_webhook_url'] ) ),
	),
	'webhook'  => array(
		'label'  => __( 'Webhook', 'karetaker' ),
		'help'   => __( 'Karetaker POSTs JSON to this URL. With a secret, each request carries an HMAC-SHA256 signature.', 'karetaker' ),
		'fields' => array(
			array( 'url', __( 'URL', 'karetaker' ), 'url', 'https://…', (string) $karetaker_s['webhook_url'] ),
			array( 'secret', __( 'Secret (optional)', 'karetaker' ), 'text', '', (string) $karetaker_s['webhook_secret'] ),
		),
	),
);
?>
<div class="scrim" id="scrim"></div>
<aside class="panel" id="panel" role="dialog" aria-modal="true" aria-labelledby="pTitle">
	<header><h2 id="pTitle"></h2><button type="button" class="btn link" id="pClose" aria-label="<?php echo esc_attr__( 'Close', 'karetaker' ); ?>" style="text-decoration:none;font-size:18px;color:var(--muted)">✕</button></header>
	<div class="body" data-mode="issue">
		<h4><?php echo esc_html__( 'What happened', 'karetaker' ); ?></h4><p id="pWhat"></p>
		<div id="pWhyWrap"><h4><?php echo esc_html__( 'Why it matters', 'karetaker' ); ?></h4><p id="pWhy"></p></div>
		<h4><?php echo esc_html__( 'What to do', 'karetaker' ); ?></h4><ol id="pSteps"></ol>
		<p id="pLink" hidden><a href="#"></a></p>
		<details><summary><?php echo esc_html__( 'Technical details', 'karetaker' ); ?></summary><dl id="pDl"></dl></details>
	</div>
	<footer data-mode="issue">
		<button type="button" class="btn" id="pMine"><?php echo esc_html__( 'This was me', 'karetaker' ); ?></button>
		<button type="button" class="btn primary" id="pDone"><?php echo esc_html__( 'I\'ve fixed it', 'karetaker' ); ?></button>
	</footer>

	<?php if ( current_user_can( 'manage_options' ) ) : ?>
		<?php
		foreach ( $karetaker_channels as $karetaker_ch => $karetaker_def ) :
			$karetaker_on = Karetaker_Routing::connected( $karetaker_ch );
			?>
			<form class="body connect" data-mode="connect" data-channel="<?php echo esc_attr( $karetaker_ch ); ?>" data-label="<?php echo esc_attr( $karetaker_def['label'] ); ?>" data-connected="<?php echo $karetaker_on ? '1' : '0'; ?>" hidden>
				<p class="muted" style="margin-bottom:14px"><?php echo esc_html( $karetaker_def['help'] ); ?></p>
				<?php foreach ( $karetaker_def['fields'] as $karetaker_f ) : ?>
					<div class="field" style="margin-bottom:12px">
						<label for="<?php echo esc_attr( 'kt-' . $karetaker_ch . '-' . $karetaker_f[0] ); ?>"><?php echo esc_html( $karetaker_f[1] ); ?></label>
						<input type="<?php echo esc_attr( $karetaker_f[2] ); ?>" id="<?php echo esc_attr( 'kt-' . $karetaker_ch . '-' . $karetaker_f[0] ); ?>" name="<?php echo esc_attr( $karetaker_f[0] ); ?>" placeholder="<?php echo esc_attr( $karetaker_f[3] ); ?>" value="<?php echo esc_attr( isset( $karetaker_f[4] ) ? $karetaker_f[4] : '' ); ?>" autocomplete="off">
					</div>
				<?php endforeach; ?>
			</form>
		<?php endforeach; ?>
		<footer data-mode="connect" hidden>
			<button type="button" class="btn" id="cRemove"><?php echo esc_html__( 'Remove', 'karetaker' ); ?></button>
			<button type="button" class="btn primary" id="cSave"><?php echo esc_html__( 'Save', 'karetaker' ); ?></button>
		</footer>
	<?php endif; ?>
</aside>
