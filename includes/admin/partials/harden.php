<?php
/**
 * Harden tab markup partial.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( karetaker_is_disabled() ) : ?>
	<div class="kt-alert kt-alert--danger">
		<span class="dashicons dashicons-warning" aria-hidden="true"></span>
		<span><?php echo esc_html__( 'Karetaker is disabled (kill switch). Harden toggles are not applying.', 'karetaker' ); ?></span>
	</div>
<?php endif; ?>

<form class="karetaker-harden-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="karetaker_save_harden" />
	<?php wp_nonce_field( 'karetaker_save_harden' ); ?>

	<?php foreach ( $harden_groups as $karetaker_group_label => $karetaker_keys ) : ?>
		<div class="kt-panel">
			<div class="kt-panel__head">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<h2><?php echo esc_html( $karetaker_group_label ); ?></h2>
			</div>
			<div class="kt-harden-grid">
				<?php foreach ( $karetaker_keys as $karetaker_key ) : ?>
					<?php
					$karetaker_label = isset( $meta[ $karetaker_key ]['label'] ) ? $meta[ $karetaker_key ]['label'] : $karetaker_key;
					$karetaker_help  = isset( $meta[ $karetaker_key ]['help'] ) ? $meta[ $karetaker_key ]['help'] : '';
					$karetaker_id    = 'karetaker_harden_' . $karetaker_key;
					?>
					<div class="kt-toggle-card">
						<div>
							<p class="kt-toggle-card__title">
								<label for="<?php echo esc_attr( $karetaker_id ); ?>"><?php echo esc_html( $karetaker_label ); ?></label>
							</p>
							<?php if ( '' !== $karetaker_help ) : ?>
								<p class="kt-toggle-card__help"><?php echo esc_html( $karetaker_help ); ?></p>
							<?php endif; ?>
						</div>
						<label class="kt-switch" for="<?php echo esc_attr( $karetaker_id ); ?>">
							<input
								type="checkbox"
								id="<?php echo esc_attr( $karetaker_id ); ?>"
								name="harden[<?php echo esc_attr( $karetaker_key ); ?>]"
								value="1"
								<?php checked( ! empty( $desired[ $karetaker_key ] ) ); ?>
							/>
							<span class="kt-switch__track"></span>
						</label>
						<span class="kt-toggle-card__live"><?php echo esc_html( Karetaker_Harden::probe( $karetaker_key ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endforeach; ?>

	<div class="kt-sticky-save">
		<button type="submit" class="kt-btn kt-btn--primary">
			<span class="dashicons dashicons-saved" aria-hidden="true"></span>
			<?php echo esc_html__( 'Save Harden settings', 'karetaker' ); ?>
		</button>
	</div>
</form>
