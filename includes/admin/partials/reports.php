<?php
/**
 * Advanced: Reports & exports.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karetaker_periods = Karetaker_Report::periods();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preview period switch only.
$karetaker_period = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : 'm:' . ( new DateTimeImmutable( 'first day of last month', wp_timezone() ) )->format( 'Y-m' );
if ( ! isset( $karetaker_periods[ $karetaker_period ] ) ) {
	$karetaker_period = '30';
}
$karetaker_include = Karetaker_Report::includes();
$karetaker_logo_id = (int) Karetaker_Settings::get( 'report_logo_id' );
$karetaker_report  = Karetaker_Report::build( $karetaker_period );
$karetaker_html    = Karetaker_Report::render_html( $karetaker_report );
$karetaker_paper   = preg_match( '#<div class="paper">(.*)</div>\s*</body>#s', $karetaker_html, $karetaker_m ) ? $karetaker_m[1] : '';
$karetaker_cases   = Karetaker_Cases::all();
$karetaker_checks  = array(
	'summary' => __( 'Summary and checks', 'karetaker' ),
	'issues'  => __( 'Issues and how they were resolved', 'karetaker' ),
	'plugins' => __( 'Plugin risk', 'karetaker' ),
	'harden'  => __( 'Hardening status', 'karetaker' ),
	'credit'  => __( '"Monitored by Karetaker" line', 'karetaker' ),
);
?>
<section data-page="reports">
	<div class="phead"><div><h1><?php echo esc_html__( 'Reports & exports', 'karetaker' ); ?></h1><p><?php echo esc_html__( 'Client-ready reports and records for audits.', 'karetaker' ); ?></p></div></div>
	<div class="g2">
		<div>
			<div class="panel">
				<header><h2><?php echo esc_html__( 'Client report', 'karetaker' ); ?></h2></header>
				<form class="form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="reportForm">
					<input type="hidden" name="action" value="karetaker_export">
					<input type="hidden" name="type" value="report">
					<?php wp_nonce_field( 'karetaker_export_report', '_wpnonce', false ); ?>
					<label for="ragency"><?php echo esc_html__( 'Prepared by', 'karetaker' ); ?></label><div class="ctl"><input type="text" id="ragency" name="report_agency" value="<?php echo esc_attr( (string) Karetaker_Settings::get( 'report_agency' ) ); ?>" placeholder="<?php echo esc_attr( (string) get_bloginfo( 'name' ) ); ?>"></div>
					<label for="rclient"><?php echo esc_html__( 'Client', 'karetaker' ); ?></label><div class="ctl"><input type="text" id="rclient" name="report_client" value="<?php echo esc_attr( (string) Karetaker_Settings::get( 'report_client' ) ); ?>" placeholder="<?php echo esc_attr( (string) get_bloginfo( 'name' ) ); ?>"></div>
					<label for="remail"><?php echo esc_html__( 'Client email', 'karetaker' ); ?></label><div class="ctl"><input type="email" id="remail" name="report_client_email" value="<?php echo esc_attr( (string) Karetaker_Settings::get( 'report_client_email' ) ); ?>"><span class="hint"><?php echo esc_html__( 'Used by "Email now" and the monthly schedule.', 'karetaker' ); ?></span></div>
					<div class="lbl"><?php echo esc_html__( 'Logo', 'karetaker' ); ?></div>
					<div class="ctl"><div class="inline">
						<input type="hidden" name="report_logo_id" id="rlogo" value="<?php echo esc_attr( (string) $karetaker_logo_id ); ?>">
						<button type="button" class="btn xs" data-kt-logo><?php echo esc_html__( 'Choose from Media Library', 'karetaker' ); ?></button>
						<?php if ( $karetaker_logo_id ) : ?>
							<button type="button" class="btn ghost xs" data-kt-logo-clear><?php echo esc_html__( 'Remove', 'karetaker' ); ?></button>
						<?php endif; ?>
						<span class="hint" id="rlogoName"><?php echo $karetaker_logo_id ? esc_html( get_the_title( $karetaker_logo_id ) ) : esc_html__( 'Uses the WordPress media picker.', 'karetaker' ); ?></span>
					</div></div>
					<label for="rperiod"><?php echo esc_html__( 'Period', 'karetaker' ); ?></label>
					<div class="ctl">
						<select id="rperiod" name="period" data-kt-period="<?php echo esc_url( self::admin_page_url( 'reports' ) ); ?>">
							<?php foreach ( $karetaker_periods as $karetaker_key => $karetaker_label ) : ?>
								<option value="<?php echo esc_attr( (string) $karetaker_key ); ?>"<?php selected( $karetaker_period, (string) $karetaker_key ); ?>><?php echo esc_html( $karetaker_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<div class="inline" id="rcustom" hidden>
							<input type="date" name="from" aria-label="<?php echo esc_attr__( 'From', 'karetaker' ); ?>" style="max-width:160px">–<input type="date" name="to" aria-label="<?php echo esc_attr__( 'To', 'karetaker' ); ?>" style="max-width:160px">
						</div>
					</div>
					<div class="lbl"><?php echo esc_html__( 'Include', 'karetaker' ); ?></div>
					<div class="ctl">
						<?php foreach ( $karetaker_checks as $karetaker_key => $karetaker_label ) : ?>
							<label class="inline"><input type="checkbox" name="include[]" value="<?php echo esc_attr( $karetaker_key ); ?>"<?php checked( in_array( $karetaker_key, $karetaker_include, true ) ); ?>> <?php echo esc_html( $karetaker_label ); ?></label>
						<?php endforeach; ?>
					</div>
					<label for="rsched"><?php echo esc_html__( 'Schedule', 'karetaker' ); ?></label>
					<div class="ctl">
						<select id="rsched" name="schedule">
							<option value=""><?php echo esc_html__( 'Don\'t schedule', 'karetaker' ); ?></option>
							<option value="monthly"<?php selected( (string) Karetaker_Settings::get( 'report_schedule' ), 'monthly' ); ?>><?php echo esc_html__( '1st of each month', 'karetaker' ); ?></option>
						</select>
						<span class="hint"><?php echo esc_html__( 'Sent from this site by email.', 'karetaker' ); ?></span>
					</div>
					<div></div>
					<div class="inline">
						<button type="submit" class="btn primary" formtarget="_blank"><?php echo esc_html__( 'Download PDF', 'karetaker' ); ?></button>
						<button type="submit" class="btn" name="send" value="1"><?php echo esc_html__( 'Email now', 'karetaker' ); ?></button>
					</div>
				</form>
			</div>
			<div class="panel">
				<header><h2><?php echo esc_html__( 'Exports', 'karetaker' ); ?></h2></header>
				<div class="tw"><table>
					<thead><tr><th><?php echo esc_html__( 'Export', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Contains', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Format', 'karetaker' ); ?></th><th></th></tr></thead>
					<tbody>
						<tr><td><?php echo esc_html__( 'Activity log', 'karetaker' ); ?></td><td><?php echo esc_html__( 'Events from the last 30 days', 'karetaker' ); ?></td><td>CSV, JSON</td><td><div class="inline">
							<a class="btn xs" href="<?php echo esc_url( self::export_url( 'activity', 'csv', '', '30d' ) ); ?>">CSV</a>
							<a class="btn xs" href="<?php echo esc_url( self::export_url( 'activity', 'json', '', '30d' ) ); ?>">JSON</a>
						</div></td></tr>
						<tr><td><?php echo esc_html__( 'Incident record', 'karetaker' ); ?></td><td><?php echo esc_html__( 'Latest case, linked issues, checklist, timeline', 'karetaker' ); ?></td><td>HTML, JSON</td><td><div class="inline">
							<?php if ( $karetaker_cases ) : ?>
								<a class="btn xs" href="<?php echo esc_url( self::export_url( 'case', 'html', $karetaker_cases[0]['id'] ) ); ?>">HTML</a>
								<a class="btn xs" href="<?php echo esc_url( self::export_url( 'case', 'json', $karetaker_cases[0]['id'] ) ); ?>">JSON</a>
							<?php else : ?>
								<span class="muted"><?php echo esc_html__( 'No incidents yet', 'karetaker' ); ?></span>
							<?php endif; ?>
						</div></td></tr>
						<tr><td><?php echo esc_html__( 'Software inventory', 'karetaker' ); ?></td><td><?php echo esc_html__( 'Core, plugins, themes, versions, sources', 'karetaker' ); ?></td><td>CSV, JSON</td><td><div class="inline">
							<a class="btn xs" href="<?php echo esc_url( Karetaker_Exports::url( 'inventory', array( 'format' => 'csv' ) ) ); ?>">CSV</a>
							<a class="btn xs" href="<?php echo esc_url( Karetaker_Exports::url( 'inventory', array( 'format' => 'json' ) ) ); ?>">JSON</a>
						</div></td></tr>
						<tr><td><?php echo esc_html__( 'CRA evidence pack', 'karetaker' ); ?></td><td><?php echo esc_html__( 'Inventory + security events + incidents', 'karetaker' ); ?></td><td>ZIP</td><td>
							<a class="btn xs" href="<?php echo esc_url( Karetaker_Exports::url( 'evidence' ) ); ?>"><?php echo esc_html__( 'Export', 'karetaker' ); ?></a>
						</td></tr>
					</tbody>
				</table></div>
			</div>
		</div>
		<div>
			<div class="paper" aria-label="<?php echo esc_attr__( 'Report preview', 'karetaker' ); ?>">
				<?php echo wp_kses_post( $karetaker_paper ); ?>
			</div>
			<p class="hint" style="margin-top:8px"><?php echo esc_html__( 'Preview uses the saved settings. Download or email to apply changes from the form.', 'karetaker' ); ?></p>
		</div>
	</div>
</section>
