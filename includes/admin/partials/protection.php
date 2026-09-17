<?php
/**
 * Protection: simple switches for the harden options.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karetaker_desired = Karetaker_Settings::harden();
$karetaker_rows    = array(
	'user_enum'     => array( __( 'Hide your usernames', 'karetaker' ), __( 'Makes it harder for bots to guess your login.', 'karetaker' ), true ),
	'xmlrpc'        => array( __( 'Turn off XML-RPC', 'karetaker' ), __( 'Closes an old remote door that bots use for password guessing.', 'karetaker' ), true ),
	'file_editor'   => array( __( 'Block the code editor', 'karetaker' ), __( 'Nobody can edit plugin or theme code from the dashboard.', 'karetaker' ), true ),
	'registration'  => array( __( 'Keep sign-ups closed', 'karetaker' ), __( 'Makes sure nobody can turn on public registration.', 'karetaker' ), true ),
	'headers'       => array( __( 'Add browser safety headers', 'karetaker' ), __( 'Tells browsers to block common tricks like clickjacking.', 'karetaker' ), true ),
	'version'       => array( __( 'Hide your WordPress version', 'karetaker' ), __( 'Removes the version number from your pages.', 'karetaker' ), false ),
	'app_passwords' => array( __( 'Limit app passwords to admins', 'karetaker' ), __( 'Other users can’t create app passwords.', 'karetaker' ), false ),
);
?>
<section data-page="protection">
	<p class="muted" style="margin-bottom:14px;max-width:70ch"><?php echo esc_html__( 'Simple protections you can switch on or off. None of them can lock you out, and Karetaker never edits wp-config.php or .htaccess.', 'karetaker' ); ?></p>
	<div class="card rows">
		<?php
		foreach ( $karetaker_rows as $karetaker_key => $karetaker_row ) :
			$karetaker_on = ! empty( $karetaker_desired[ $karetaker_key ] );
			?>
			<div>
				<div>
					<h3><?php echo esc_html( $karetaker_row[0] ); ?>
					<?php
					if ( $karetaker_row[2] ) :
						?>
						<span class="tag"><?php echo esc_html__( 'Recommended', 'karetaker' ); ?></span><?php endif; ?> <span class="tag <?php echo $karetaker_on ? 'ok' : 'off'; ?>" data-live data-on="<?php echo esc_attr__( 'On', 'karetaker' ); ?>" data-off="<?php echo esc_attr__( 'Off', 'karetaker' ); ?>"><?php echo $karetaker_on ? esc_html__( 'On', 'karetaker' ) : esc_html__( 'Off', 'karetaker' ); ?></span></h3>
					<p><?php echo esc_html( $karetaker_row[1] ); ?></p>
				</div>
				<label class="switch"><input type="checkbox" data-kt-harden="<?php echo esc_attr( $karetaker_key ); ?>"<?php checked( $karetaker_on ); ?> aria-label="<?php echo esc_attr( $karetaker_row[0] ); ?>"><span></span></label>
			</div>
		<?php endforeach; ?>
	</div>
</section>
