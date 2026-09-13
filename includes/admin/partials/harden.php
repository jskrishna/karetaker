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

	<?php foreach ( $harden_groups as $group_label => $keys ) : ?>
		<div class="kt-panel">
			<div class="kt-panel__head">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<h2><?php echo esc_html( $group_label ); ?></h2>
			</div>
			<div class="kt-harden-grid">
				<?php foreach ( $keys as $key ) : ?>
					<?php
					$label = isset( $meta[ $key ]['label'] ) ? $meta[ $key ]['label'] : $key;
					$help  = isset( $meta[ $key ]['help'] ) ? $meta[ $key ]['help'] : '';
					$id    = 'karetaker_harden_' . $key;
					?>
					<div class="kt-toggle-card">
						<div>
							<p class="kt-toggle-card__title">
								<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
							</p>
							<?php if ( '' !== $help ) : ?>
								<p class="kt-toggle-card__help"><?php echo esc_html( $help ); ?></p>
							<?php endif; ?>
						</div>
						<label class="kt-switch" for="<?php echo esc_attr( $id ); ?>">
							<input
								type="checkbox"
								id="<?php echo esc_attr( $id ); ?>"
								name="harden[<?php echo esc_attr( $key ); ?>]"
								value="1"
								<?php checked( ! empty( $desired[ $key ] ) ); ?>
							/>
							<span class="kt-switch__track"></span>
						</label>
						<span class="kt-toggle-card__live"><?php echo esc_html( Karetaker_Harden::probe( $key ) ); ?></span>
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
