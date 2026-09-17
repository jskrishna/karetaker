<?php
/**
 * Advanced: Monitoring — files, users & sessions, plugin risk, scripts & SEO, activity log.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karetaker_views = array(
	'files'   => __( 'Files', 'karetaker' ),
	'users'   => __( 'Users & sessions', 'karetaker' ),
	'plugins' => __( 'Plugin risk', 'karetaker' ),
	'scripts' => __( 'Scripts & SEO', 'karetaker' ),
	'log'     => __( 'Activity log', 'karetaker' ),
);
if ( ! current_user_can( 'karetaker_view_log' ) ) {
	unset( $karetaker_views['log'] );
}
$karetaker_view  = self::current_view( array_keys( $karetaker_views ) );
$karetaker_state = Karetaker_Scanner::state();
$karetaker_open  = array();
foreach ( Karetaker_Issues::all( array( 'status' => 'active' ) ) as $karetaker_issue ) {
	$karetaker_open[ $karetaker_issue['code'] ][] = $karetaker_issue;
}
$karetaker_count = static function ( $codes ) use ( $karetaker_open ) {
	$n = 0;
	foreach ( (array) $codes as $code ) {
		$n += isset( $karetaker_open[ $code ] ) ? count( $karetaker_open[ $code ] ) : 0;
	}
	return $n;
};
?>
<section data-page="monitoring">
	<div class="subnav seg" style="margin-bottom:14px">
		<?php foreach ( $karetaker_views as $karetaker_key => $karetaker_label ) : ?>
			<a href="<?php echo esc_url( self::admin_page_url( 'monitoring', array( 'view' => $karetaker_key ) ) ); ?>"<?php echo $karetaker_key === $karetaker_view ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $karetaker_label ); ?></a>
		<?php endforeach; ?>
	</div>

<?php
if ( 'files' === $karetaker_view ) :
	$karetaker_files   = Karetaker_Admin_Watch::files( $karetaker_state );
	$karetaker_changed = count(
		array_filter(
			$karetaker_files['critical'],
			static function ( $row ) {
				return 'act' === $row['chip'];
			}
		)
	);
	$karetaker_core    = $karetaker_files['core_stats'];
	$karetaker_core_at = $karetaker_files['core_verified'] ? Karetaker_Admin_Data::when( gmdate( 'Y-m-d H:i:s', $karetaker_files['core_verified'] ) ) : __( 'Not yet', 'karetaker' );
	$karetaker_plugins = Karetaker_Admin_Watch::plugins( $karetaker_state );
	$karetaker_checks  = array();
	foreach ( Karetaker_Admin_Data::checks() as $karetaker_row ) {
		$karetaker_checks[ $karetaker_row['name'] ] = $karetaker_row;
	}
	$karetaker_mu = isset( $karetaker_checks[ __( 'Must-use plugins', 'karetaker' ) ] ) ? $karetaker_checks[ __( 'Must-use plugins', 'karetaker' ) ] : array(
		'value' => '—',
		'level' => '',
	);
	?>
	<div class="phead"><div><h1><?php echo esc_html__( 'File integrity', 'karetaker' ); ?></h1><p><?php echo esc_html__( 'Compared with official WordPress.org checksums and your own baseline. Karetaker stores fingerprints, never file contents.', 'karetaker' ); ?></p></div></div>
	<div class="panel">
		<header><h2><?php echo esc_html__( 'Critical files', 'karetaker' ); ?></h2><span class="grow"></span>
			<?php if ( $karetaker_changed ) : ?>
				<?php /* translators: %d: number of changed files */ ?>
				<span class="chip bad"><?php echo esc_html( sprintf( _n( '%d changed', '%d changed', $karetaker_changed, 'karetaker' ), $karetaker_changed ) ); ?></span>
			<?php else : ?>
				<span class="chip ok"><?php echo esc_html__( 'No changes', 'karetaker' ); ?></span>
			<?php endif; ?>
		</header>
		<div class="tw"><table>
			<thead><tr><th><?php echo esc_html__( 'File', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Status', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Size', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Changed', 'karetaker' ); ?></th><th></th></tr></thead>
			<tbody>
				<?php foreach ( $karetaker_files['critical'] as $karetaker_row ) : ?>
					<tr>
						<td><code><?php echo esc_html( $karetaker_row['path'] ); ?></code></td>
						<td><span class="chip <?php echo esc_attr( self::chip_class( $karetaker_row['chip'] ) ); ?>"><?php echo esc_html( 'act' === $karetaker_row['chip'] ? __( 'Changed outside WordPress', 'karetaker' ) : ( 'safe' === $karetaker_row['chip'] ? __( 'Matches baseline', 'karetaker' ) : $karetaker_row['status'] ) ); ?></span></td>
						<td class="num"><?php echo esc_html( $karetaker_row['size'] ); ?></td>
						<td class="num"><?php echo esc_html( $karetaker_row['modified'] ); ?></td>
						<td>
						<?php
						if ( $karetaker_row['event_id'] && current_user_can( 'manage_options' ) ) :
							?>
							<button type="button" class="btn xs" data-kt-issue="expected" data-id="<?php echo esc_attr( (string) $karetaker_row['event_id'] ); ?>"><?php echo esc_html__( 'Mark as expected', 'karetaker' ); ?></button><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table></div>
	</div>
	<div class="g2">
		<div class="panel">
			<header><h2><?php echo esc_html__( 'WordPress core', 'karetaker' ); ?></h2><span class="grow"></span><span class="chip <?php echo ! empty( $karetaker_core['modified'] ) ? 'bad' : 'ok'; ?>"><?php echo esc_html( $karetaker_files['wp_version'] ); ?></span></header>
			<ul class="cov">
				<li><i class="dot"></i><span><?php echo esc_html__( 'Files verified', 'karetaker' ); ?></span><span class="v num"><?php echo esc_html( isset( $karetaker_core['checked'] ) ? number_format_i18n( (int) $karetaker_core['checked'] ) : '—' ); ?></span><span class="t"><?php echo esc_html( $karetaker_core_at ); ?></span></li>
				<li><i class="dot <?php echo ! empty( $karetaker_core['modified'] ) ? 'bad' : ''; ?>"></i><span><?php echo esc_html__( 'Modified', 'karetaker' ); ?></span><span class="v num"><?php echo esc_html( isset( $karetaker_core['modified'] ) ? (string) (int) $karetaker_core['modified'] : '—' ); ?></span><span class="t"><?php echo esc_html( $karetaker_core_at ); ?></span></li>
				<li><i class="dot <?php echo ! empty( $karetaker_core['unknown'] ) ? 'bad' : ''; ?>"></i><span><?php echo esc_html__( 'Unknown files in wp-admin / wp-includes', 'karetaker' ); ?></span><span class="v num"><?php echo esc_html( isset( $karetaker_core['unknown'] ) ? (string) (int) $karetaker_core['unknown'] : '—' ); ?></span><span class="t"><?php echo esc_html( $karetaker_core_at ); ?></span></li>
				<li><i class="dot info"></i><span><?php echo esc_html__( 'Managed by host', 'karetaker' ); ?></span><span class="v num"><?php echo esc_html( isset( $karetaker_core['host_managed'] ) ? (string) (int) $karetaker_core['host_managed'] : '—' ); ?></span><span class="t"><?php echo esc_html__( 'Labelled', 'karetaker' ); ?></span></li>
			</ul>
		</div>
		<div class="panel">
			<header><h2><?php echo esc_html__( 'Uploads & MU-plugins', 'karetaker' ); ?></h2></header>
			<ul class="cov">
				<li><i class="dot <?php echo $karetaker_files['uploads_open'] ? 'bad' : ''; ?>"></i><span><?php echo esc_html__( 'Code files in uploads', 'karetaker' ); ?></span><span class="v num">
					<?php
					if ( ! $karetaker_files['uploads_seen'] ) {
						echo esc_html__( 'Not checked yet', 'karetaker' );
					} elseif ( $karetaker_files['uploads_total'] ) {
						/* translators: 1: code files found, 2: files checked */
						echo esc_html( sprintf( __( '%1$s of %2$s', 'karetaker' ), number_format_i18n( $karetaker_files['uploads_count'] ), number_format_i18n( $karetaker_files['uploads_total'] ) ) );
					} else {
						echo esc_html( number_format_i18n( $karetaker_files['uploads_count'] ) );
					}
					?>
				</span><span class="t"></span></li>
				<li><i class="dot <?php echo esc_attr( $karetaker_mu['level'] ); ?>"></i><span><?php echo esc_html__( 'MU-plugins', 'karetaker' ); ?></span><span class="v"><?php echo esc_html( $karetaker_mu['value'] ); ?></span><span class="t"></span></li>
				<li><i class="dot <?php echo $karetaker_count( 'silent_plugin_found' ) ? 'bad' : ''; ?>"></i><span><?php echo esc_html__( 'Plugins without an install event', 'karetaker' ); ?></span><span class="v num"><?php echo esc_html( (string) $karetaker_count( 'silent_plugin_found' ) ); ?></span><span class="t"></span></li>
				<li><i class="dot <?php echo $karetaker_count( 'hidden_plugin_found' ) ? 'bad' : ''; ?>"></i><span><?php echo esc_html__( 'Plugins hidden from the Plugins screen', 'karetaker' ); ?></span><span class="v num"><?php echo esc_html( (string) $karetaker_count( 'hidden_plugin_found' ) ); ?></span><span class="t"></span></li>
			</ul>
		</div>
	</div>
	<?php if ( $karetaker_files['mismatch'] || $karetaker_files['uploads'] ) : ?>
		<div class="panel">
			<header><h2><?php echo esc_html__( 'Files that need a look', 'karetaker' ); ?></h2></header>
			<div class="tw"><table>
				<thead><tr><th><?php echo esc_html__( 'File', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Where', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Status', 'karetaker' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $karetaker_files['mismatch'] as $karetaker_row ) : ?>
						<tr><td><code><?php echo esc_html( $karetaker_row['file'] ); ?></code></td><td><?php echo esc_html( 'core' === $karetaker_row['subject'] ? 'WordPress core' : $karetaker_row['subject'] ); ?></td><td><span class="chip bad"><?php echo esc_html__( 'Doesn\'t match official copy', 'karetaker' ); ?></span></td></tr>
					<?php endforeach; ?>
					<?php foreach ( $karetaker_files['uploads'] as $karetaker_file ) : ?>
						<tr><td><code><?php echo esc_html( $karetaker_file ); ?></code></td><td><?php echo esc_html__( 'Uploads', 'karetaker' ); ?></td><td><span class="chip bad"><?php echo esc_html__( 'Code file in uploads', 'karetaker' ); ?></span></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table></div>
		</div>
	<?php endif; ?>
	<div class="panel">
		<header><h2><?php echo esc_html__( 'Plugins & themes', 'karetaker' ); ?></h2><span class="sub"><?php echo esc_html__( 'Checked a few per run, round-robin', 'karetaker' ); ?></span></header>
		<div class="tw"><table>
			<thead><tr><th><?php echo esc_html__( 'Component', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Version', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Source', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Files', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Last verified', 'karetaker' ); ?></th></tr></thead>
			<tbody>
				<?php
				$karetaker_dir      = isset( $karetaker_state['plugin_directory'] ) && is_array( $karetaker_state['plugin_directory'] ) ? $karetaker_state['plugin_directory'] : array();
				$karetaker_source   = static function ( $slug ) use ( $karetaker_dir ) {
					$status = isset( $karetaker_dir[ $slug ]['status'] ) ? (string) $karetaker_dir[ $slug ]['status'] : '';
					if ( '' === $status ) {
						return '—';
					}
					return 'not_found' === $status ? __( 'Premium or custom', 'karetaker' ) : 'WordPress.org';
				};
				$karetaker_verified = isset( $karetaker_state['plugin_verified'] ) && is_array( $karetaker_state['plugin_verified'] ) ? $karetaker_state['plugin_verified'] : array();
	foreach ( $karetaker_plugins['rows'] as $karetaker_row ) :
		$karetaker_at = isset( $karetaker_verified[ $karetaker_row['slug'] ]['at'] ) ? Karetaker_Admin_Data::when( gmdate( 'Y-m-d H:i:s', (int) $karetaker_verified[ $karetaker_row['slug'] ]['at'] ) ) : '—';
		?>
					<tr><td><?php echo esc_html( $karetaker_row['name'] ); ?>
					<?php
					if ( ! $karetaker_row['active'] ) :
						?>
						<span class="s"><?php echo esc_html__( 'Inactive', 'karetaker' ); ?></span><?php endif; ?></td><td class="num"><?php echo esc_html( $karetaker_row['version'] ); ?></td><td><?php echo esc_html( $karetaker_source( $karetaker_row['slug'] ) ); ?></td><td><span class="chip <?php echo esc_attr( self::chip_class( $karetaker_row['fchip'] ) ); ?>"><?php echo esc_html( '—' === $karetaker_row['files'] ? __( 'Not checked', 'karetaker' ) : $karetaker_row['files'] ); ?></span></td><td class="num"><?php echo esc_html( $karetaker_at ); ?></td></tr>
				<?php endforeach; ?>
				<?php foreach ( wp_get_themes() as $karetaker_theme ) : ?>
					<tr><td><?php echo esc_html( $karetaker_theme->get( 'Name' ) ); ?><span class="s"><?php echo get_stylesheet() === $karetaker_theme->get_stylesheet() ? esc_html__( 'Active theme', 'karetaker' ) : esc_html__( 'Theme', 'karetaker' ); ?></span></td><td class="num"><?php echo esc_html( (string) $karetaker_theme->get( 'Version' ) ); ?></td><td>—</td><td><span class="chip mute"><?php echo esc_html__( 'Not checked', 'karetaker' ); ?></span></td><td>—</td></tr>
				<?php endforeach; ?>
			</tbody>
		</table></div>
	</div>

	<?php
elseif ( 'users' === $karetaker_view ) :
	$karetaker_people   = Karetaker_Admin_Watch::people( $karetaker_state );
	$karetaker_sessions = Karetaker_Admin_Watch::sessions();
	$karetaker_hidden   = array_values(
		array_filter(
			$karetaker_people,
			static function ( $row ) {
				return $row['hidden'];
			}
		)
	);
	$karetaker_fails    = get_option( 'karetaker_login_failures', array() );
	$karetaker_fails    = is_array( $karetaker_fails ) ? $karetaker_fails : array();
	$karetaker_roster   = isset( $karetaker_state['admin_roster'] ) && is_array( $karetaker_state['admin_roster'] ) ? count( $karetaker_state['admin_roster'] ) : 0;
	?>
	<div class="phead"><div><h1><?php echo esc_html__( 'Users & sessions', 'karetaker' ); ?></h1><p><?php echo esc_html__( 'Administrators are read directly from the database, so accounts hidden from the Users screen still appear.', 'karetaker' ); ?></p></div></div>
	<?php if ( $karetaker_hidden ) : ?>
		<div class="notice-kt bad"><div class="grow"><b>
			<?php
			/* translators: %d: number of hidden administrators */
			echo esc_html( sprintf( _n( '%d hidden administrator.', '%d hidden administrators.', count( $karetaker_hidden ), 'karetaker' ), count( $karetaker_hidden ) ) );
			?>
		</b> <span class="muted">
			<?php
			/* translators: %s: user login */
			echo esc_html( sprintf( __( '%s exists in the database but is filtered out of Users → All Users.', 'karetaker' ), $karetaker_hidden[0]['login'] ) );
			?>
		</span></div>
			<?php if ( isset( $karetaker_open['hidden_admin_found'][0] ) ) : ?>
				<button type="button" class="btn xs" data-open="<?php echo esc_attr( self::expose_issue( $karetaker_open['hidden_admin_found'][0] ) ); ?>"><?php echo esc_html__( 'Details', 'karetaker' ); ?></button>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<div class="panel">
		<header><h2><?php echo esc_html__( 'Administrators', 'karetaker' ); ?></h2><span class="grow"></span>
			<?php if ( $karetaker_roster ) : ?>
				<?php /* translators: %d: accounts in the baseline */ ?>
				<span class="chip mute"><?php echo esc_html( sprintf( _n( 'Roster baseline: %d account', 'Roster baseline: %d accounts', $karetaker_roster, 'karetaker' ), $karetaker_roster ) ); ?></span>
			<?php endif; ?>
		</header>
		<div class="tw"><table>
			<thead><tr><th><?php echo esc_html__( 'User', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Status', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Created', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Created by', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Last login', 'karetaker' ); ?></th><th><?php echo esc_html__( '2FA', 'karetaker' ); ?></th><th></th></tr></thead>
			<tbody>
				<?php foreach ( $karetaker_people as $karetaker_row ) : ?>
					<tr>
						<td><b><?php echo esc_html( $karetaker_row['login'] ); ?></b><span class="s"><?php echo esc_html( $karetaker_row['email'] ); ?></span></td>
						<td><span class="chip <?php echo esc_attr( self::chip_class( $karetaker_row['chip'] ) ); ?>"><?php echo esc_html( $karetaker_row['status'] ); ?></span></td>
						<td class="num"><?php echo esc_html( $karetaker_row['added'] ); ?></td>
						<td><?php echo esc_html( $karetaker_row['created_by'] ); ?></td>
						<td class="num"><?php echo esc_html( $karetaker_row['last'] ); ?></td>
						<td>
							<?php if ( 'on' === $karetaker_row['twofa'] ) : ?>
								<span class="chip ok"><?php echo esc_html__( 'On', 'karetaker' ); ?></span>
							<?php elseif ( 'off' === $karetaker_row['twofa'] ) : ?>
								<span class="chip warn"><?php echo esc_html__( 'Off', 'karetaker' ); ?></span>
							<?php else : ?>
								<span class="chip mute"><?php echo esc_html__( 'Unknown', 'karetaker' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( '' !== $karetaker_row['edit'] && 'safe' !== $karetaker_row['chip'] ) : ?>
								<a class="btn xs" href="<?php echo esc_url( $karetaker_row['edit'] ); ?>"><?php echo esc_html__( 'Review', 'karetaker' ); ?></a>
							<?php elseif ( $karetaker_row['hidden'] && isset( $karetaker_open['hidden_admin_found'][0] ) ) : ?>
								<button type="button" class="btn xs danger" data-open="<?php echo esc_attr( self::expose_issue( $karetaker_open['hidden_admin_found'][0] ) ); ?>"><?php echo esc_html__( 'Review', 'karetaker' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table></div>
		<footer><?php echo esc_html__( '2FA status is read from the Two Factor plugin when installed. Karetaker does not provide 2FA.', 'karetaker' ); ?></footer>
	</div>
	<div class="g2">
		<div class="panel">
			<header><h2><?php echo esc_html__( 'Active admin sessions', 'karetaker' ); ?></h2><span class="grow"></span><button type="button" class="btn xs danger" data-kt-sessions="all"><?php echo esc_html__( 'Sign out all others', 'karetaker' ); ?></button></header>
			<div class="tw"><table>
				<thead><tr><th><?php echo esc_html__( 'User', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Device', 'karetaker' ); ?></th><th><?php echo esc_html__( 'IP', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Started', 'karetaker' ); ?></th><th></th></tr></thead>
				<tbody>
					<?php if ( ! $karetaker_sessions['rows'] ) : ?>
						<tr><td colspan="5"><div class="empty"><?php echo esc_html__( 'No active sessions.', 'karetaker' ); ?></div></td></tr>
					<?php endif; ?>
					<?php foreach ( $karetaker_sessions['rows'] as $karetaker_row ) : ?>
						<tr>
							<td><?php echo esc_html( $karetaker_row['login'] ); ?></td>
							<td><?php echo esc_html( $karetaker_row['device'] ); ?></td>
							<td class="num"><?php echo esc_html( '' !== $karetaker_row['ip'] ? $karetaker_row['ip'] : '—' ); ?></td>
							<td class="num"><?php echo esc_html( $karetaker_row['when'] ); ?></td>
							<td>
								<?php if ( $karetaker_row['current'] ) : ?>
									<span class="chip mute"><?php echo esc_html__( 'You', 'karetaker' ); ?></span>
								<?php elseif ( '' !== $karetaker_row['verifier'] ) : ?>
									<button type="button" class="btn xs" data-kt-sessions="one" data-user="<?php echo esc_attr( (string) $karetaker_row['user_id'] ); ?>" data-verifier="<?php echo esc_attr( $karetaker_row['verifier'] ); ?>"><?php echo esc_html__( 'Sign out', 'karetaker' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table></div>
		</div>
		<div class="panel">
			<?php
			$karetaker_bursts = 0;
			$karetaker_other  = 0;
			$karetaker_rows   = array();
			foreach ( $karetaker_fails as $karetaker_ip => $karetaker_row ) {
				if ( time() - (int) $karetaker_row['last'] > DAY_IN_SECONDS ) {
					continue;
				}
				if ( (int) $karetaker_row['count'] >= Karetaker_Hooks::BURST_THRESHOLD ) {
					++$karetaker_bursts;
					$karetaker_rows[ $karetaker_ip ] = $karetaker_row;
				} else {
					$karetaker_other += (int) $karetaker_row['count'];
				}
			}
			?>
			<header><h2><?php echo esc_html__( 'Failed logins, 24 hours', 'karetaker' ); ?></h2><span class="grow"></span>
				<?php if ( $karetaker_bursts ) : ?>
					<?php /* translators: %d: number of bursts */ ?>
					<span class="chip warn"><?php echo esc_html( sprintf( _n( '%d burst', '%d bursts', $karetaker_bursts, 'karetaker' ), $karetaker_bursts ) ); ?></span>
				<?php else : ?>
					<span class="chip ok"><?php echo esc_html__( 'Normal', 'karetaker' ); ?></span>
				<?php endif; ?>
			</header>
			<ul class="cov">
				<?php foreach ( $karetaker_rows as $karetaker_ip => $karetaker_row ) : ?>
					<li><i class="dot warn"></i><span>
						<?php
						/* translators: 1: IP, 2: attempts, 3: minutes */
						echo esc_html( sprintf( __( '%1$s · %2$d attempts in %3$d min', 'karetaker' ), $karetaker_ip, (int) $karetaker_row['count'], max( 1, (int) round( ( (int) $karetaker_row['last'] - (int) $karetaker_row['first'] ) / 60 ) ) ) );
						?>
					</span><span class="v">
						<?php
						/* translators: %s: username tried */
						echo esc_html( sprintf( __( 'user: %s', 'karetaker' ), isset( $karetaker_row['user'] ) ? $karetaker_row['user'] : '' ) );
						?>
					</span><span class="t"><?php echo esc_html( wp_date( (string) get_option( 'time_format' ), (int) $karetaker_row['first'] ) ); ?></span></li>
				<?php endforeach; ?>
				<li><i class="dot mute"></i><span>
					<?php
					/* translators: %d: attempts */
					echo esc_html( sprintf( _n( 'Other IPs · %d attempt', 'Other IPs · %d attempts', $karetaker_other, 'karetaker' ), $karetaker_other ) );
					?>
				</span><span class="v"><?php echo esc_html__( 'below threshold', 'karetaker' ); ?></span><span class="t"><?php echo esc_html__( '24h', 'karetaker' ); ?></span></li>
			</ul>
			<footer><?php echo esc_html__( 'Karetaker records attempts and alerts on bursts. It never blocks logins, so you can\'t be locked out.', 'karetaker' ); ?></footer>
		</div>
	</div>

	<?php
elseif ( 'plugins' === $karetaker_view ) :
	$karetaker_plugins  = Karetaker_Admin_Watch::plugins( $karetaker_state );
	$karetaker_dir      = isset( $karetaker_state['plugin_directory'] ) && is_array( $karetaker_state['plugin_directory'] ) ? $karetaker_state['plugin_directory'] : array();
	$karetaker_receipts = Karetaker_Receipts::all();
	$karetaker_vuln_on  = (bool) Karetaker_Settings::get( 'vuln_lookup_enabled' );
	?>
	<div class="phead"><div><h1><?php echo esc_html__( 'Plugin risk', 'karetaker' ); ?></h1><p><?php echo esc_html__( 'Signals from the public WordPress.org plugin API, checked once a day. No information about your site is sent.', 'karetaker' ); ?></p></div></div>
	<div class="panel">
		<div class="tw"><table>
			<thead><tr><th><?php echo esc_html__( 'Plugin', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Installed', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Signal', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Detail', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Last release', 'karetaker' ); ?></th><th></th></tr></thead>
			<tbody>
				<?php
				foreach ( $karetaker_plugins['rows'] as $karetaker_row ) :
					$karetaker_d       = isset( $karetaker_dir[ $karetaker_row['slug'] ] ) ? $karetaker_dir[ $karetaker_row['slug'] ] : array();
					$karetaker_release = ! empty( $karetaker_d['last_updated'] ) ? wp_date( 'M Y', (int) strtotime( (string) $karetaker_d['last_updated'] ) ) : '—';
					$karetaker_detail  = $karetaker_row['why'];
					if ( ! empty( $karetaker_d['closed_date'] ) ) {
						/* translators: 1: date, 2: reason */
						$karetaker_detail = trim( sprintf( __( 'Closed %1$s · %2$s', 'karetaker' ), wp_date( (string) get_option( 'date_format' ), (int) strtotime( (string) $karetaker_d['closed_date'] ) ), isset( $karetaker_d['reason'] ) ? (string) $karetaker_d['reason'] : '' ), ' ·' );
					}
					?>
					<tr>
						<td><b><?php echo esc_html( $karetaker_row['name'] ); ?></b>
						<?php
						if ( ! $karetaker_row['active'] ) :
							?>
							<span class="s"><?php echo esc_html__( 'Inactive', 'karetaker' ); ?></span><?php endif; ?></td>
						<td class="num"><?php echo esc_html( $karetaker_row['version'] ); ?></td>
						<td><span class="chip <?php echo esc_attr( self::chip_class( $karetaker_row['chip'] ) ); ?>"><?php echo esc_html( 'safe' === $karetaker_row['chip'] ? __( 'No signals', 'karetaker' ) : $karetaker_row['risk'] ); ?></span></td>
						<td><?php echo esc_html( 'safe' === $karetaker_row['chip'] ? '—' : $karetaker_detail ); ?></td>
						<td class="num"><?php echo esc_html( $karetaker_release ); ?></td>
						<td>
							<?php if ( 'act' === $karetaker_row['chip'] ) : ?>
								<a class="btn xs" href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=search&s=' . rawurlencode( $karetaker_row['name'] ) ) ); ?>"><?php echo esc_html__( 'Find alternatives', 'karetaker' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table></div>
	</div>
	<div class="panel">
		<header><h2><?php echo esc_html__( 'Update receipts', 'karetaker' ); ?></h2><span class="sub"><?php echo esc_html__( 'What changed in each plugin update', 'karetaker' ); ?></span></header>
		<div class="tw"><table>
			<thead><tr><th><?php echo esc_html__( 'Update', 'karetaker' ); ?></th><th><?php echo esc_html__( 'When', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Files changed', 'karetaker' ); ?></th><th><?php echo esc_html__( 'New outbound domains', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Risky functions added', 'karetaker' ); ?></th><th></th></tr></thead>
			<tbody>
				<?php if ( ! $karetaker_receipts ) : ?>
					<tr><td colspan="6"><div class="empty"><b><?php echo esc_html__( 'No updates recorded yet.', 'karetaker' ); ?></b><?php echo esc_html__( 'The next plugin update you install from the dashboard gets a receipt.', 'karetaker' ); ?></div></td></tr>
				<?php endif; ?>
				<?php foreach ( $karetaker_receipts as $karetaker_i => $karetaker_r ) : ?>
					<tr>
						<td><?php echo esc_html( $karetaker_r['name'] . ' ' . $karetaker_r['from'] . ' → ' . $karetaker_r['to'] ); ?></td>
						<td class="num"><?php echo esc_html( Karetaker_Admin_Data::when( gmdate( 'Y-m-d H:i:s', (int) $karetaker_r['at'] ) ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $karetaker_r['changed'] ) . ( ! empty( $karetaker_r['truncated'] ) ? '+' : '' ) ); ?></td>
						<td>
							<?php if ( $karetaker_r['hosts'] ) : ?>
								<span class="chip warn"><?php echo esc_html( count( $karetaker_r['hosts'] ) . ' · ' . implode( ', ', array_slice( $karetaker_r['hosts'], 0, 2 ) ) ); ?></span>
							<?php else : ?>
								<span class="chip ok">0</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $karetaker_r['risky'] ) : ?>
								<?php foreach ( $karetaker_r['risky'] as $karetaker_fn => $karetaker_n ) : ?>
									<span class="chip warn"><?php echo esc_html( $karetaker_fn . ' ×' . (int) $karetaker_n ); ?></span>
								<?php endforeach; ?>
							<?php else : ?>
								<span class="chip ok">0</span>
							<?php endif; ?>
						</td>
						<td>
						<?php
						if ( $karetaker_r['lines'] ) :
							?>
							<button type="button" class="btn xs" data-kt-toggle="receipt-<?php echo esc_attr( (string) $karetaker_i ); ?>"><?php echo esc_html__( 'View', 'karetaker' ); ?></button><?php endif; ?></td>
					</tr>
					<?php if ( $karetaker_r['lines'] ) : ?>
						<tr id="receipt-<?php echo esc_attr( (string) $karetaker_i ); ?>" hidden><td colspan="6"><div class="diff">
						<?php
						foreach ( $karetaker_r['lines'] as $karetaker_line ) :
							?>
							<span class="a">+ <?php echo esc_html( $karetaker_line ); ?></span>
<?php endforeach; ?></div></td></tr>
					<?php endif; ?>
				<?php endforeach; ?>
			</tbody>
		</table></div>
		<footer><?php echo esc_html__( 'Receipts count patterns; they are not a malware verdict.', 'karetaker' ); ?></footer>
	</div>
	<div class="panel">
		<header><h2><?php echo esc_html__( 'Vulnerability lookup', 'karetaker' ); ?></h2><span class="grow"></span><span class="chip <?php echo $karetaker_vuln_on ? 'ok' : 'mute'; ?>"><?php echo $karetaker_vuln_on ? esc_html__( 'On', 'karetaker' ) : esc_html__( 'Off', 'karetaker' ); ?></span></header>
		<div class="pad inline"><span class="muted"><?php echo esc_html__( 'Match installed plugins against the public WPVulnerability list. Only plugin names and versions are sent.', 'karetaker' ); ?></span><span class="grow" style="flex:1"></span><label class="switch"><input type="checkbox" data-kt-setting="vuln_lookup_enabled" data-reload="1"<?php checked( $karetaker_vuln_on ); ?> aria-label="<?php echo esc_attr__( 'Vulnerability lookup', 'karetaker' ); ?>"><span></span></label></div>
	</div>

	<?php
elseif ( 'scripts' === $karetaker_view ) :
	$karetaker_scripts = Karetaker_Admin_Watch::scripts( $karetaker_state );
	$karetaker_first   = isset( $karetaker_state['script_first_seen'] ) && is_array( $karetaker_state['script_first_seen'] ) ? $karetaker_state['script_first_seen'] : array();
	$karetaker_sources = isset( $karetaker_state['script_sources'] ) && is_array( $karetaker_state['script_sources'] ) ? $karetaker_state['script_sources'] : array();
	$karetaker_new     = count(
		array_filter(
			$karetaker_scripts['rows'],
			static function ( $row ) {
				return 'check' === $row['chip'];
			}
		)
	);
	$karetaker_cloak   = $karetaker_scripts['cloaking'];
	$karetaker_pages   = isset( $karetaker_cloak['pages'] ) && is_array( $karetaker_cloak['pages'] ) ? $karetaker_cloak['pages'] : array();
	$karetaker_labels  = array(
		'home' => __( 'Home', 'karetaker' ),
		'shop' => __( 'Shop', 'karetaker' ),
	);
	?>
	<div class="phead"><div><h1><?php echo esc_html__( 'Scripts & SEO', 'karetaker' ); ?></h1><p><?php echo esc_html__( 'External JavaScript sources and what search engines see. Catches checkout skimmers and cloaked spam.', 'karetaker' ); ?></p></div></div>
	<div class="panel">
		<header><h2><?php echo esc_html__( 'External script domains', 'karetaker' ); ?></h2><span class="grow"></span>
			<?php if ( $karetaker_new ) : ?>
				<?php /* translators: %d: number of new domains */ ?>
				<span class="chip warn"><?php echo esc_html( sprintf( _n( '%d new domain', '%d new domains', $karetaker_new, 'karetaker' ), $karetaker_new ) ); ?></span>
			<?php else : ?>
				<span class="chip ok"><?php echo esc_html__( 'No new domains', 'karetaker' ); ?></span>
			<?php endif; ?>
		</header>
		<div class="tw"><table>
			<thead><tr><th><?php echo esc_html__( 'Domain', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Loaded on', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Found in', 'karetaker' ); ?></th><th><?php echo esc_html__( 'First seen', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Status', 'karetaker' ); ?></th></tr></thead>
			<tbody>
				<?php if ( ! $karetaker_scripts['rows'] ) : ?>
					<tr><td colspan="5"><div class="empty"><b><?php echo $karetaker_scripts['seen'] ? esc_html__( 'No external scripts found.', 'karetaker' ) : esc_html__( 'Not checked yet.', 'karetaker' ); ?></b><?php echo esc_html__( 'Karetaker reads your pages on each scheduled check.', 'karetaker' ); ?></div></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $karetaker_scripts['rows'] as $karetaker_row ) :
					$karetaker_src   = isset( $karetaker_sources[ $karetaker_row['host'] ] ) ? (array) $karetaker_sources[ $karetaker_row['host'] ] : array();
					$karetaker_found = array();
					$karetaker_on    = array();
					foreach ( $karetaker_src as $karetaker_s ) {
						if ( 0 === strpos( (string) $karetaker_s, 'option:' ) ) {
							/* translators: %s: option name */
							$karetaker_found[] = sprintf( __( 'Setting: %s', 'karetaker' ), substr( (string) $karetaker_s, 7 ) );
						} else {
							$karetaker_on[] = (string) $karetaker_s;
						}
					}
					?>
					<tr>
						<td><code><?php echo esc_html( $karetaker_row['host'] ); ?></code></td>
						<td><?php echo esc_html( $karetaker_on ? $karetaker_row['where'] : ( $karetaker_found ? __( 'All pages', 'karetaker' ) : $karetaker_row['where'] ) ); ?></td>
						<td><?php echo esc_html( $karetaker_found ? implode( ', ', $karetaker_found ) : __( 'Page source', 'karetaker' ) ); ?></td>
						<td class="num"><?php echo esc_html( isset( $karetaker_first[ $karetaker_row['host'] ] ) ? wp_date( 'M Y', (int) $karetaker_first[ $karetaker_row['host'] ] ) : '—' ); ?></td>
						<td>
							<span class="chip <?php echo 'check' === $karetaker_row['chip'] ? 'warn' : 'ok'; ?>"><?php echo 'check' === $karetaker_row['chip'] ? esc_html__( 'New', 'karetaker' ) : esc_html__( 'Baseline', 'karetaker' ); ?></span>
							<?php if ( $karetaker_row['event_id'] && current_user_can( 'manage_options' ) ) : ?>
								<button type="button" class="btn xs" data-kt-issue="expected" data-id="<?php echo esc_attr( (string) $karetaker_row['event_id'] ); ?>"><?php echo esc_html__( 'Mark as expected', 'karetaker' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table></div>
		<footer><?php echo esc_html__( 'Sources checked: scripts on the home page, cart and checkout, plus header and footer code saved in settings.', 'karetaker' ); ?></footer>
	</div>
	<div class="panel">
		<header><h2><?php echo esc_html__( 'Search engine view', 'karetaker' ); ?></h2><span class="grow"></span>
			<?php if ( empty( $karetaker_cloak['status'] ) ) : ?>
				<span class="chip mute"><?php echo esc_html__( 'Not checked yet', 'karetaker' ); ?></span>
			<?php elseif ( 'different' === $karetaker_cloak['status'] ) : ?>
				<span class="chip bad"><?php echo esc_html__( 'Different from visitor view', 'karetaker' ); ?></span>
			<?php elseif ( 'unavailable' === $karetaker_cloak['status'] ) : ?>
				<span class="chip mute"><?php echo esc_html__( 'Could not check', 'karetaker' ); ?></span>
			<?php else : ?>
				<span class="chip ok"><?php echo esc_html__( 'Matches visitor view', 'karetaker' ); ?></span>
			<?php endif; ?>
		</header>
		<div class="tw"><table>
			<thead><tr><th><?php echo esc_html__( 'Page', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Visitor', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Googlebot', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Difference', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Checked', 'karetaker' ); ?></th></tr></thead>
			<tbody>
				<?php if ( ! $karetaker_pages ) : ?>
					<tr><td colspan="5"><div class="empty"><?php echo esc_html__( 'Runs with the daily check.', 'karetaker' ); ?></div></td></tr>
				<?php endif; ?>
				<?php foreach ( $karetaker_pages as $karetaker_page => $karetaker_p ) : ?>
					<tr>
						<td><?php echo esc_html( isset( $karetaker_labels[ $karetaker_page ] ) ? $karetaker_labels[ $karetaker_page ] : $karetaker_page ); ?></td>
						<?php /* translators: 1: links, 2: domains */ ?>
						<td class="num"><?php echo esc_html( sprintf( __( '%1$d links · %2$d domains', 'karetaker' ), (int) $karetaker_p['visitor_links'], (int) $karetaker_p['visitor_domains'] ) ); ?></td>
						<?php /* translators: 1: links, 2: domains */ ?>
						<td class="num"><?php echo esc_html( sprintf( __( '%1$d links · %2$d domains', 'karetaker' ), (int) $karetaker_p['bot_links'], (int) $karetaker_p['bot_domains'] ) ); ?></td>
						<td>
							<?php if ( 'different' === $karetaker_p['status'] ) : ?>
								<span class="chip bad"><?php echo esc_html( implode( ', ', array_slice( (array) $karetaker_p['extra_hosts'], 0, 3 ) ) ); ?></span>
							<?php else : ?>
								<span class="chip ok"><?php echo esc_html__( 'None', 'karetaker' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="num"><?php echo esc_html( ! empty( $karetaker_cloak['at'] ) ? Karetaker_Admin_Data::when( gmdate( 'Y-m-d H:i:s', (int) $karetaker_cloak['at'] ) ) : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table></div>
	</div>

	<?php
else :
	include __DIR__ . '/log.php';
endif;
?>
</section>
