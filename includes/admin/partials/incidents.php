<?php
/**
 * Advanced: Incidents (cases) and Alert routing.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karetaker_can_route = current_user_can( 'manage_options' );
$karetaker_views     = array( 'cases' => __( 'Incidents', 'karetaker' ) );
if ( $karetaker_can_route ) {
	$karetaker_views['routing'] = __( 'Alert routing', 'karetaker' );
}
$karetaker_view = self::current_view( array_keys( $karetaker_views ) );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Old "Alerts" tab links land on routing.
if ( $karetaker_can_route && isset( $_GET['tab'] ) && 'alerts' === $_GET['tab'] && ! isset( $_GET['view'] ) ) {
	$karetaker_view = 'routing';
}
?>
<section data-page="incidents">
	<div class="subnav seg" style="margin-bottom:14px">
		<?php foreach ( $karetaker_views as $karetaker_key => $karetaker_label ) : ?>
			<a href="<?php echo esc_url( self::admin_page_url( 'incidents', array( 'view' => $karetaker_key ) ) ); ?>"<?php echo $karetaker_key === $karetaker_view ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $karetaker_label ); ?></a>
		<?php endforeach; ?>
	</div>

<?php
if ( 'cases' === $karetaker_view ) :
	$karetaker_cases  = Karetaker_Cases::all();
	$karetaker_steps  = count( Karetaker_Cases::steps() );
	$karetaker_active = array();
	foreach ( Karetaker_Issues::all( array( 'status' => 'active' ) ) as $karetaker_issue ) {
		if ( 'act' === $karetaker_issue['sev'] ) {
			$karetaker_active[] = (int) $karetaker_issue['id'];
		}
	}
	?>
	<div class="phead"><div><h1><?php echo esc_html__( 'Incidents', 'karetaker' ); ?></h1><p><?php echo esc_html__( 'Group related issues into one case, work through the checklist, and export a record.', 'karetaker' ); ?></p></div><span class="grow"></span><div class="tools"><button type="button" class="btn primary" data-kt-case-open="<?php echo esc_attr( implode( ',', $karetaker_active ) ); ?>"><?php echo esc_html__( 'Open new incident', 'karetaker' ); ?></button></div></div>
	<div class="panel">
		<div class="tw"><table>
			<thead><tr><th><?php echo esc_html__( 'Case', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Status', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Linked issues', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Checklist', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Opened', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Owner', 'karetaker' ); ?></th><th></th></tr></thead>
			<tbody>
				<?php if ( ! $karetaker_cases ) : ?>
					<tr><td colspan="7"><div class="empty"><b><?php echo esc_html__( 'No incidents yet.', 'karetaker' ); ?></b><?php echo esc_html__( 'Open one when you think the site was broken into. The checklist walks you through the response.', 'karetaker' ); ?></div></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $karetaker_cases as $karetaker_case ) :
					$karetaker_owner = get_userdata( (int) $karetaker_case['owner'] );
					$karetaker_done  = count( (array) $karetaker_case['done'] );
					self::expose(
						'cases',
						$karetaker_case['id'],
						array(
							'id'     => $karetaker_case['id'],
							'title'  => $karetaker_case['title'],
							'status' => $karetaker_case['status'],
							'done'   => array_keys( (array) $karetaker_case['done'] ),
							'export' => Karetaker_Exports::url(
								'case',
								array(
									'id'     => $karetaker_case['id'],
									'format' => 'html',
								)
							),
						)
					);
					?>
					<tr>
						<td><b><?php echo esc_html( $karetaker_case['id'] ); ?></b><span class="s"><?php echo esc_html( $karetaker_case['title'] ); ?></span></td>
						<td><span class="chip <?php echo 'open' === $karetaker_case['status'] ? 'bad' : 'ok'; ?>"><?php echo 'open' === $karetaker_case['status'] ? esc_html__( 'Open', 'karetaker' ) : esc_html__( 'Closed', 'karetaker' ); ?></span></td>
						<td class="num"><?php echo esc_html( (string) count( (array) $karetaker_case['issues'] ) ); ?></td>
						<?php /* translators: 1: steps done, 2: total steps */ ?>
						<td class="num"><?php echo esc_html( sprintf( __( '%1$d of %2$d', 'karetaker' ), $karetaker_done, $karetaker_steps ) ); ?></td>
						<td class="num"><?php echo esc_html( wp_date( (string) get_option( 'date_format' ) . ' H:i', (int) $karetaker_case['opened'] ) ); ?></td>
						<td><?php echo esc_html( $karetaker_owner ? $karetaker_owner->user_login : '—' ); ?></td>
						<td>
							<?php if ( 'open' === $karetaker_case['status'] ) : ?>
								<button type="button" class="btn xs" data-kt-case="<?php echo esc_attr( $karetaker_case['id'] ); ?>"><?php echo esc_html__( 'Continue', 'karetaker' ); ?></button>
							<?php else : ?>
								<button type="button" class="btn xs" data-kt-case="<?php echo esc_attr( $karetaker_case['id'] ); ?>"><?php echo esc_html__( 'View', 'karetaker' ); ?></button>
								<a class="btn xs" href="<?php echo esc_url( self::export_url( 'case', 'html', $karetaker_case['id'] ) ); ?>"><?php echo esc_html__( 'Export', 'karetaker' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table></div>
	</div>

	<?php
else :
	$karetaker_routes   = Karetaker_Routing::routes();
	$karetaker_labels   = Karetaker_Routing::labels();
	$karetaker_s        = Karetaker_Settings::all();
	$karetaker_windows  = Karetaker_Routing::windows();
	$karetaker_paused   = Karetaker_Settings::alerts_paused_until();
	$karetaker_sub      = array(
		'email'    => Karetaker_Settings::alert_email(),
		'telegram' => '' !== (string) $karetaker_s['telegram_chat_id'] ? sprintf( /* translators: %s: chat id */ __( 'Chat %s', 'karetaker' ), (string) $karetaker_s['telegram_chat_id'] ) : '',
		'slack'    => '' !== (string) $karetaker_s['slack_webhook_url'] ? (string) wp_parse_url( (string) $karetaker_s['slack_webhook_url'], PHP_URL_HOST ) : '',
		'discord'  => '' !== (string) $karetaker_s['discord_webhook_url'] ? (string) wp_parse_url( (string) $karetaker_s['discord_webhook_url'], PHP_URL_HOST ) : '',
		'teams'    => '' !== (string) $karetaker_s['teams_webhook_url'] ? (string) wp_parse_url( (string) $karetaker_s['teams_webhook_url'], PHP_URL_HOST ) : '',
		'webhook'  => '' !== (string) $karetaker_s['webhook_secret'] ? __( 'HMAC-SHA256 signed', 'karetaker' ) : (string) wp_parse_url( (string) $karetaker_s['webhook_url'], PHP_URL_HOST ),
	);
	$karetaker_previews = array(
		'act'    => __( 'Act now alert', 'karetaker' ),
		'review' => __( 'Check this alert', 'karetaker' ),
		'weekly' => __( 'Weekly summary', 'karetaker' ),
		'pause'  => __( 'Pause summary', 'karetaker' ),
		'test'   => __( 'Test email', 'karetaker' ),
	);
	$karetaker_days     = array();
	for ( $karetaker_d = 1; $karetaker_d <= 7; $karetaker_d++ ) {
		$karetaker_days[ $karetaker_d ] = wp_date( 'l', ( new DateTimeImmutable( 'monday this week' ) )->modify( '+' . ( $karetaker_d - 1 ) . ' days' )->getTimestamp() );
	}
	?>
	<div class="phead"><div><h1><?php echo esc_html__( 'Alert routing', 'karetaker' ); ?></h1><p><?php echo esc_html__( 'Decide which alerts go where. Everything is logged regardless.', 'karetaker' ); ?></p></div><span class="grow"></span><div class="tools">
		<div class="menu-wrap">
			<button type="button" class="btn" data-kt-toggle="kt-previews" aria-expanded="false"><?php echo esc_html__( 'Preview emails', 'karetaker' ); ?></button>
			<div class="menu" id="kt-previews" hidden>
				<?php foreach ( $karetaker_previews as $karetaker_type => $karetaker_label ) : ?>
					<div class="menu-row"><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=karetaker_email_preview&type=' . $karetaker_type ), 'karetaker_email_preview' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $karetaker_label ); ?></a></div>
				<?php endforeach; ?>
			</div>
		</div>
		<button type="button" class="btn" data-kt-test><?php echo esc_html__( 'Send test alert', 'karetaker' ); ?></button>
	</div></div>
	<div class="panel">
		<header><h2><?php echo esc_html__( 'Routing rules', 'karetaker' ); ?></h2><span class="sub"><?php echo esc_html__( 'Changes save as you switch them', 'karetaker' ); ?></span></header>
		<div class="tw"><table class="matrix">
			<thead><tr><th><?php echo esc_html__( 'Destination', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Act now', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Review', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Weekly summary', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Status', 'karetaker' ); ?></th><th></th></tr></thead>
			<tbody>
				<?php
				foreach ( $karetaker_labels as $karetaker_ch => $karetaker_label ) :
					$karetaker_on = Karetaker_Routing::connected( $karetaker_ch );
					?>
					<tr>
						<td><b><?php echo esc_html( $karetaker_label ); ?></b>
						<?php
						if ( $karetaker_on && '' !== $karetaker_sub[ $karetaker_ch ] ) :
							?>
							<span class="s"><?php echo esc_html( $karetaker_sub[ $karetaker_ch ] ); ?></span><?php endif; ?></td>
						<?php foreach ( array( 'act', 'review', 'weekly' ) as $karetaker_kind ) : ?>
							<td>
								<?php if ( $karetaker_on && ( 'weekly' !== $karetaker_kind || 'webhook' !== $karetaker_ch ) ) : ?>
									<?php /* translators: 1: destination, 2: rule */ ?>
									<label class="switch"><input type="checkbox" data-kt-route="<?php echo esc_attr( $karetaker_ch ); ?>" data-kind="<?php echo esc_attr( $karetaker_kind ); ?>"<?php checked( $karetaker_routes[ $karetaker_ch ][ $karetaker_kind ] ); ?> aria-label="<?php echo esc_attr( sprintf( __( '%1$s: %2$s', 'karetaker' ), $karetaker_label, $karetaker_kind ) ); ?>"><span></span></label>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						<?php endforeach; ?>
						<td>
							<?php if ( ! $karetaker_on ) : ?>
								<span class="chip mute"><?php echo esc_html__( 'Not set up', 'karetaker' ); ?></span>
							<?php elseif ( 'email' === $karetaker_ch ) : ?>
								<?php
								$karetaker_mail_bad = ! empty( Karetaker_Scanner::state()['guard']['mail_failed']['bad'] );
								?>
								<span class="chip <?php echo $karetaker_mail_bad ? 'bad' : 'ok'; ?>"><?php echo $karetaker_mail_bad ? esc_html__( 'Sending failed', 'karetaker' ) : esc_html__( 'Delivering', 'karetaker' ); ?></span>
							<?php else : ?>
								<span class="chip ok"><?php echo esc_html__( 'Connected', 'karetaker' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( 'email' === $karetaker_ch ) : ?>
								<a class="btn xs" href="<?php echo esc_url( self::admin_page_url( 'settings' ) ); ?>"><?php echo esc_html__( 'Edit', 'karetaker' ); ?></a>
							<?php else : ?>
								<button type="button" class="btn xs" data-connect="<?php echo esc_attr( $karetaker_ch ); ?>"><?php echo $karetaker_on ? esc_html__( 'Edit', 'karetaker' ) : esc_html__( 'Connect', 'karetaker' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table></div>
	</div>
	<div class="g2">
		<div class="panel">
			<header><h2><?php echo esc_html__( 'Maintenance windows', 'karetaker' ); ?></h2><span class="grow"></span>
				<?php if ( $karetaker_paused ) : ?>
					<button type="button" class="btn xs" data-kt-pause="0"><?php echo esc_html__( 'Resume alerts', 'karetaker' ); ?></button>
				<?php else : ?>
					<button type="button" class="btn xs primary" data-kt-pause="1"><?php echo esc_html__( 'Pause for 1 hour', 'karetaker' ); ?></button>
				<?php endif; ?>
			</header>
			<div class="tw"><table>
				<thead><tr><th><?php echo esc_html__( 'Window', 'karetaker' ); ?></th><th><?php echo esc_html__( 'When', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Created by', 'karetaker' ); ?></th><th></th></tr></thead>
				<tbody>
					<?php if ( $karetaker_paused ) : ?>
						<?php /* translators: %s: time */ ?>
						<tr><td><?php echo esc_html__( 'Paused now', 'karetaker' ); ?></td><td><?php echo esc_html( sprintf( __( 'Until %s', 'karetaker' ), wp_date( (string) get_option( 'time_format' ), $karetaker_paused ) ) ); ?></td><td>—</td><td></td></tr>
					<?php endif; ?>
					<?php
					foreach ( $karetaker_windows as $karetaker_i => $karetaker_w ) :
						$karetaker_by = get_userdata( (int) $karetaker_w['by'] );
						?>
						<tr>
							<td><?php echo esc_html( '' !== $karetaker_w['name'] ? $karetaker_w['name'] : __( 'Maintenance', 'karetaker' ) ); ?></td>
							<td><?php echo esc_html( Karetaker_Routing::window_label( $karetaker_w ) . ' ' . wp_timezone_string() ); ?></td>
							<td><?php echo esc_html( $karetaker_by ? $karetaker_by->user_login : '—' ); ?></td>
							<td><button type="button" class="btn xs" data-kt-window-delete="<?php echo esc_attr( (string) $karetaker_i ); ?>"><?php echo esc_html__( 'Remove', 'karetaker' ); ?></button></td>
						</tr>
					<?php endforeach; ?>
					<tr class="addrow">
						<td><input type="text" id="wName" placeholder="<?php echo esc_attr__( 'Weekly updates', 'karetaker' ); ?>" aria-label="<?php echo esc_attr__( 'Window name', 'karetaker' ); ?>"></td>
						<td><div class="inline">
							<select id="wDay" aria-label="<?php echo esc_attr__( 'Day', 'karetaker' ); ?>">
								<?php foreach ( $karetaker_days as $karetaker_n => $karetaker_day ) : ?>
									<option value="<?php echo esc_attr( (string) $karetaker_n ); ?>"><?php echo esc_html( $karetaker_day ); ?></option>
								<?php endforeach; ?>
							</select>
							<input type="time" id="wStart" value="22:00" aria-label="<?php echo esc_attr__( 'Start', 'karetaker' ); ?>">–<input type="time" id="wEnd" value="23:00" aria-label="<?php echo esc_attr__( 'End', 'karetaker' ); ?>">
						</div></td>
						<td></td>
						<td><button type="button" class="btn xs primary" data-kt-window-add><?php echo esc_html__( 'Add', 'karetaker' ); ?></button></td>
					</tr>
				</tbody>
			</table></div>
			<footer><?php echo esc_html__( 'During a window, alerts are held and sent as one summary when it ends.', 'karetaker' ); ?></footer>
		</div>
		<div class="panel">
			<header><h2><?php echo esc_html__( 'Delivery', 'karetaker' ); ?></h2></header>
			<div class="form">
				<label for="dedupe"><?php echo esc_html__( 'Repeat alerts', 'karetaker' ); ?></label>
				<div class="ctl"><select id="dedupe" data-kt-setting="alert_repeat">
					<option value="24h"<?php selected( $karetaker_s['alert_repeat'], '24h' ); ?>><?php echo esc_html__( 'Once per 24 hours', 'karetaker' ); ?></option>
					<option value="6h"<?php selected( $karetaker_s['alert_repeat'], '6h' ); ?>><?php echo esc_html__( 'Once per 6 hours', 'karetaker' ); ?></option>
					<option value="every"<?php selected( $karetaker_s['alert_repeat'], 'every' ); ?>><?php echo esc_html__( 'Every time', 'karetaker' ); ?></option>
				</select></div>
				<label for="quiet"><?php echo esc_html__( 'Quiet hours', 'karetaker' ); ?></label>
				<div class="ctl"><div class="inline"><input type="text" id="quiet" data-kt-setting="quiet_hours" value="<?php echo esc_attr( (string) $karetaker_s['quiet_hours'] ); ?>" placeholder="23:00-07:00" style="max-width:140px"><span class="hint"><?php echo esc_html__( 'Review-level alerts only. Act now always sends.', 'karetaker' ); ?></span></div></div>
				<label for="digest"><?php echo esc_html__( 'Weekly summary', 'karetaker' ); ?></label>
				<div class="ctl"><select id="digest" data-kt-setting="weekly_slot">
					<option value="mon09"<?php selected( $karetaker_s['weekly_slot'], 'mon09' ); ?>><?php echo esc_html__( 'Monday 09:00', 'karetaker' ); ?></option>
					<option value="fri17"<?php selected( $karetaker_s['weekly_slot'], 'fri17' ); ?>><?php echo esc_html__( 'Friday 17:00', 'karetaker' ); ?></option>
				</select></div>
			</div>
		</div>
	</div>
<?php endif; ?>
</section>
