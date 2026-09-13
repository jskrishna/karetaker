<?php
/**
 * Activity tab markup partial.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form class="kt-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
	<input type="hidden" name="page" value="karetaker" />
	<input type="hidden" name="tab" value="activity" />
	<div class="kt-field">
		<label for="kt_sev"><?php echo esc_html__( 'Severity', 'karetaker' ); ?></label>
		<select name="kt_sev" id="kt_sev">
			<option value=""><?php echo esc_html__( 'All', 'karetaker' ); ?></option>
			<option value="log" <?php selected( $filter_sev, 'log' ); ?>><?php echo esc_html__( 'Log', 'karetaker' ); ?></option>
			<option value="watch" <?php selected( $filter_sev, 'watch' ); ?>><?php echo esc_html__( 'Watch', 'karetaker' ); ?></option>
			<option value="act" <?php selected( $filter_sev, 'act' ); ?>><?php echo esc_html__( 'Act-now', 'karetaker' ); ?></option>
		</select>
	</div>
	<div class="kt-field">
		<label for="kt_code"><?php echo esc_html__( 'Event code', 'karetaker' ); ?></label>
		<select name="kt_code" id="kt_code">
			<option value=""><?php echo esc_html__( 'All codes', 'karetaker' ); ?></option>
			<?php foreach ( array_keys( Karetaker_Events::codes() ) as $event_code_key ) : ?>
				<option value="<?php echo esc_attr( $event_code_key ); ?>" <?php selected( $filter_code, $event_code_key ); ?>><?php echo esc_html( $event_code_key ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<div class="kt-field">
		<label for="kt_since"><?php echo esc_html__( 'From (UTC)', 'karetaker' ); ?></label>
		<input type="date" name="kt_since" id="kt_since" value="<?php echo esc_attr( $filter_since ); ?>" />
	</div>
	<div class="kt-field">
		<label for="kt_until"><?php echo esc_html__( 'To (UTC)', 'karetaker' ); ?></label>
		<input type="date" name="kt_until" id="kt_until" value="<?php echo esc_attr( $filter_until ); ?>" />
	</div>
	<div class="kt-field">
		<label for="kt_s"><?php echo esc_html__( 'Search', 'karetaker' ); ?></label>
		<input type="search" name="kt_s" id="kt_s" value="<?php echo esc_attr( $filter_search ); ?>" placeholder="<?php echo esc_attr__( 'Code or context', 'karetaker' ); ?>" />
	</div>
	<div class="kt-field">
		<label>&nbsp;</label>
		<button type="submit" class="kt-btn kt-btn--primary">
			<span class="dashicons dashicons-filter" aria-hidden="true"></span>
			<?php echo esc_html__( 'Apply', 'karetaker' ); ?>
		</button>
	</div>
</form>

<div class="kt-actions">
	<a class="kt-btn kt-btn--secondary" href="<?php echo esc_url( $export_url ); ?>">
		<span class="dashicons dashicons-download" aria-hidden="true"></span>
		<?php echo esc_html__( 'Export CSV', 'karetaker' ); ?>
	</a>
</div>

<div class="kt-table-wrap">
	<?php $table->display(); ?>
</div>

<div class="kt-drawer" aria-hidden="true" role="dialog" aria-modal="true">
	<div class="kt-drawer__backdrop" tabindex="-1"></div>
	<div class="kt-drawer__panel">
		<div class="kt-drawer__head">
			<div>
				<p class="kt-drawer__eyebrow"></p>
				<h2 class="kt-drawer__title"></h2>
				<p class="kt-drawer__meta kt-metric__meta"></p>
			</div>
			<button type="button" class="kt-drawer__close" aria-label="<?php echo esc_attr__( 'Close', 'karetaker' ); ?>">
				<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
			</button>
		</div>

		<div class="kt-drawer__action">
			<div class="kt-drawer__action-head">
				<span class="dashicons dashicons-yes" aria-hidden="true"></span>
				<strong><?php echo esc_html__( 'What you should do', 'karetaker' ); ?></strong>
			</div>
			<p class="kt-drawer__summary"></p>
			<ol class="kt-drawer__steps"></ol>
			<p class="kt-drawer__link-wrap">
				<a class="kt-btn kt-btn--primary kt-drawer__link" href="#" style="display:none;"></a>
			</p>
		</div>

		<details class="kt-drawer__details">
			<summary><?php echo esc_html__( 'Technical details (JSON)', 'karetaker' ); ?></summary>
			<button type="button" class="kt-btn kt-btn--secondary kt-drawer__copy">
				<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
				<?php echo esc_html__( 'Copy JSON', 'karetaker' ); ?>
			</button>
			<pre></pre>
		</details>
	</div>
</div>
