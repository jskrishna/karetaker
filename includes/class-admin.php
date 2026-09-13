<?php
/**
 * Karetaker admin UI.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Karetaker admin UI and save handlers.
 *
 * @since 0.1.0
 * @package Karetaker
 */
class Karetaker_Admin {

	const PAGE_SLUG = 'karetaker';

	const TABS = array( 'overview', 'activity', 'harden', 'settings' );

	/**
	 * Registers the Tools menu page, save handlers, and notices.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_legacy_tools' ) );
		add_action( 'admin_post_karetaker_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_karetaker_run_scan', array( __CLASS__, 'handle_run_scan' ) );
		add_action( 'admin_post_karetaker_export_events', array( __CLASS__, 'handle_export_events' ) );
		add_action( 'admin_post_karetaker_save_harden', array( __CLASS__, 'handle_save_harden' ) );
		add_action( 'admin_post_karetaker_agency_generate', array( __CLASS__, 'handle_agency_generate' ) );
		add_action( 'admin_post_karetaker_agency_regenerate', array( __CLASS__, 'handle_agency_regenerate' ) );
		add_action( 'admin_post_karetaker_agency_clear', array( __CLASS__, 'handle_agency_clear' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_settings_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_agency_token_notice' ) );
	}

	/**
	 * Adds the top-level Karetaker menu for users with manage_options.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Karetaker', 'karetaker' ),
			__( 'Karetaker', 'karetaker' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-shield-alt',
			80
		);
	}

	/**
	 * Enqueues admin CSS on the Karetaker page only.
	 *
	 * @since 0.1.0
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );

		$path = KARETAKER_DIR . 'assets/admin.css';
		wp_enqueue_style(
			'karetaker-admin',
			plugins_url( 'assets/admin.css', KARETAKER_FILE ),
			array( 'dashicons' ),
			file_exists( $path ) ? (string) filemtime( $path ) : KARETAKER_VERSION
		);

		$js_path = KARETAKER_DIR . 'assets/admin.js';
		wp_enqueue_script(
			'karetaker-admin',
			plugins_url( 'assets/admin.js', KARETAKER_FILE ),
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : KARETAKER_VERSION,
			true
		);
	}

	/**
	 * Builds an admin.php URL for the Karetaker screen.
	 *
	 * @since 0.1.0
	 * @param string $tab Optional tab slug.
	 * @return string
	 */
	public static function admin_page_url( $tab = '' ) {
		$args = array( 'page' => self::PAGE_SLUG );
		if ( '' !== $tab ) {
			$args['tab'] = sanitize_key( $tab );
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Soft-redirects legacy Tools → Karetaker bookmarks to admin.php.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function maybe_redirect_legacy_tools() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $pagenow;
		if ( 'tools.php' !== $pagenow ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( self::admin_page_url( $tab ) );
		exit;
	}

	/**
	 * Resolves the active admin tab from the request.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public static function current_tab() {
		// Tab switcher is a GET link; capability checked in render_page.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $tab, self::TABS, true ) ) {
			return 'overview';
		}

		return $tab;
	}

	/**
	 * Renders the Karetaker admin page shell and active tab.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'karetaker' ) );
		}

		$tab = self::current_tab();
		?>
		<div class="wrap karetaker-wrap karetaker-app">
			<div class="kt-shell">
				<?php self::render_header(); ?>
				<?php self::render_tabs( $tab ); ?>
				<div class="kt-body">
					<?php self::maybe_inline_notices(); ?>
					<?php
					if ( 'settings' === $tab ) {
						self::render_settings();
					} elseif ( 'activity' === $tab ) {
						self::render_activity();
					} elseif ( 'harden' === $tab ) {
						self::render_harden();
					} else {
						self::render_overview();
					}
					?>
				</div>
				<?php self::render_footer(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Outputs the branded page header.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private static function render_header() {
		?>
		<header class="kt-brand">
			<div class="kt-brand__mark" aria-hidden="true">
				<span class="dashicons dashicons-shield-alt"></span>
			</div>
			<div>
				<h1 class="kt-brand__title"><?php echo esc_html__( 'Karetaker', 'karetaker' ); ?></h1>
				<p class="kt-brand__tagline"><?php echo esc_html__( 'A watchtower for WordPress.', 'karetaker' ); ?></p>
			</div>
		</header>
		<?php
	}

	/**
	 * Outputs the small footer credit.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private static function render_footer() {
		?>
		<p class="kt-footer">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: plugin version */
					__( 'Karetaker v%s · by Team Krikir', 'karetaker' ),
					KARETAKER_VERSION
				)
			);
			?>
		</p>
		<?php
	}

	/**
	 * Outputs the tab navigation.
	 *
	 * @since 0.1.0
	 * @param string $current Active tab slug.
	 * @return void
	 */
	private static function render_tabs( $current ) {
		$base = self::admin_page_url();
		$tabs = array(
			'overview' => array(
				'label' => __( 'Overview', 'karetaker' ),
				'icon'  => 'dashicons-dashboard',
			),
			'activity' => array(
				'label' => __( 'Activity', 'karetaker' ),
				'icon'  => 'dashicons-list-view',
			),
			'harden'   => array(
				'label' => __( 'Harden', 'karetaker' ),
				'icon'  => 'dashicons-lock',
			),
			'settings' => array(
				'label' => __( 'Settings', 'karetaker' ),
				'icon'  => 'dashicons-admin-generic',
			),
		);
		?>
		<nav aria-label="<?php echo esc_attr__( 'Karetaker sections', 'karetaker' ); ?>">
			<ul class="kt-nav">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<li>
						<a
							href="<?php echo esc_url( add_query_arg( 'tab', $slug, $base ) ); ?>"
							class="kt-nav__link<?php echo $slug === $current ? ' is-active' : ''; ?>"
						>
							<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span>
							<?php echo esc_html( $tab['label'] ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
	}

	/**
	 * Inline success and scan notices inside the app shell.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private static function maybe_inline_notices() {
		if ( ! empty( $_GET['scan_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<div class="kt-alert kt-alert--success">
				<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<span><?php echo esc_html__( 'Scan completed. Results are updated below.', 'karetaker' ); ?></span>
			</div>
			<?php
		}
	}

	/**
	 * Renders the Overview intro and status cards.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private static function render_overview() {
		$state     = Karetaker_Scanner::state();
		$last_run  = isset( $state['last_run'] ) ? (string) $state['last_run'] : '';
		$results   = isset( $state['last_results'] ) && is_array( $state['last_results'] ) ? $state['last_results'] : array();
		$guard     = isset( $state['guard'] ) && is_array( $state['guard'] ) ? $state['guard'] : array();
		$since     = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
		$act_rows  = Karetaker_Events::query(
			array(
				'min_severity' => Karetaker_Events::SEVERITY_ACT,
				'since'        => $since,
				'limit'        => 500,
			)
		);
		$act_count = count( $act_rows );
		$total     = Karetaker_Schema::count();
		$next_scan = wp_next_scheduled( Karetaker_Scanner::CRON_HOOK );

		$bad_count = 0;
		foreach ( Karetaker_Guard::CHECKS as $check ) {
			$entry = isset( $guard[ $check ] ) && is_array( $guard[ $check ] ) ? $guard[ $check ] : array();
			if ( ! empty( $entry['bad'] ) ) {
				++$bad_count;
			}
		}

		if ( '' === $last_run ) {
			$last_scan_label   = __( 'Never', 'karetaker' );
			$metric_scan_class = 'is-warn';
		} else {
			$last_ts           = strtotime( $last_run . ' UTC' );
			$last_scan_label   = human_time_diff( $last_ts, time() ) . ' ' . __( 'ago', 'karetaker' );
			$metric_scan_class = ( time() - $last_ts ) > Karetaker_Site_Health::STALE_SECONDS ? 'is-warn' : 'is-ok';
		}

		if ( $bad_count ) {
			$guard_label = sprintf(
				/* translators: %d: number of bad guard checks */
				_n( '%d flag bad', '%d flags bad', $bad_count, 'karetaker' ),
				$bad_count
			);
			$metric_guard_class = 'is-bad';
		} else {
			$guard_label        = __( 'All ok', 'karetaker' );
			$metric_guard_class = 'is-ok';
		}

		$metric_act_class = $act_count > 0 ? 'is-bad' : 'is-ok';

		include KARETAKER_DIR . 'includes/admin/partials/overview.php';
	}

	/**
	 * Renders the Activity list table.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private static function render_activity() {
		$table = new Karetaker_List_Table();
		$table->prepare_items();

		$filters       = Karetaker_List_Table::filter_args_from_request();
		$filter_sev    = isset( $_GET['kt_sev'] ) ? sanitize_key( wp_unslash( $_GET['kt_sev'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_code   = isset( $filters['code'] ) ? (string) $filters['code'] : '';
		$filter_since  = ! empty( $filters['since'] ) ? substr( (string) $filters['since'], 0, 10 ) : '';
		$filter_until  = ! empty( $filters['until'] ) ? substr( (string) $filters['until'], 0, 10 ) : '';
		$filter_search = isset( $filters['search'] ) ? (string) $filters['search'] : '';

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'karetaker_export_events',
				),
				admin_url( 'admin-post.php' )
			),
			'karetaker_export_events'
		);
		$export_url = add_query_arg( Karetaker_List_Table::filter_query_for_url( $filters ), $export_url );

		include KARETAKER_DIR . 'includes/admin/partials/activity.php';
	}

	/**
	 * Renders the Harden toggles form (manage_options + nonce on save).
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private static function render_harden() {
		$desired       = Karetaker_Harden::desired();
		$meta          = self::harden_field_meta();
		$harden_groups = array(
			__( 'Headers & exposure', 'karetaker' ) => array( 'headers', 'xmlrpc', 'version' ),
			__( 'Access & accounts', 'karetaker' )  => array( 'file_editor', 'user_enum', 'registration', 'app_passwords' ),
		);

		include KARETAKER_DIR . 'includes/admin/partials/harden.php';
	}

	/**
	 * Labels and help text for each harden toggle.
	 *
	 * @since 0.1.0
	 * @return array<string, array{label: string, help: string}>
	 */
	private static function harden_field_meta() {
		return array(
			'headers'       => array(
				'label' => __( 'Security headers', 'karetaker' ),
				'help'  => __( 'Send nosniff, frame, referrer, and permissions headers. No CSP. HSTS only on SSL outside local.', 'karetaker' ),
			),
			'xmlrpc'        => array(
				'label' => __( 'Disable XML-RPC', 'karetaker' ),
				'help'  => __( 'Turn off XML-RPC and strip pingback methods / X-Pingback.', 'karetaker' ),
			),
			'file_editor'   => array(
				'label' => __( 'Block file editor', 'karetaker' ),
				'help'  => __( 'Define DISALLOW_FILE_EDIT for this request if the host has not already.', 'karetaker' ),
			),
			'user_enum'     => array(
				'label' => __( 'Block user enumeration', 'karetaker' ),
				'help'  => __( 'Hide REST user routes for guests and redirect digit-only ?author= queries.', 'karetaker' ),
			),
			'version'       => array(
				'label' => __( 'Hide WordPress version', 'karetaker' ),
				'help'  => __( 'Remove the generator and strip ver= from script and style URLs.', 'karetaker' ),
			),
			'registration'  => array(
				'label' => __( 'Close registration', 'karetaker' ),
				'help'  => __( 'Force users_can_register closed via filter without writing the option.', 'karetaker' ),
			),
			'app_passwords' => array(
				'label' => __( 'Limit application passwords', 'karetaker' ),
				'help'  => __( 'Allow application passwords only for users with manage_options.', 'karetaker' ),
			),
		);
	}

	/**
	 * Renders alerts, row-cap, proxy, and agency settings forms.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private static function render_settings() {
		$settings        = Karetaker_Settings::all();
		$email           = isset( $settings['alert_email'] ) ? (string) $settings['alert_email'] : '';
		$enabled         = ! empty( $settings['alerts_enabled'] );
		$row_cap         = Karetaker_Settings::row_cap();
		$proxies         = isset( $settings['trusted_proxies'] ) && is_array( $settings['trusted_proxies'] )
			? $settings['trusted_proxies']
			: array();
		$proxies_text    = implode( "\n", array_map( 'strval', $proxies ) );
		$webhook_enabled = ! empty( $settings['webhook_enabled'] );
		$webhook_url     = isset( $settings['webhook_url'] ) ? (string) $settings['webhook_url'] : '';
		$webhook_secret  = isset( $settings['webhook_secret'] ) ? (string) $settings['webhook_secret'] : '';
		?>
		<form class="karetaker-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="karetaker_save_settings" />
			<?php wp_nonce_field( 'karetaker_save_settings' ); ?>
			<div class="kt-panel kt-form-table">
				<div class="kt-panel__head">
					<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
					<h2><?php echo esc_html__( 'Alerts', 'karetaker' ); ?></h2>
				</div>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="karetaker_alert_email"><?php echo esc_html__( 'Alert email', 'karetaker' ); ?></label>
					</th>
					<td>
						<input
							type="email"
							class="regular-text"
							id="karetaker_alert_email"
							name="alert_email"
							value="<?php echo esc_attr( $email ); ?>"
							placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"
						/>
						<p class="description">
							<?php echo esc_html__( 'Leave blank to use the site admin email. Turn off Enable alerts to stop ACT emails.', 'karetaker' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Enable alerts', 'karetaker' ); ?></th>
					<td>
						<label for="karetaker_alerts_enabled">
							<input
								type="checkbox"
								id="karetaker_alerts_enabled"
								name="alerts_enabled"
								value="1"
								<?php checked( $enabled ); ?>
							/>
							<?php echo esc_html__( 'Send email when an ACT event is recorded', 'karetaker' ); ?>
						</label>
					</td>
				</tr>
			</table>
			</div>
			<div class="kt-panel kt-form-table">
				<div class="kt-panel__head">
					<span class="dashicons dashicons-database" aria-hidden="true"></span>
					<h2><?php echo esc_html__( 'Event retention', 'karetaker' ); ?></h2>
				</div>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="karetaker_row_cap"><?php echo esc_html__( 'Event row cap', 'karetaker' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							class="small-text"
							id="karetaker_row_cap"
							name="row_cap"
							min="<?php echo esc_attr( (string) Karetaker_Settings::ROW_CAP_MIN ); ?>"
							max="<?php echo esc_attr( (string) Karetaker_Settings::ROW_CAP_MAX ); ?>"
							step="1"
							value="<?php echo esc_attr( (string) $row_cap ); ?>"
						/>
						<p class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: minimum, 2: maximum */
									__( 'Between %1$d and %2$d. Oldest events are trimmed when the cap is exceeded.', 'karetaker' ),
									Karetaker_Settings::ROW_CAP_MIN,
									Karetaker_Settings::ROW_CAP_MAX
								)
							);
							?>
						</p>
					</td>
				</tr>
			</table>
			</div>
			<div class="kt-panel kt-form-table">
				<div class="kt-panel__head">
					<span class="dashicons dashicons-networking" aria-hidden="true"></span>
					<h2><?php echo esc_html__( 'Trusted proxies', 'karetaker' ); ?></h2>
				</div>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="karetaker_trusted_proxies"><?php echo esc_html__( 'Trusted proxies', 'karetaker' ); ?></label>
					</th>
					<td>
						<textarea
							class="large-text code"
							rows="4"
							id="karetaker_trusted_proxies"
							name="trusted_proxies"
						><?php echo esc_textarea( $proxies_text ); ?></textarea>
						<p class="description">
							<?php echo esc_html__( 'One CIDR or IP per line. Used when reading client IP behind a reverse proxy.', 'karetaker' ); ?>
						</p>
					</td>
				</tr>
			</table>
			</div>
			<div class="kt-panel kt-form-table">
				<div class="kt-panel__head">
					<span class="dashicons dashicons-share" aria-hidden="true"></span>
					<h2><?php echo esc_html__( 'ACT webhook', 'karetaker' ); ?></h2>
				</div>
				<p class="description" style="margin:12px 16px 0;"><?php echo esc_html__( 'Optional: POST act-now events to your endpoint (single attempt, HMAC-SHA256 when secret is set).', 'karetaker' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Enable webhook', 'karetaker' ); ?></th>
						<td>
							<label for="karetaker_webhook_enabled">
								<input type="checkbox" id="karetaker_webhook_enabled" name="webhook_enabled" value="1" <?php checked( $webhook_enabled ); ?> />
								<?php echo esc_html__( 'Send ACT events to webhook URL', 'karetaker' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="karetaker_webhook_url"><?php echo esc_html__( 'Webhook URL', 'karetaker' ); ?></label></th>
						<td><input type="url" class="large-text" id="karetaker_webhook_url" name="webhook_url" value="<?php echo esc_attr( $webhook_url ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="karetaker_webhook_secret"><?php echo esc_html__( 'Signing secret', 'karetaker' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="karetaker_webhook_secret" name="webhook_secret" value="<?php echo esc_attr( $webhook_secret ); ?>" autocomplete="new-password" />
							<p class="description"><?php echo esc_html__( 'Sent as X-Karetaker-Signature: sha256=… over the JSON body.', 'karetaker' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
			<div class="kt-sticky-save">
				<button type="submit" class="kt-btn kt-btn--primary">
					<span class="dashicons dashicons-saved" aria-hidden="true"></span>
					<?php echo esc_html__( 'Save settings', 'karetaker' ); ?>
				</button>
			</div>
		</form>
		<?php self::render_agency_settings(); ?>
		<?php
	}

	/**
	 * Renders agency token generate / regenerate / clear controls.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private static function render_agency_settings() {
		$token      = Karetaker_Settings::agency_token();
		$has        = '' !== $token;
		$status_url = rest_url( 'karetaker/v1/status' );
		?>
		<div class="kt-panel">
			<div class="kt-panel__head">
				<span class="dashicons dashicons-rest-api" aria-hidden="true"></span>
				<h2><?php echo esc_html__( 'Agency channel', 'karetaker' ); ?></h2>
			</div>
			<div class="kt-panel__body">
			<p class="description">
				<?php echo esc_html__( 'Read-only signed status for remote monitoring. Empty token keeps the channel off.', 'karetaker' ); ?>
			</p>
		<?php if ( ! $has ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="karetaker_agency_generate" />
				<?php wp_nonce_field( 'karetaker_agency_token' ); ?>
				<button type="submit" class="kt-btn kt-btn--primary">
					<span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
					<?php echo esc_html__( 'Generate token', 'karetaker' ); ?>
				</button>
			</form>
		<?php else : ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Current token', 'karetaker' ); ?></th>
					<td>
						<code><?php echo esc_html( Karetaker_Agency::mask_token( $token ) ); ?></code>
						<p class="description">
							<?php
							echo esc_html__(
								'Copy the full token from the one-time notice after generate or regenerate. It is not shown again.',
								'karetaker'
							);
							?>
						</p>
						<p class="description">
							<code>Authorization: Bearer &lt;token&gt;</code>
						</p>
						<p class="description">
							<code>curl -H "Authorization: Bearer TOKEN" <?php echo esc_html( $status_url ); ?></code>
						</p>
					</td>
				</tr>
			</table>
			<div class="kt-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="karetaker_agency_regenerate" />
					<?php wp_nonce_field( 'karetaker_agency_token' ); ?>
					<button type="submit" class="kt-btn kt-btn--secondary">
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<?php echo esc_html__( 'Regenerate', 'karetaker' ); ?>
					</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="karetaker_agency_clear" />
					<?php wp_nonce_field( 'karetaker_agency_token' ); ?>
					<button type="submit" class="kt-btn kt-btn--secondary">
						<span class="dashicons dashicons-trash" aria-hidden="true"></span>
						<?php echo esc_html__( 'Clear', 'karetaker' ); ?>
					</button>
				</form>
			</div>
		<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Runs a full scan from the admin UI.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function handle_run_scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run scans.', 'karetaker' ) );
		}

		check_admin_referer( 'karetaker_run_scan' );

		Karetaker_Scanner::run();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE_SLUG,
					'tab'       => 'overview',
					'scan_done' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Exports filtered events as CSV.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function handle_export_events() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export events.', 'karetaker' ) );
		}

		check_admin_referer( 'karetaker_export_events' );

		$filters = Karetaker_List_Table::filter_args_from_request();
		$rows    = Karetaker_Events::query(
			array_merge(
				$filters,
				array(
					'limit'  => 5000,
					'offset' => 0,
				)
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=karetaker-events.csv' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		fputcsv( $out, array( 'id', 'event_time', 'severity', 'event_code', 'user_id', 'ip', 'context' ) );

		foreach ( $rows as $row ) {
			$context = is_array( $row->context ) ? $row->context : array();
			$json    = wp_json_encode( $context );
			if ( false === $json ) {
				$json = '{}';
			}
			fputcsv(
				$out,
				array(
					(int) $row->id,
					(string) $row->event_time,
					(int) $row->severity,
					(string) $row->event_code,
					(int) $row->user_id,
					(string) $row->ip_display,
					$json,
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Saves Settings tab fields. Requires manage_options and karetaker_save_settings nonce.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'karetaker' ) );
		}

		check_admin_referer( 'karetaker_save_settings' );

		$email = isset( $_POST['alert_email'] ) ? sanitize_email( wp_unslash( $_POST['alert_email'] ) ) : '';
		if ( '' !== $email && ! is_email( $email ) ) {
			$email = '';
		}

		$enabled = ! empty( $_POST['alerts_enabled'] );

		if ( isset( $_POST['row_cap'] ) ) {
			$row_cap = absint( wp_unslash( $_POST['row_cap'] ) );
		} else {
			$row_cap = Karetaker_Settings::ROW_CAP_MIN;
		}

		$proxies_raw = isset( $_POST['trusted_proxies'] )
			? sanitize_textarea_field( wp_unslash( $_POST['trusted_proxies'] ) )
			: '';
		$proxies     = self::sanitize_proxy_lines( $proxies_raw );

		$webhook_enabled = ! empty( $_POST['webhook_enabled'] );
		$webhook_url     = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';
		$webhook_secret  = isset( $_POST['webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ) ) : '';

		Karetaker_Settings::update(
			array(
				'alert_email'     => $email,
				'alerts_enabled'  => (bool) $enabled,
				'row_cap'         => $row_cap,
				'trusted_proxies' => $proxies,
				'webhook_enabled' => (bool) $webhook_enabled,
				'webhook_url'     => $webhook_url,
				'webhook_secret'  => $webhook_secret,
			)
		);

		Karetaker_Settings::update(
			array(
				'row_cap' => Karetaker_Settings::row_cap(),
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::PAGE_SLUG,
					'tab'             => 'settings',
					'karetaker_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Saves Harden Desired map and records setting_changed. Requires manage_options and nonce.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function handle_save_harden() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'karetaker' ) );
		}

		check_admin_referer( 'karetaker_save_harden' );

		$posted = array();
		if ( isset( $_POST['harden'] ) && is_array( $_POST['harden'] ) ) {
			$raw = wp_unslash( $_POST['harden'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- keys checked against KEYS below
			foreach ( Karetaker_Harden::KEYS as $key ) {
				if ( isset( $raw[ $key ] ) ) {
					$posted[ $key ] = sanitize_text_field( (string) $raw[ $key ] );
				}
			}
		}
		$next = Karetaker_Harden::defaults();

		foreach ( Karetaker_Harden::KEYS as $key ) {
			$next[ $key ] = ! empty( $posted[ $key ] );
		}

		$prev = Karetaker_Settings::harden();

		foreach ( Karetaker_Harden::KEYS as $key ) {
			if ( (bool) $prev[ $key ] !== (bool) $next[ $key ] ) {
				Karetaker_Events::record(
					'setting_changed',
					array(
						'option' => 'harden.' . $key,
						'value'  => $next[ $key ] ? '1' : '0',
					)
				);
			}
		}

		Karetaker_Settings::update( array( 'harden' => $next ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'tab'     => 'harden',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Generates a new agency token.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function handle_agency_generate() {
		self::handle_agency_token_action( 'generated' );
	}

	/**
	 * Regenerates the agency token, invalidating the previous one.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function handle_agency_regenerate() {
		self::handle_agency_token_action( 'regenerated' );
	}

	/**
	 * Clears the agency token and turns the channel off.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function handle_agency_clear() {
		self::handle_agency_token_action( 'cleared' );
	}

	/**
	 * Shared generate / regenerate / clear handler. Requires manage_options and agency nonce.
	 *
	 * @since 0.1.0
	 * @param string $value Audit value: generated, regenerated, or cleared.
	 * @return void
	 */
	private static function handle_agency_token_action( $value ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change the agency token.', 'karetaker' ) );
		}

		check_admin_referer( 'karetaker_agency_token' );

		if ( 'cleared' === $value ) {
			Karetaker_Settings::update( array( 'agency_token' => '' ) );
			Karetaker_Events::record(
				'setting_changed',
				array(
					'option' => 'agency_token',
					'value'  => 'cleared',
				)
			);
		} else {
			$token = Karetaker_Agency::generate_token();
			Karetaker_Settings::update( array( 'agency_token' => $token ) );
			Karetaker_Events::record(
				'setting_changed',
				array(
					'option' => 'agency_token',
					'value'  => $value,
				)
			);
			set_transient( self::agency_token_once_key(), $token, 60 );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => 'settings',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Transient key for the one-time plaintext token notice.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	private static function agency_token_once_key() {
		return 'karetaker_agency_token_once_' . get_current_user_id();
	}

	/**
	 * Shows the one-time full agency token after generate or regenerate.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function maybe_agency_token_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		$key   = self::agency_token_once_key();
		$token = get_transient( $key );
		if ( false === $token || '' === (string) $token ) {
			return;
		}

		delete_transient( $key );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php echo esc_html__( 'Copy your agency token now.', 'karetaker' ); ?></strong>
				<?php echo esc_html__( 'It will not be shown again.', 'karetaker' ); ?>
			</p>
			<p><code><?php echo esc_html( (string) $token ); ?></code></p>
		</div>
		<?php
	}

	/**
	 * Shows a success notice after settings or harden save redirects.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function maybe_settings_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		$saved_settings = ! empty( $_GET['karetaker_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saved_harden   = ! empty( $_GET['updated'] ) && 'harden' === self::current_tab(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $saved_settings && ! $saved_harden ) {
			return;
		}
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html__( 'Settings saved.', 'karetaker' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Parses textarea lines into unique plausible IPs or CIDRs.
	 *
	 * @since 0.1.0
	 * @param string $raw Raw textarea value.
	 * @return string[]
	 */
	private static function sanitize_proxy_lines( $raw ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		$clean = array();

		if ( ! is_array( $lines ) ) {
			return $clean;
		}

		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' === $line ) {
				continue;
			}

			if ( ! self::is_plausible_proxy( $line ) ) {
				continue;
			}

			$clean[] = $line;
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Whether a line looks like an IPv4/IPv6 address or CIDR.
	 *
	 * @since 0.1.0
	 * @param string $value Single proxy line.
	 * @return bool
	 */
	private static function is_plausible_proxy( $value ) {
		if ( false !== strpos( $value, '/' ) ) {
			$parts = explode( '/', $value, 2 );
			$ip    = $parts[0];
			$mask  = isset( $parts[1] ) ? $parts[1] : '';

			if ( ! is_numeric( $mask ) ) {
				return false;
			}

			$mask = (int) $mask;
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return $mask >= 0 && $mask <= 32;
			}
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				return $mask >= 0 && $mask <= 128;
			}

			return false;
		}

		return (bool) filter_var( $value, FILTER_VALIDATE_IP );
	}
}
