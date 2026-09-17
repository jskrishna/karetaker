<?php
/**
 * Advanced mode: issue/event drawer and the incident checklist dialog.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karetaker_owners = self::owners();
?>
<aside class="drawer" id="drawer" role="dialog" aria-modal="true" aria-labelledby="dTitle">
	<header>
		<div style="flex:1"><div id="dMeta"></div><h2 id="dTitle"></h2></div>
		<button type="button" class="btn ghost" id="dClose" aria-label="<?php echo esc_attr__( 'Close', 'karetaker' ); ?>">✕</button>
	</header>
	<div class="dtabs" role="tablist">
		<button type="button" role="tab" aria-selected="true" data-dt="guide"><?php echo esc_html__( 'What to do', 'karetaker' ); ?></button>
		<button type="button" role="tab" aria-selected="false" data-dt="details"><?php echo esc_html__( 'Details', 'karetaker' ); ?></button>
		<button type="button" role="tab" aria-selected="false" data-dt="timeline"><?php echo esc_html__( 'Timeline', 'karetaker' ); ?></button>
		<button type="button" role="tab" aria-selected="false" data-dt="raw"><?php echo esc_html__( 'Raw', 'karetaker' ); ?></button>
	</div>
	<div class="body">
		<div data-dp="guide"><p id="dWhat" style="margin:0"></p><p id="dWhy" class="muted" style="margin:8px 0 0"></p><h4><?php echo esc_html__( 'Steps', 'karetaker' ); ?></h4><ol id="dSteps"></ol><p id="dLink" style="margin-top:10px" hidden><a href="#"></a></p></div>
		<div data-dp="details" hidden><dl id="dDl"></dl></div>
		<div data-dp="timeline" hidden><ol class="tline" id="dTl"></ol></div>
		<div data-dp="raw" hidden><pre id="dRaw"></pre></div>
	</div>
	<footer>
		<label class="sr" for="dOwner"><?php echo esc_html__( 'Owner', 'karetaker' ); ?></label>
		<select id="dOwner" style="border:1px solid var(--line);border-radius:4px;padding:4px 6px">
			<option value="0"><?php echo esc_html__( 'Unassigned', 'karetaker' ); ?></option>
			<?php foreach ( $karetaker_owners as $karetaker_id => $karetaker_login ) : ?>
				<option value="<?php echo esc_attr( (string) $karetaker_id ); ?>"><?php echo esc_html( $karetaker_login ); ?></option>
			<?php endforeach; ?>
		</select>
		<span style="flex:1"></span>
		<button type="button" class="btn" id="dExpect"><?php echo esc_html__( 'Mark as expected', 'karetaker' ); ?></button>
		<button type="button" class="btn" id="dAck"><?php echo esc_html__( 'Acknowledge', 'karetaker' ); ?></button>
		<button type="button" class="btn" id="dReopen"><?php echo esc_html__( 'Reopen', 'karetaker' ); ?></button>
		<button type="button" class="btn primary" id="dResolve"><?php echo esc_html__( 'Resolve', 'karetaker' ); ?></button>
	</footer>
</aside>

<?php if ( current_user_can( 'karetaker_resolve' ) ) : ?>
	<?php
	$karetaker_steps = Karetaker_Cases::steps();
	$karetaker_links = array(
		'backup'  => 'https://developer.wordpress.org/advanced-administration/security/backup/',
		'users'   => self::admin_page_url( 'monitoring', array( 'view' => 'users' ) ),
		'files'   => self::admin_page_url( 'monitoring', array( 'view' => 'files' ) ),
		'tokens'  => self::admin_page_url( 'access' ),
		'plugins' => self::admin_page_url( 'monitoring', array( 'view' => 'plugins' ) ),
	);
	?>
	<div class="modal" id="modal" role="dialog" aria-modal="true" aria-labelledby="mTitle">
		<div class="dialog">
			<header><h2 id="mTitle"></h2><span id="mChip"></span><button type="button" class="btn ghost" id="mClose" aria-label="<?php echo esc_attr__( 'Close', 'karetaker' ); ?>">✕</button></header>
			<div class="progress"><i id="mBar"></i></div>
			<ol class="steps" id="mSteps">
				<?php
				$karetaker_n = 0;
				foreach ( $karetaker_steps as $karetaker_key => $karetaker_step ) :
					++$karetaker_n;
					?>
					<li data-key="<?php echo esc_attr( $karetaker_key ); ?>"><span class="n" data-n="<?php echo esc_attr( (string) $karetaker_n ); ?>"><?php echo esc_html( (string) $karetaker_n ); ?></span><div><h3><?php echo esc_html( $karetaker_step[0] ); ?></h3><p><?php echo esc_html( $karetaker_step[1] ); ?></p></div>
						<div class="inline">
							<?php if ( 'sessions' === $karetaker_step[3] ) : ?>
								<button type="button" class="btn xs" data-kt-sessions="all"><?php echo esc_html( $karetaker_step[2] ); ?></button>
							<?php elseif ( 'check' === $karetaker_step[3] ) : ?>
								<button type="button" class="btn xs" data-kt-scan><?php echo esc_html( $karetaker_step[2] ); ?></button>
							<?php elseif ( isset( $karetaker_links[ $karetaker_step[3] ] ) ) : ?>
								<a class="btn xs" href="<?php echo esc_url( $karetaker_links[ $karetaker_step[3] ] ); ?>"<?php echo 'backup' === $karetaker_step[3] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php echo esc_html( $karetaker_step[2] ); ?></a>
							<?php endif; ?>
							<label class="inline"><input type="checkbox" data-step="<?php echo esc_attr( $karetaker_key ); ?>"> <?php echo esc_html__( 'Done', 'karetaker' ); ?></label>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>
			<footer><span class="muted" id="mCount"></span><span style="flex:1"></span><a class="btn" id="mExport" href="#"><?php echo esc_html__( 'Export record', 'karetaker' ); ?></a><button type="button" class="btn primary" id="mCloseCase" disabled><?php echo esc_html__( 'Close incident', 'karetaker' ); ?></button><button type="button" class="btn" id="mReopen" hidden><?php echo esc_html__( 'Reopen', 'karetaker' ); ?></button></footer>
		</div>
	</div>
<?php endif; ?>
