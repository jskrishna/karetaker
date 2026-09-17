<?php
/**
 * Advanced: Activity log table (Monitoring → Activity log).
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karetaker_per     = 25;
$karetaker_filters = Karetaker_Admin_Data::log_filters();
$karetaker_ui      = $karetaker_filters['ui'];
unset( $karetaker_filters['ui'] );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pager.
$karetaker_paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
$karetaker_total  = Karetaker_Events::count_filtered( $karetaker_filters );
$karetaker_pages  = max( 1, (int) ceil( $karetaker_total / $karetaker_per ) );
$karetaker_paged  = min( $karetaker_paged, $karetaker_pages );
$karetaker_marked = Karetaker_Expected::marked_ids();
$karetaker_events = Karetaker_Events::query(
	array_merge(
		$karetaker_filters,
		array(
			'limit'  => $karetaker_per,
			'offset' => ( $karetaker_paged - 1 ) * $karetaker_per,
		)
	)
);
$karetaker_query  = array_merge( array( 'view' => 'log' ), array_filter( $karetaker_ui, 'strlen' ) );
$karetaker_base   = self::admin_page_url( 'monitoring', $karetaker_query );
$karetaker_saved  = get_user_meta( get_current_user_id(), 'karetaker_saved_views', true );
$karetaker_saved  = is_array( $karetaker_saved ) ? $karetaker_saved : array();
$karetaker_sevs   = array(
	''       => __( 'All', 'karetaker' ),
	'act'    => __( 'Act now', 'karetaker' ),
	'review' => __( 'Review', 'karetaker' ),
	'log'    => __( 'Logged', 'karetaker' ),
);
$karetaker_ranges = array(
	'24h' => __( 'Last 24 hours', 'karetaker' ),
	'7d'  => __( 'Last 7 days', 'karetaker' ),
	'30d' => __( 'Last 30 days', 'karetaker' ),
	'all' => __( 'All time', 'karetaker' ),
);
$karetaker_export = array_filter( $karetaker_ui, 'strlen' );
?>
<div class="phead"><div><h1><?php echo esc_html__( 'Activity log', 'karetaker' ); ?></h1><p><?php echo esc_html__( 'Everything Karetaker recorded. Stored in your database only.', 'karetaker' ); ?></p></div><span class="grow"></span>
	<div class="tools">
		<div class="menu-wrap">
			<button type="button" class="btn" data-kt-toggle="kt-views" aria-expanded="false"><?php echo esc_html__( 'Saved views', 'karetaker' ); ?></button>
			<div class="menu" id="kt-views" hidden>
				<?php foreach ( $karetaker_saved as $karetaker_name => $karetaker_url ) : ?>
					<div class="menu-row"><a href="<?php echo esc_url( $karetaker_url ); ?>"><?php echo esc_html( $karetaker_name ); ?></a><button type="button" class="btn ghost xs" data-kt-view-delete="<?php echo esc_attr( $karetaker_name ); ?>" aria-label="<?php echo esc_attr__( 'Delete view', 'karetaker' ); ?>">✕</button></div>
				<?php endforeach; ?>
				<?php if ( ! $karetaker_saved ) : ?>
					<div class="menu-row muted"><?php echo esc_html__( 'No saved views yet.', 'karetaker' ); ?></div>
				<?php endif; ?>
				<div class="menu-row"><button type="button" class="btn xs primary" data-kt-view-save="<?php echo esc_attr( $karetaker_base ); ?>"><?php echo esc_html__( 'Save current filters', 'karetaker' ); ?></button></div>
			</div>
		</div>
		<a class="btn" href="<?php echo esc_url( Karetaker_Exports::url( 'activity', array_merge( $karetaker_export, array( 'format' => 'csv' ) ) ) ); ?>"><?php echo esc_html__( 'Export CSV', 'karetaker' ); ?></a>
		<a class="btn" href="<?php echo esc_url( Karetaker_Exports::url( 'activity', array_merge( $karetaker_export, array( 'format' => 'json' ) ) ) ); ?>"><?php echo esc_html__( 'Export JSON', 'karetaker' ); ?></a>
	</div>
</div>
<div class="panel">
	<form class="tb" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
		<input type="hidden" name="tab" value="monitoring">
		<input type="hidden" name="view" value="log">
		<input type="hidden" name="kt_sev" value="<?php echo esc_attr( $karetaker_ui['kt_sev'] ); ?>">
		<label class="sr" for="aq"><?php echo esc_html__( 'Filter log', 'karetaker' ); ?></label>
		<input type="search" id="aq" name="kt_s" value="<?php echo esc_attr( $karetaker_ui['kt_s'] ); ?>" placeholder="<?php echo esc_attr__( 'Filter by event, user, IP, file', 'karetaker' ); ?>">
		<div class="seg">
			<?php foreach ( $karetaker_sevs as $karetaker_key => $karetaker_label ) : ?>
				<a href="<?php echo esc_url( self::admin_page_url( 'monitoring', array_filter( array_merge( $karetaker_query, array( 'kt_sev' => $karetaker_key ) ), 'strlen' ) ) ); ?>" aria-pressed="<?php echo $karetaker_key === $karetaker_ui['kt_sev'] ? 'true' : 'false'; ?>"><?php echo esc_html( $karetaker_label ); ?></a>
			<?php endforeach; ?>
		</div>
		<select name="kt_cat" aria-label="<?php echo esc_attr__( 'Category', 'karetaker' ); ?>" data-kt-submit>
			<option value=""><?php echo esc_html__( 'All categories', 'karetaker' ); ?></option>
			<?php foreach ( array( 'files', 'users', 'plugins', 'settings', 'login', 'scan' ) as $karetaker_cat ) : ?>
				<option value="<?php echo esc_attr( $karetaker_cat ); ?>"<?php selected( $karetaker_ui['kt_cat'], $karetaker_cat ); ?>><?php echo esc_html( Karetaker_Issues::area_label( $karetaker_cat ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="kt_range" aria-label="<?php echo esc_attr__( 'Date range', 'karetaker' ); ?>" data-kt-submit>
			<?php foreach ( $karetaker_ranges as $karetaker_key => $karetaker_label ) : ?>
				<option value="<?php echo esc_attr( $karetaker_key ); ?>"<?php selected( $karetaker_ui['kt_range'], $karetaker_key ); ?>><?php echo esc_html( $karetaker_label ); ?></option>
			<?php endforeach; ?>
		</select>
	</form>
	<div class="bulk" id="bulk" hidden><b id="selCount">0</b> <?php echo esc_html__( 'selected', 'karetaker' ); ?>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<button type="button" class="btn xs" id="bulkExpect"><?php echo esc_html__( 'Mark as expected', 'karetaker' ); ?></button>
		<?php endif; ?>
		<a class="btn xs" id="bulkExport" href="<?php echo esc_url( self::export_url( 'activity', 'csv', '', 'all' ) ); ?>"><?php echo esc_html__( 'Export selected', 'karetaker' ); ?></a>
		<button type="button" class="btn ghost xs" id="clearSel"><?php echo esc_html__( 'Clear', 'karetaker' ); ?></button>
	</div>
	<div class="tw"><table>
		<thead><tr>
			<th class="chk"><input type="checkbox" id="selAll" aria-label="<?php echo esc_attr__( 'Select all', 'karetaker' ); ?>"></th>
			<th><?php echo esc_html__( 'Time', 'karetaker' ); ?> ▾</th><th><?php echo esc_html__( 'Importance', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Event', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Category', 'karetaker' ); ?></th><th><?php echo esc_html__( 'User', 'karetaker' ); ?></th><th><?php echo esc_html__( 'IP', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Object', 'karetaker' ); ?></th>
		</tr></thead>
		<tbody id="logBody">
			<?php if ( ! $karetaker_events ) : ?>
				<tr><td colspan="8"><div class="empty"><b><?php echo esc_html__( 'No events match.', 'karetaker' ); ?></b><?php echo esc_html__( 'Clear a filter or widen the date range.', 'karetaker' ); ?></div></td></tr>
			<?php endif; ?>
			<?php
			foreach ( $karetaker_events as $karetaker_row ) :
				$karetaker_ev  = Karetaker_Admin_Data::event( $karetaker_row, $karetaker_marked );
				$karetaker_key = self::expose_event( $karetaker_ev );
				$karetaker_obj = $karetaker_ev['details'] ? $karetaker_ev['details'][0][1] : '';
				?>
				<tr class="row" data-open="<?php echo esc_attr( $karetaker_key ); ?>">
					<td class="chk"><input type="checkbox" data-sel="<?php echo esc_attr( (string) $karetaker_ev['id'] ); ?>" aria-label="<?php echo esc_attr__( 'Select event', 'karetaker' ); ?>"></td>
					<td class="num"><?php echo esc_html( $karetaker_ev['when'] ); ?></td>
					<td><?php self::sev_chip( $karetaker_ev['expected'] ? 'log' : $karetaker_ev['sev'] ); ?></td>
					<td><?php echo esc_html( $karetaker_ev['title'] ); ?></td>
					<td><?php echo esc_html( Karetaker_Issues::area_label( Karetaker_Issues::area( $karetaker_ev['code'] ) ) ); ?></td>
					<td><?php echo esc_html( explode( ' · ', $karetaker_ev['who'] )[0] ); ?></td>
					<td class="num"><?php echo esc_html( '' !== (string) $karetaker_row->ip_display ? (string) $karetaker_row->ip_display : '—' ); ?></td>
					<td>
					<?php
					if ( '' !== $karetaker_obj ) :
						?>
						<code><?php echo esc_html( wp_trim_words( $karetaker_obj, 8 ) ); ?></code><?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table></div>
	<footer>
		<span>
			<?php
			/* translators: 1: rows shown, 2: total rows */
			echo esc_html( sprintf( __( 'Showing %1$s of %2$s events', 'karetaker' ), number_format_i18n( count( $karetaker_events ) ), number_format_i18n( $karetaker_total ) ) );
			?>
		</span>
		<div class="pager">
			<?php if ( $karetaker_paged > 1 ) : ?>
				<a class="btn xs" href="<?php echo esc_url( add_query_arg( 'paged', $karetaker_paged - 1, $karetaker_base ) ); ?>"><?php echo esc_html__( 'Previous', 'karetaker' ); ?></a>
			<?php else : ?>
				<button type="button" class="btn xs" disabled><?php echo esc_html__( 'Previous', 'karetaker' ); ?></button>
			<?php endif; ?>
			<?php /* translators: 1: current page, 2: total pages */ ?>
			<span><?php echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'karetaker' ), $karetaker_paged, $karetaker_pages ) ); ?></span>
			<?php if ( $karetaker_paged < $karetaker_pages ) : ?>
				<a class="btn xs" href="<?php echo esc_url( add_query_arg( 'paged', $karetaker_paged + 1, $karetaker_base ) ); ?>"><?php echo esc_html__( 'Next', 'karetaker' ); ?></a>
			<?php else : ?>
				<button type="button" class="btn xs" disabled><?php echo esc_html__( 'Next', 'karetaker' ); ?></button>
			<?php endif; ?>
		</div>
	</footer>
</div>
