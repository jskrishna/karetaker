<?php
/**
 * Advanced: Issues table.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
$karetaker_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'open';
$karetaker_area   = isset( $_GET['area'] ) ? sanitize_key( wp_unslash( $_GET['area'] ) ) : '';
$karetaker_owner  = isset( $_GET['owner'] ) ? sanitize_text_field( wp_unslash( $_GET['owner'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $karetaker_status, array( 'open', 'ack', 'resolved', 'expected', 'all' ), true ) ) {
	$karetaker_status = 'open';
}
$karetaker_issues = Karetaker_Issues::all(
	array(
		'status' => $karetaker_status,
		'area'   => $karetaker_area,
		'owner'  => $karetaker_owner,
	)
);
$karetaker_segs   = array(
	'open'     => __( 'Open', 'karetaker' ),
	'ack'      => __( 'Acknowledged', 'karetaker' ),
	'resolved' => __( 'Resolved', 'karetaker' ),
	'expected' => __( 'Expected', 'karetaker' ),
	'all'      => __( 'All', 'karetaker' ),
);
$karetaker_areas  = array( 'files', 'users', 'login', 'plugins', 'settings' );
$karetaker_owners = self::owners();
$karetaker_keep   = array_filter(
	array(
		'area'  => $karetaker_area,
		'owner' => $karetaker_owner,
	),
	'strlen'
);
?>
<section data-page="issues">
	<div class="phead"><div><h1><?php echo esc_html__( 'Issues', 'karetaker' ); ?></h1><p><?php echo esc_html__( 'Things Karetaker found that need a decision. Mark as expected, assign an owner, or resolve.', 'karetaker' ); ?></p></div><span class="grow"></span></div>
	<div class="panel">
		<form class="tb" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="hidden" name="tab" value="issues">
			<input type="hidden" name="status" value="<?php echo esc_attr( $karetaker_status ); ?>">
			<div class="seg">
				<?php foreach ( $karetaker_segs as $karetaker_key => $karetaker_label ) : ?>
					<a href="<?php echo esc_url( self::admin_page_url( 'issues', array_merge( $karetaker_keep, array( 'status' => $karetaker_key ) ) ) ); ?>" aria-pressed="<?php echo $karetaker_key === $karetaker_status ? 'true' : 'false'; ?>"><?php echo esc_html( $karetaker_label ); ?></a>
				<?php endforeach; ?>
			</div>
			<select name="area" aria-label="<?php echo esc_attr__( 'Filter by area', 'karetaker' ); ?>" data-kt-submit>
				<option value=""><?php echo esc_html__( 'All areas', 'karetaker' ); ?></option>
				<?php foreach ( $karetaker_areas as $karetaker_a ) : ?>
					<option value="<?php echo esc_attr( $karetaker_a ); ?>"<?php selected( $karetaker_area, $karetaker_a ); ?>><?php echo esc_html( Karetaker_Issues::area_label( $karetaker_a ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="owner" aria-label="<?php echo esc_attr__( 'Filter by owner', 'karetaker' ); ?>" data-kt-submit>
				<option value=""><?php echo esc_html__( 'Any owner', 'karetaker' ); ?></option>
				<?php foreach ( $karetaker_owners as $karetaker_id => $karetaker_login ) : ?>
					<option value="<?php echo esc_attr( (string) $karetaker_id ); ?>"<?php selected( $karetaker_owner, (string) $karetaker_id ); ?>><?php echo esc_html( $karetaker_login ); ?></option>
				<?php endforeach; ?>
				<option value="0"<?php selected( $karetaker_owner, '0' ); ?>><?php echo esc_html__( 'Unassigned', 'karetaker' ); ?></option>
			</select>
		</form>
		<div class="tw"><table>
			<thead><tr><th><?php echo esc_html__( 'Issue', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Area', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Importance', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Status', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Owner', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Opened', 'karetaker' ); ?></th><th></th></tr></thead>
			<tbody>
				<?php if ( ! $karetaker_issues ) : ?>
					<tr><td colspan="7"><div class="empty"><b><?php echo esc_html__( 'No issues here.', 'karetaker' ); ?></b><?php echo esc_html__( 'Karetaker will add new issues when a check finds something.', 'karetaker' ); ?></div></td></tr>
				<?php endif; ?>
				<?php
				foreach ( array_slice( $karetaker_issues, 0, 100 ) as $karetaker_issue ) :
					$karetaker_key = self::expose_issue( $karetaker_issue );
					?>
					<tr class="row" data-open="<?php echo esc_attr( $karetaker_key ); ?>">
						<td><b><?php echo esc_html( $karetaker_issue['title'] ); ?></b><span class="s">#<?php echo esc_html( (string) $karetaker_issue['id'] ); ?><?php echo $karetaker_issue['count'] > 1 ? esc_html( ' · ' . sprintf( /* translators: %d: times seen */ _n( 'seen %d time', 'seen %d times', (int) $karetaker_issue['count'], 'karetaker' ), (int) $karetaker_issue['count'] ) ) : ''; ?></span></td>
						<td><?php echo esc_html( $karetaker_issue['area_label'] ); ?></td>
						<td><?php self::sev_chip( $karetaker_issue['sev'] ); ?></td>
						<td><?php self::status_chip( $karetaker_issue['status'] ); ?></td>
						<td><?php echo esc_html( '' !== $karetaker_issue['owner_name'] ? $karetaker_issue['owner_name'] : __( 'Unassigned', 'karetaker' ) ); ?></td>
						<td class="num"><?php echo esc_html( $karetaker_issue['opened_h'] ); ?></td>
						<td><button type="button" class="btn xs" data-open="<?php echo esc_attr( $karetaker_key ); ?>"><?php echo esc_html__( 'Open', 'karetaker' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table></div>
	</div>
</section>
