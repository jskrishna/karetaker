<?php
/**
 * Tools → Karetaker admin UI.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools → Karetaker admin UI and save handlers.
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
		add_action( 'admin_post_karetaker_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_karetaker_save_harden', array( __CLASS__, 'handle_save_harden' ) );
		add_action( 'admin_post_karetaker_agency_generate', array( __CLASS__, 'handle_agency_generate' ) );
		add_action( 'admin_post_karetaker_agency_regenerate', array( __CLASS__, 'handle_agency_regenerate' ) );
		add_action( 'admin_post_karetaker_agency_clear', array( __CLASS__, 'handle_agency_clear' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_settings_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_agency_token_notice' ) );
	}

	/**
	 * Adds Tools → Karetaker for users with manage_options.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function register_menu() {
		add_management_page(
			__( 'Karetaker', 'karetaker' ),
			__( 'Karetaker', 'karetaker' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Resolves the active admin tab from the request.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';

		if ( ! in_array( $tab, self::TABS, true ) ) {
			return 'overview';
		}

		return $tab;
	}

	/**
	 * Renders the Karetaker Tools page shell and active tab.
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
		<div class="wrap">
			<h1><?php echo esc_html__( 'Karetaker', 'karetaker' ); ?></h1>
			<?php self::render_tabs( $tab ); ?>
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
		$base = admin_url( 'tools.php?page=' . self::PAGE_SLUG );
		$labels = array(
			'overview'  => __( 'Overview', 'karetaker' ),
			'activity'  => __( 'Activity', 'karetaker' ),
			'harden'    => __( 'Harden', 'karetaker' ),
			'settings'  => __( 'Settings', 'karetaker' ),
		);
		?>
		<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Karetaker sections', 'karetaker' ); ?>">
			<?php foreach ( $labels as $slug => $label ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', $slug, $base ) ); ?>"
					class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>"
				><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Renders the Overview tab summary table.
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
		?>
		<div class="karetaker-overview" style="max-width:720px;margin-top:1em;">
			<table class="widefat striped">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last scan', 'karetaker' ); ?></th>
						<td>
							<?php
							if ( '' === $last_run ) {
								echo esc_html__( 'Never', 'karetaker' );
							} else {
								echo esc_html( $last_run . ' UTC' );
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last scan results', 'karetaker' ); ?></th>
						<td>
							<?php if ( empty( $results ) ) : ?>
								<?php echo esc_html__( 'None yet', 'karetaker' ); ?>
							<?php else : ?>
								<ul style="margin:0;">
									<?php foreach ( $results as $scan => $status ) : ?>
										<li>
											<code><?php echo esc_html( (string) $scan ); ?></code>:
											<?php echo esc_html( (string) $status ); ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Guard flags', 'karetaker' ); ?></th>
						<td>
							<ul style="margin:0;">
								<?php foreach ( Karetaker_Guard::CHECKS as $check ) : ?>
									<?php
									$entry = isset( $guard[ $check ] ) && is_array( $guard[ $check ] ) ? $guard[ $check ] : array();
									$bad   = ! empty( $entry['bad'] );
									$since_check = ( $bad && ! empty( $entry['since'] ) ) ? (string) $entry['since'] : '';
									?>
									<li>
										<code><?php echo esc_html( $check ); ?></code>:
										<?php
										echo $bad
											? esc_html__( 'bad', 'karetaker' )
											: esc_html__( 'ok', 'karetaker' );
										if ( $since_check ) {
											/* translators: %s: MySQL UTC datetime */
											echo ' ' . esc_html( sprintf( __( '(since %s UTC)', 'karetaker' ), $since_check ) );
										}
										?>
									</li>
								<?php endforeach; ?>
							</ul>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'ACT events this week', 'karetaker' ); ?></th>
						<td><?php echo esc_html( (string) $act_count ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Events stored', 'karetaker' ); ?></th>
						<td><?php echo esc_html( (string) $total ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Agency channel', 'karetaker' ); ?></th>
						<td>
							<?php
							echo '' === Karetaker_Settings::agency_token()
								? esc_html__( 'Off', 'karetaker' )
								: esc_html__( 'On', 'karetaker' );
							?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
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
		?>
		<div style="margin-top:1em;">
			<?php $table->display(); ?>
		</div>
		<?php
	}

	/**
	 * Renders the Harden toggles form (manage_options + nonce on save).
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private static function render_harden() {
		$desired = Karetaker_Harden::desired();
		$meta    = self::harden_field_meta();

		if ( karetaker_is_disabled() ) {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html__( 'Karetaker is disabled (kill switch). Harden toggles are not applying.', 'karetaker' ); ?></p>
			</div>
			<?php
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em;max-width:960px;">
			<input type="hidden" name="action" value="karetaker_save_harden" />
			<?php wp_nonce_field( 'karetaker_save_harden' ); ?>
			<table class="widefat striped" style="margin-top:1em;">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Toggle', 'karetaker' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Desired', 'karetaker' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Live now', 'karetaker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( Karetaker_Harden::KEYS as $key ) : ?>
						<?php
						$label = isset( $meta[ $key ]['label'] ) ? $meta[ $key ]['label'] : $key;
						$help  = isset( $meta[ $key ]['help'] ) ? $meta[ $key ]['help'] : '';
						$id    = 'karetaker_harden_' . $key;
						?>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
								<?php if ( '' !== $help ) : ?>
									<p class="description"><?php echo esc_html( $help ); ?></p>
								<?php endif; ?>
							</th>
							<td>
								<input
									type="checkbox"
									id="<?php echo esc_attr( $id ); ?>"
									name="harden[<?php echo esc_attr( $key ); ?>]"
									value="1"
									<?php checked( ! empty( $desired[ $key ] ) ); ?>
								/>
							</td>
							<td><?php echo esc_html( Karetaker_Harden::probe( $key ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Save Harden settings', 'karetaker' ) ); ?>
		</form>
		<?php
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
		$settings = Karetaker_Settings::all();
		$email    = isset( $settings['alert_email'] ) ? (string) $settings['alert_email'] : '';
		$enabled  = ! empty( $settings['alerts_enabled'] );
		$row_cap  = Karetaker_Settings::row_cap();
		$proxies  = isset( $settings['trusted_proxies'] ) && is_array( $settings['trusted_proxies'] )
			? $settings['trusted_proxies']
			: array();
		$proxies_text = implode( "\n", array_map( 'strval', $proxies ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em;max-width:640px;">
			<input type="hidden" name="action" value="karetaker_save_settings" />
			<?php wp_nonce_field( 'karetaker_save_settings' ); ?>
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
			<?php submit_button( __( 'Save settings', 'karetaker' ) ); ?>
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
		$token = Karetaker_Settings::agency_token();
		$has   = '' !== $token;
		$status_url = rest_url( 'karetaker/v1/status' );
		?>
		<hr style="margin:2em 0 1.5em;" />
		<h2><?php echo esc_html__( 'Agency channel', 'karetaker' ); ?></h2>
		<p class="description">
			<?php echo esc_html__( 'Read-only signed status for remote monitoring. Empty token keeps the channel off.', 'karetaker' ); ?>
		</p>
		<?php if ( ! $has ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="karetaker_agency_generate" />
				<?php wp_nonce_field( 'karetaker_agency_token' ); ?>
				<?php submit_button( __( 'Generate token', 'karetaker' ), 'secondary', 'submit', false ); ?>
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
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:0.5em;">
				<input type="hidden" name="action" value="karetaker_agency_regenerate" />
				<?php wp_nonce_field( 'karetaker_agency_token' ); ?>
				<?php submit_button( __( 'Regenerate', 'karetaker' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
				<input type="hidden" name="action" value="karetaker_agency_clear" />
				<?php wp_nonce_field( 'karetaker_agency_token' ); ?>
				<?php submit_button( __( 'Clear', 'karetaker' ), 'delete', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
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

		$row_cap = isset( $_POST['row_cap'] ) ? (int) wp_unslash( $_POST['row_cap'] ) : Karetaker_Settings::ROW_CAP_MIN;

		$proxies_raw = isset( $_POST['trusted_proxies'] ) ? (string) wp_unslash( $_POST['trusted_proxies'] ) : '';
		$proxies     = self::sanitize_proxy_lines( $proxies_raw );

		Karetaker_Settings::update(
			array(
				'alert_email'     => $email,
				'alerts_enabled'  => (bool) $enabled,
				'row_cap'         => $row_cap,
				'trusted_proxies' => $proxies,
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
					'page'             => self::PAGE_SLUG,
					'tab'              => 'settings',
					'karetaker_saved'  => '1',
				),
				admin_url( 'tools.php' )
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

		$posted = isset( $_POST['harden'] ) && is_array( $_POST['harden'] ) ? wp_unslash( $_POST['harden'] ) : array();
		$next   = Karetaker_Harden::defaults();

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
				admin_url( 'tools.php' )
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
				admin_url( 'tools.php' )
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

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
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

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		$saved_settings = ! empty( $_GET['karetaker_saved'] );
		$saved_harden   = ! empty( $_GET['updated'] ) && 'harden' === self::current_tab();
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
