<?php
/**
 * Activity: plain-language feed grouped by day.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only feed switches.
$karetaker_mode = isset( $_GET['feed'] ) && 'all' === $_GET['feed'] ? 'all' : 'important';
$karetaker_pg   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
// phpcs:enable WordPress.Security.NonceVerification.Recommended
$karetaker_feed = Karetaker_Admin_Data::feed( $karetaker_mode, $karetaker_pg );
$karetaker_base = self::admin_page_url( 'activity', 'all' === $karetaker_mode ? array( 'feed' => 'all' ) : array() );
?>
<section data-page="activity">
	<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
		<div class="seg">
			<a href="<?php echo esc_url( self::admin_page_url( 'activity' ) ); ?>" aria-pressed="<?php echo 'important' === $karetaker_mode ? 'true' : 'false'; ?>"><?php echo esc_html__( 'Important', 'karetaker' ); ?></a>
			<a href="<?php echo esc_url( self::admin_page_url( 'activity', array( 'feed' => 'all' ) ) ); ?>" aria-pressed="<?php echo 'all' === $karetaker_mode ? 'true' : 'false'; ?>"><?php echo esc_html__( 'Everything', 'karetaker' ); ?></a>
		</div>
		<span class="grow" style="flex:1"></span>
		<span class="hint"><?php echo esc_html__( 'Kept on your site only.', 'karetaker' ); ?></span>
	</div>
	<div class="card">
		<?php if ( ! $karetaker_feed['days'] ) : ?>
			<div class="quiet"><b><?php echo esc_html__( 'Nothing here yet', 'karetaker' ); ?></b><span class="muted"><?php echo 'important' === $karetaker_mode ? esc_html__( 'Nothing important has happened. Switch to Everything to see all activity.', 'karetaker' ) : esc_html__( 'Karetaker records activity as it happens.', 'karetaker' ); ?></span></div>
		<?php endif; ?>
		<?php foreach ( $karetaker_feed['days'] as $karetaker_day ) : ?>
			<div class="day"><?php echo esc_html( $karetaker_day['day'] ); ?></div>
			<ul class="feed">
				<?php foreach ( $karetaker_day['items'] as $karetaker_item ) : ?>
					<li class="<?php echo esc_attr( $karetaker_item['lv'] ); ?>">
						<span class="fi"><?php self::the_icon( $karetaker_item['icon'] ); ?></span>
						<div><div><?php echo esc_html( $karetaker_item['text'] ); ?></div>
						<?php
						if ( '' !== $karetaker_item['who'] ) :
							?>
							<div class="who"><?php echo esc_html( $karetaker_item['who'] ); ?></div><?php endif; ?></div>
						<time><?php echo esc_html( $karetaker_item['time'] ); ?></time>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>
		<?php if ( $karetaker_pg > 1 || $karetaker_feed['more'] ) : ?>
			<div class="feed-more">
				<?php if ( $karetaker_pg > 1 ) : ?>
					<a class="btn" href="<?php echo esc_url( add_query_arg( 'paged', $karetaker_pg - 1, $karetaker_base ) ); ?>"><?php echo esc_html__( 'Newer', 'karetaker' ); ?></a>
				<?php endif; ?>
				<?php if ( $karetaker_feed['more'] ) : ?>
					<a class="btn" href="<?php echo esc_url( add_query_arg( 'paged', $karetaker_pg + 1, $karetaker_base ) ); ?>"><?php echo esc_html__( 'Older', 'karetaker' ); ?></a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</section>
