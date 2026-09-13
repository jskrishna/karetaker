<?php
/**
 * Overview tab markup partial.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$slice_labels = array(
	'muplugins' => __( 'MU-plugins', 'karetaker' ),
	'uploads'   => __( 'Uploads', 'karetaker' ),
	'cron'      => __( 'Cron', 'karetaker' ),
	'options'   => __( 'Options', 'karetaker' ),
	'guard'     => __( 'Guard', 'karetaker' ),
	'checksums' => __( 'Checksums', 'karetaker' ),
);
?>
<?php if ( karetaker_is_disabled() ) : ?>
	<div class="kt-alert kt-alert--danger">
		<span class="dashicons dashicons-warning" aria-hidden="true"></span>
		<span><?php echo esc_html__( 'Karetaker is disabled (kill switch). Monitoring and Harden are not applying.', 'karetaker' ); ?></span>
	</div>
<?php endif; ?>

<div class="kt-toolbar">
	<div class="kt-toolbar__left">
		<form class="kt-scan-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="karetaker_run_scan" />
			<?php wp_nonce_field( 'karetaker_run_scan' ); ?>
			<button type="submit" class="kt-btn kt-btn--primary" data-busy-label="<?php echo esc_attr__( 'Running scan…', 'karetaker' ); ?>">
				<span class="dashicons dashicons-update" aria-hidden="true"></span>
				<?php echo esc_html__( 'Run scan now', 'karetaker' ); ?>
			</button>
		</form>
		<a class="kt-btn kt-btn--secondary" href="<?php echo esc_url( admin_url( 'site-health.php' ) ); ?>">
			<span class="dashicons dashicons-heart" aria-hidden="true"></span>
			<?php echo esc_html__( 'Site Health', 'karetaker' ); ?>
		</a>
	</div>
	<div class="kt-toolbar__right">
		<span class="kt-hint">
			<?php echo esc_html__( 'Same as', 'karetaker' ); ?>
			<code>wp karetaker scan</code>
		</span>
		<?php if ( $next_scan ) : ?>
			<span class="kt-hint">
				<span class="dashicons dashicons-clock" aria-hidden="true" style="font-size:14px;width:14px;height:14px;vertical-align:middle;"></span>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: localised datetime */
						__( 'Next: %s', 'karetaker' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_scan )
					)
				);
				?>
			</span>
		<?php endif; ?>
	</div>
</div>

<div class="kt-posture">
	<div class="kt-metric <?php echo esc_attr( $metric_scan_class ); ?>">
		<div class="kt-metric__top">
			<span class="kt-metric__label"><?php echo esc_html__( 'Last scan', 'karetaker' ); ?></span>
			<span class="kt-metric__icon"><span class="dashicons dashicons-search" aria-hidden="true"></span></span>
		</div>
		<div class="kt-metric__value"><?php echo esc_html( $last_scan_label ); ?></div>
		<?php if ( '' !== $last_run ) : ?>
			<div class="kt-metric__meta"><?php echo esc_html( $last_run . ' UTC' ); ?></div>
		<?php endif; ?>
	</div>
	<div class="kt-metric <?php echo esc_attr( $metric_guard_class ); ?>">
		<div class="kt-metric__top">
			<span class="kt-metric__label"><?php echo esc_html__( 'Guard', 'karetaker' ); ?></span>
			<span class="kt-metric__icon"><span class="dashicons dashicons-shield" aria-hidden="true"></span></span>
		</div>
		<div class="kt-metric__value"><?php echo esc_html( $guard_label ); ?></div>
	</div>
	<div class="kt-metric <?php echo esc_attr( $metric_act_class ); ?>">
		<div class="kt-metric__top">
			<span class="kt-metric__label"><?php echo esc_html__( 'ACT (7 days)', 'karetaker' ); ?></span>
			<span class="kt-metric__icon"><span class="dashicons dashicons-flag" aria-hidden="true"></span></span>
		</div>
		<div class="kt-metric__value"><?php echo esc_html( (string) $act_count ); ?></div>
	</div>
	<div class="kt-metric is-ok">
		<div class="kt-metric__top">
			<span class="kt-metric__label"><?php echo esc_html__( 'Events stored', 'karetaker' ); ?></span>
			<span class="kt-metric__icon"><span class="dashicons dashicons-list-view" aria-hidden="true"></span></span>
		</div>
		<div class="kt-metric__value"><?php echo esc_html( (string) $total ); ?></div>
	</div>
	<div class="kt-metric is-ok">
		<div class="kt-metric__top">
			<span class="kt-metric__label"><?php echo esc_html__( 'Agency', 'karetaker' ); ?></span>
			<span class="kt-metric__icon"><span class="dashicons dashicons-rest-api" aria-hidden="true"></span></span>
		</div>
		<div class="kt-metric__value">
			<?php
			echo '' === Karetaker_Settings::agency_token()
				? esc_html__( 'Off', 'karetaker' )
				: esc_html__( 'On', 'karetaker' );
			?>
		</div>
	</div>
</div>

<?php if ( ! empty( $results ) ) : ?>
	<div class="kt-panel">
		<div class="kt-panel__head">
			<span class="dashicons dashicons-performance" aria-hidden="true"></span>
			<h2><?php echo esc_html__( 'Last scan slices', 'karetaker' ); ?></h2>
		</div>
		<div class="kt-panel__body" style="padding:0;">
			<table class="kt-data-table">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Slice', 'karetaker' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Key', 'karetaker' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Result', 'karetaker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $results as $scan => $status ) : ?>
						<?php
						$scan_key   = (string) $scan;
						$scan_label = isset( $slice_labels[ $scan_key ] ) ? $slice_labels[ $scan_key ] : $scan_key;
						$status_str = (string) $status;
						$is_clean   = ( false !== strpos( $status_str, 'clean' ) || false !== strpos( $status_str, 'baseline' ) || 'ok' === $status_str );
						?>
						<tr>
							<td><?php echo esc_html( $scan_label ); ?></td>
							<td><code><?php echo esc_html( $scan_key ); ?></code></td>
							<td>
								<span class="kt-status-pill<?php echo $is_clean ? '' : ' is-warn'; ?>">
									<span class="dashicons <?php echo $is_clean ? 'dashicons-yes-alt' : 'dashicons-info'; ?>" aria-hidden="true" style="font-size:14px;width:14px;height:14px;"></span>
									<?php echo esc_html( $status_str ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php endif; ?>

<div class="kt-panel">
	<div class="kt-panel__head">
		<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
		<h2><?php echo esc_html__( 'What Karetaker does', 'karetaker' ); ?></h2>
	</div>
	<div class="kt-panel__body">
		<p>
			<?php
			echo esc_html__(
				'Karetaker is a watchtower, not a wall. It notices compromise signals, watches for one-checkbox business catastrophes, can apply opt-in hardening toggles, and emails you only for act-now events.',
				'karetaker'
			);
			?>
		</p>
		<p>
			<?php echo esc_html__( 'It is not a WAF, malware signature scanner, or login-lockout product by default — and it does not write wp-config.php, .htaccess, or server config.', 'karetaker' ); ?>
		</p>
	</div>
</div>
