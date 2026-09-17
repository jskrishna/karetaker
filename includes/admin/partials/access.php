<?php
/**
 * Advanced: Access & API, and Connections & privacy.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karetaker_views = array(
	'api'     => __( 'Access & API', 'karetaker' ),
	'privacy' => __( 'Connections & privacy', 'karetaker' ),
);
$karetaker_view  = self::current_view( array_keys( $karetaker_views ) );
?>
<section data-page="access">
	<div class="subnav seg" style="margin-bottom:14px">
		<?php foreach ( $karetaker_views as $karetaker_key => $karetaker_label ) : ?>
			<a href="<?php echo esc_url( self::admin_page_url( 'access', array( 'view' => $karetaker_key ) ) ); ?>"<?php echo $karetaker_key === $karetaker_view ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $karetaker_label ); ?></a>
		<?php endforeach; ?>
	</div>

<?php
if ( 'api' === $karetaker_view ) :
	$karetaker_roles  = Karetaker_Access::roles();
	$karetaker_matrix = Karetaker_Access::matrix();
	$karetaker_only   = Karetaker_Access::admins_only();
	$karetaker_tokens = Karetaker_Access::tokens();
	$karetaker_audit  = Karetaker_Access::audit_trail( 20 );
	$karetaker_date   = static function ( $ts ) {
		return $ts ? wp_date( 'M Y', (int) $ts ) : '';
	};
	?>
	<div class="phead"><div><h1><?php echo esc_html__( 'Access & API', 'karetaker' ); ?></h1><p><?php echo esc_html__( 'Who can use Karetaker on this site, and how external dashboards connect.', 'karetaker' ); ?></p></div><span class="grow"></span><div class="tools"><button type="button" class="btn primary" data-kt-roles<?php disabled( $karetaker_only || ! $karetaker_roles ); ?>><?php echo esc_html__( 'Save changes', 'karetaker' ); ?></button></div></div>
	<?php if ( $karetaker_only ) : ?>
		<div class="notice-kt warn"><div class="grow"><b><?php echo esc_html__( 'Only administrators can see Karetaker.', 'karetaker' ); ?></b> <span class="muted"><?php echo esc_html__( 'Turn that off in Settings to give other roles access.', 'karetaker' ); ?></span></div><a class="btn xs" href="<?php echo esc_url( self::admin_page_url( 'settings' ) ); ?>"><?php echo esc_html__( 'Settings', 'karetaker' ); ?></a></div>
	<?php endif; ?>
	<div class="panel">
		<header><h2><?php echo esc_html__( 'Permissions', 'karetaker' ); ?></h2><span class="sub"><?php echo esc_html__( 'Administrators always have full access', 'karetaker' ); ?></span></header>
		<div class="tw"><table class="matrix" id="roleMatrix">
			<thead><tr><th><?php echo esc_html__( 'Role', 'karetaker' ); ?></th><th><?php echo esc_html__( 'View status', 'karetaker' ); ?></th><th><?php echo esc_html__( 'View activity log', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Resolve issues', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Change settings', 'karetaker' ); ?></th></tr></thead>
			<tbody>
				<tr><td><?php echo esc_html( translate_user_role( 'Administrator' ) ); ?></td><td>✓</td><td>✓</td><td>✓</td><td>✓</td></tr>
				<?php foreach ( $karetaker_roles as $karetaker_role => $karetaker_name ) : ?>
					<tr>
						<td><?php echo esc_html( $karetaker_name ); ?></td>
						<?php foreach ( Karetaker_Access::CAPS as $karetaker_cap ) : ?>
							<?php /* translators: 1: role, 2: capability */ ?>
							<td><label class="switch"><input type="checkbox" data-role="<?php echo esc_attr( $karetaker_role ); ?>" data-cap="<?php echo esc_attr( $karetaker_cap ); ?>"<?php checked( $karetaker_matrix[ $karetaker_role ][ $karetaker_cap ] ); ?><?php disabled( $karetaker_only ); ?> aria-label="<?php echo esc_attr( sprintf( __( '%1$s: %2$s', 'karetaker' ), $karetaker_name, $karetaker_cap ) ); ?>"><span></span></label></td>
						<?php endforeach; ?>
						<td>—</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table></div>
		<footer><?php echo esc_html__( 'Implemented with custom capabilities:', 'karetaker' ); ?> <code>karetaker_view</code>, <code>karetaker_view_log</code>, <code>karetaker_resolve</code>.</footer>
	</div>

	<div class="panel">
		<header><h2><?php echo esc_html__( 'API tokens', 'karetaker' ); ?></h2><span class="sub"><?php echo esc_html__( 'Read-only access to', 'karetaker' ); ?> <code>/wp-json/karetaker/v1/</code></span><span class="grow"></span><button type="button" class="btn xs primary" data-kt-toggle="tokenForm"><?php echo esc_html__( 'Create token', 'karetaker' ); ?></button></header>
		<div class="notice-kt" id="tokenOnce" hidden style="margin:12px 14px"><div class="grow"><b><?php echo esc_html__( 'Copy this token now. It won\'t be shown again.', 'karetaker' ); ?></b><div><code id="tokenValue"></code></div></div><button type="button" class="btn xs" data-kt-copy="tokenValue"><?php echo esc_html__( 'Copy', 'karetaker' ); ?></button></div>
		<div class="form" id="tokenForm" hidden>
			<label for="tName"><?php echo esc_html__( 'Name', 'karetaker' ); ?></label><div class="ctl"><input type="text" id="tName" placeholder="<?php echo esc_attr__( 'Fleet dashboard', 'karetaker' ); ?>"></div>
			<div class="lbl"><?php echo esc_html__( 'Scope', 'karetaker' ); ?></div><div class="ctl inline"><label class="inline"><input type="checkbox" name="tScope" value="status" checked> status</label><label class="inline"><input type="checkbox" name="tScope" value="events"> events</label></div>
			<label for="tIps"><?php echo esc_html__( 'Allowed IPs', 'karetaker' ); ?></label><div class="ctl"><input type="text" id="tIps" placeholder="24.84.0.0/16"><span class="hint"><?php echo esc_html__( 'IPs or CIDR ranges, separated by commas. Empty allows any.', 'karetaker' ); ?></span></div>
			<label for="tDays"><?php echo esc_html__( 'Expires', 'karetaker' ); ?></label><div class="ctl"><select id="tDays"><option value="0"><?php echo esc_html__( 'Never', 'karetaker' ); ?></option><option value="90"><?php echo esc_html__( 'In 90 days', 'karetaker' ); ?></option><option value="365" selected><?php echo esc_html__( 'In 1 year', 'karetaker' ); ?></option></select></div>
			<div></div><div class="inline"><button type="button" class="btn primary" data-kt-token="create"><?php echo esc_html__( 'Create token', 'karetaker' ); ?></button></div>
		</div>
		<div class="tw"><table>
			<thead><tr><th><?php echo esc_html__( 'Name', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Scope', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Allowed IPs', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Created', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Last used', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Expires', 'karetaker' ); ?></th><th></th></tr></thead>
			<tbody>
				<?php if ( ! $karetaker_tokens ) : ?>
					<tr><td colspan="7"><div class="empty"><b><?php echo esc_html__( 'No tokens.', 'karetaker' ); ?></b><?php echo esc_html__( 'Create one to let a dashboard read this site\'s status.', 'karetaker' ); ?></div></td></tr>
				<?php endif; ?>
				<?php foreach ( $karetaker_tokens as $karetaker_t ) : ?>
					<tr>
						<td><b><?php echo esc_html( $karetaker_t['name'] ); ?></b><span class="s">kt_…<?php echo esc_html( isset( $karetaker_t['hint'] ) ? (string) $karetaker_t['hint'] : '' ); ?></span></td>
						<td>
						<?php
						foreach ( (array) $karetaker_t['scopes'] as $karetaker_scope ) :
							?>
							<span class="chip info"><?php echo esc_html( $karetaker_scope ); ?></span> <?php endforeach; ?></td>
						<td class="num"><?php echo esc_html( $karetaker_t['ips'] ? implode( ', ', (array) $karetaker_t['ips'] ) : __( 'Any', 'karetaker' ) ); ?></td>
						<td class="num"><?php echo esc_html( $karetaker_date( $karetaker_t['created'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $karetaker_t['last_used'] ? Karetaker_Admin_Data::when( gmdate( 'Y-m-d H:i:s', (int) $karetaker_t['last_used'] ) ) : __( 'Never', 'karetaker' ) ); ?></td>
						<td class="num"><?php echo esc_html( $karetaker_t['expires'] ? $karetaker_date( $karetaker_t['expires'] ) : __( 'Never', 'karetaker' ) ); ?></td>
						<td><button type="button" class="btn xs" data-kt-token="rotate" data-id="<?php echo esc_attr( $karetaker_t['id'] ); ?>"><?php echo esc_html__( 'Rotate', 'karetaker' ); ?></button> <button type="button" class="btn xs danger" data-kt-token="revoke" data-id="<?php echo esc_attr( $karetaker_t['id'] ); ?>" data-name="<?php echo esc_attr( $karetaker_t['name'] ); ?>"><?php echo esc_html__( 'Revoke', 'karetaker' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table></div>
		<footer><?php echo esc_html__( 'Tokens are shown once at creation and stored hashed. Responses are HMAC-signed.', 'karetaker' ); ?></footer>
	</div>

	<div class="panel">
		<header><h2><?php echo esc_html__( 'Karetaker audit trail', 'karetaker' ); ?></h2><span class="sub"><?php echo esc_html__( 'Changes made to Karetaker itself', 'karetaker' ); ?></span></header>
		<div class="tw"><table>
			<thead><tr><th><?php echo esc_html__( 'When', 'karetaker' ); ?></th><th><?php echo esc_html__( 'User', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Change', 'karetaker' ); ?></th></tr></thead>
			<tbody>
				<?php if ( ! $karetaker_audit ) : ?>
					<tr><td colspan="3"><div class="empty"><?php echo esc_html__( 'No changes recorded yet.', 'karetaker' ); ?></div></td></tr>
				<?php endif; ?>
				<?php foreach ( $karetaker_audit as $karetaker_row ) : ?>
					<tr><td class="num"><?php echo esc_html( $karetaker_row['when'] ); ?></td><td><?php echo esc_html( $karetaker_row['user'] ); ?></td><td><?php echo esc_html( $karetaker_row['change'] ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table></div>
	</div>

	<?php
else :
	$karetaker_s     = Karetaker_Settings::all();
	$karetaker_count = 0;
	foreach ( array( 'telegram', 'slack', 'discord', 'teams', 'webhook' ) as $karetaker_ch ) {
		$karetaker_count += Karetaker_Routing::connected( $karetaker_ch ) ? 1 : 0;
	}
	$karetaker_vuln     = ! empty( $karetaker_s['vuln_lookup_enabled'] );
	$karetaker_ignored  = Karetaker_Admin_Data::ignored();
	$karetaker_proxies  = isset( $karetaker_s['trusted_proxies'] ) && is_array( $karetaker_s['trusted_proxies'] ) ? implode( "\n", $karetaker_s['trusted_proxies'] ) : '';
	$karetaker_tokens_n = count( Karetaker_Access::tokens() );
	?>
	<div class="phead"><div><h1><?php echo esc_html__( 'Connections & privacy', 'karetaker' ); ?></h1><p><?php echo esc_html__( 'Everything Karetaker sends off this site, and what it keeps.', 'karetaker' ); ?></p></div></div>
	<div class="panel">
		<header><h2><?php echo esc_html__( 'Connections & privacy', 'karetaker' ); ?></h2><span class="sub"><?php echo esc_html__( 'Everything Karetaker sends off this site', 'karetaker' ); ?></span></header>
		<div class="tw"><table>
			<thead><tr><th><?php echo esc_html__( 'Connection', 'karetaker' ); ?></th><th><?php echo esc_html__( 'What is sent', 'karetaker' ); ?></th><th><?php echo esc_html__( 'When', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Status', 'karetaker' ); ?></th></tr></thead>
			<tbody>
				<tr><td>api.wordpress.org</td><td><?php echo esc_html__( 'WordPress, plugin and theme names with versions, to fetch checksums and plugin status', 'karetaker' ); ?></td><td><?php echo esc_html__( 'Daily', 'karetaker' ); ?></td><td><span class="chip ok"><?php echo esc_html__( 'On', 'karetaker' ); ?></span></td></tr>
				<tr><td><?php echo esc_html__( 'Your site (loopback)', 'karetaker' ); ?></td><td><?php echo esc_html__( 'Requests to your own pages for script and search engine checks', 'karetaker' ); ?></td><td><?php echo esc_html__( 'Daily', 'karetaker' ); ?></td><td><span class="chip ok"><?php echo esc_html__( 'On', 'karetaker' ); ?></span></td></tr>
				<tr><td><?php echo esc_html__( 'Alert destinations', 'karetaker' ); ?></td><td><?php echo esc_html__( 'Alert text to the services you connect', 'karetaker' ); ?></td><td><?php echo esc_html__( 'On alert', 'karetaker' ); ?></td><td>
					<?php /* translators: %d: connected destinations */ ?>
					<span class="chip <?php echo $karetaker_count ? 'ok' : 'mute'; ?>"><?php echo esc_html( sprintf( _n( '%d connected', '%d connected', $karetaker_count, 'karetaker' ), $karetaker_count ) ); ?></span>
				</td></tr>
				<tr><td><?php echo esc_html__( 'Vulnerability list', 'karetaker' ); ?></td><td><?php echo esc_html__( 'Plugin names and versions, to the WPVulnerability API. Site details are not sent.', 'karetaker' ); ?></td><td><?php echo esc_html__( 'Daily', 'karetaker' ); ?></td><td><label class="switch"><input type="checkbox" data-kt-setting="vuln_lookup_enabled"<?php checked( $karetaker_vuln ); ?> aria-label="<?php echo esc_attr__( 'Vulnerability lookup', 'karetaker' ); ?>"><span></span></label></td></tr>
				<tr><td><?php echo esc_html__( 'API tokens', 'karetaker' ); ?></td><td><?php echo esc_html__( 'Status, and events if allowed, to dashboards that hold a token', 'karetaker' ); ?></td><td><?php echo esc_html__( 'When asked', 'karetaker' ); ?></td><td>
					<?php /* translators: %d: tokens */ ?>
					<span class="chip <?php echo $karetaker_tokens_n ? 'ok' : 'mute'; ?>"><?php echo esc_html( sprintf( _n( '%d token', '%d tokens', $karetaker_tokens_n, 'karetaker' ), $karetaker_tokens_n ) ); ?></span>
				</td></tr>
			</tbody>
		</table></div>
		<footer><?php echo esc_html__( 'No usage tracking. Karetaker has no account system and no analytics.', 'karetaker' ); ?></footer>
	</div>
	<div class="panel">
		<header><h2><?php echo esc_html__( 'Data', 'karetaker' ); ?></h2></header>
		<div class="form">
			<label for="ret"><?php echo esc_html__( 'Keep events', 'karetaker' ); ?></label>
			<div class="ctl"><input type="number" id="ret" data-kt-setting="row_cap" min="<?php echo esc_attr( (string) Karetaker_Settings::ROW_CAP_MIN ); ?>" max="<?php echo esc_attr( (string) Karetaker_Settings::ROW_CAP_MAX ); ?>" step="500" value="<?php echo esc_attr( (string) Karetaker_Settings::row_cap() ); ?>" style="max-width:140px"><span class="hint"><?php echo esc_html__( 'Most recent events kept. Older ones are deleted.', 'karetaker' ); ?></span></div>
			<label for="proxies"><?php echo esc_html__( 'Trusted proxies', 'karetaker' ); ?></label>
			<div class="ctl"><textarea id="proxies" rows="2" data-kt-setting="trusted_proxies" placeholder="<?php echo esc_attr__( 'One IP or CIDR per line', 'karetaker' ); ?>"><?php echo esc_textarea( $karetaker_proxies ); ?></textarea><span class="hint"><?php echo esc_html__( 'Used to read the real visitor IP behind a CDN or load balancer.', 'karetaker' ); ?></span></div>
			<div class="lbl"><?php echo esc_html__( 'Ignore list', 'karetaker' ); ?></div>
			<div class="ctl">
				<?php if ( ! $karetaker_ignored ) : ?>
					<span class="hint"><?php echo esc_html__( 'Nothing marked as expected.', 'karetaker' ); ?></span>
				<?php endif; ?>
				<?php foreach ( $karetaker_ignored as $karetaker_row ) : ?>
					<div class="inline"><span><?php echo esc_html( $karetaker_row['title'] ); ?></span>
					<?php
					if ( '' !== $karetaker_row['detail'] ) :
						?>
						<code><?php echo esc_html( wp_trim_words( $karetaker_row['detail'], 6 ) ); ?></code><?php endif; ?><span class="chip amber"><?php echo esc_html__( 'Expected', 'karetaker' ); ?></span><button type="button" class="btn ghost xs" data-kt-unmark="<?php echo esc_attr( (string) $karetaker_row['event_id'] ); ?>"><?php echo esc_html__( 'Remove', 'karetaker' ); ?></button></div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<div class="panel">
		<header><h2><?php echo esc_html__( 'Emergency off switch', 'karetaker' ); ?></h2></header>
		<div class="pad muted"><?php echo esc_html__( 'Add', 'karetaker' ); ?> <code>define( 'KARETAKER_DISABLE', true );</code> <?php echo esc_html__( 'to wp-config.php. Karetaker stops all checks and protections immediately.', 'karetaker' ); ?></div>
	</div>
	<div class="panel">
		<header><h2><?php echo esc_html__( 'Command line', 'karetaker' ); ?></h2></header>
		<div class="pad"><div class="diff">wp karetaker status
wp karetaker scan
wp karetaker log --limit=50 --severity=2
wp karetaker incident</div></div>
	</div>
<?php endif; ?>
</section>
