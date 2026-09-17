<?php
/**
 * Home: watchtower hero, to-do cards, and the checks Karetaker runs.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karetaker_home    = self::home_model();
$karetaker_checks  = Karetaker_Admin_Data::checks();
$karetaker_flagged = count(
	array_filter(
		$karetaker_checks,
		static function ( $row ) {
			return '' !== $row['level'];
		}
	)
);
$karetaker_can_run = current_user_can( 'manage_options' );
$karetaker_tiers   = $karetaker_home['tiers'];
$karetaker_dot     = array(
	'act'   => 'bad',
	'check' => 'warn',
	'safe'  => '',
);
?>
<section data-page="home">
	<div class="hero" aria-live="polite">
		<div class="tower-art" aria-hidden="true">
			<svg viewBox="0 0 128 150" focusable="false">
				<g class="rays">
					<line class="ray" x1="44" y1="30" x2="4" y2="18"/>
					<line class="ray" x1="42" y1="36" x2="0" y2="40"/>
					<line class="ray" x1="84" y1="30" x2="124" y2="18"/>
					<line class="ray" x1="86" y1="36" x2="128" y2="40"/>
				</g>
				<circle class="lamp" cx="64" cy="30" r="14"/>
				<rect class="t<?php echo '' !== $karetaker_tiers[0] ? ' bad' : ''; ?>" x="45" y="58" width="38" height="17"/>
				<rect class="t<?php echo '' !== $karetaker_tiers[1] ? ' bad' : ''; ?>" x="31" y="89" width="66" height="17"/>
				<rect class="t<?php echo '' !== $karetaker_tiers[2] ? ' bad' : ''; ?>" x="17" y="120" width="94" height="17"/>
			</svg>
		</div>
		<div class="txt">
			<h2><?php echo esc_html( $karetaker_home['title'] ); ?></h2>
			<p><?php echo esc_html( $karetaker_home['sub'] ); ?></p>
		</div>
		<div class="side">
			<?php if ( $karetaker_can_run ) : ?>
				<button type="button" class="btn" data-kt-scan><?php self::the_icon( 'refresh' ); ?><?php echo esc_html__( 'Check now', 'karetaker' ); ?></button>
			<?php endif; ?>
			<small><?php echo esc_html( $karetaker_home['last'] ); ?></small>
		</div>
	</div>

	<?php if ( $karetaker_home['issues'] ) : ?>
		<h2 class="section-title"><?php echo esc_html__( 'To do', 'karetaker' ); ?></h2>
		<div class="stack">
			<?php
			foreach ( $karetaker_home['issues'] as $karetaker_i => $karetaker_issue ) :
				$karetaker_key = self::expose_issue( $karetaker_issue );
				?>
				<div class="card todo<?php echo 'act' === $karetaker_issue['sev'] ? '' : ' review'; ?>">
					<span class="ic"><?php self::the_icon( $karetaker_issue['icon'] ); ?></span>
					<div><h3><?php echo esc_html( $karetaker_issue['title'] ); ?></h3><p><?php echo esc_html( $karetaker_issue['what'] ); ?></p></div>
					<div class="acts"><button type="button" class="btn<?php echo 0 === $karetaker_i ? ' primary' : ''; ?>" data-open="<?php echo esc_attr( $karetaker_key ); ?>"><?php echo esc_html__( 'Show me what to do', 'karetaker' ); ?></button></div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<h2 class="section-title"><?php echo esc_html__( 'To do', 'karetaker' ); ?></h2>
		<div class="card quiet"><b><?php echo esc_html__( 'Nothing to do', 'karetaker' ); ?></b><span class="muted"><?php echo esc_html__( 'We\'ll email you if that changes.', 'karetaker' ); ?></span></div>
	<?php endif; ?>

	<h2 class="section-title"><?php echo esc_html__( 'What Karetaker watches', 'karetaker' ); ?></h2>
	<details class="card checks">
		<summary>
			<span class="dot <?php echo esc_attr( $karetaker_dot[ $karetaker_home['state'] ] ); ?>"></span>
			<span class="grow">
				<?php
				if ( $karetaker_flagged ) {
					/* translators: 1: number of checks, 2: number that found something */
					printf( esc_html__( '%1$d checks running · %2$d found something', 'karetaker' ), count( $karetaker_checks ), (int) $karetaker_flagged );
				} else {
					/* translators: %d: number of checks */
					printf( esc_html__( '%d checks running · all clear', 'karetaker' ), count( $karetaker_checks ) );
				}
				?>
			</span>
			<span class="muted"><?php echo esc_html__( 'Show', 'karetaker' ); ?></span>
			<svg class="chev" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m8 5 5 5-5 5"/></svg>
		</summary>
		<ul>
			<?php foreach ( $karetaker_checks as $karetaker_check ) : ?>
				<li><span class="dot <?php echo esc_attr( $karetaker_check['level'] ); ?>"></span><span><?php echo esc_html( $karetaker_check['name'] ); ?></span><span><?php echo esc_html( $karetaker_check['value'] ); ?></span></li>
			<?php endforeach; ?>
		</ul>
	</details>
</section>
