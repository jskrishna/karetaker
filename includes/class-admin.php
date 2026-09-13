<?php
/**
 * Tools → Karetaker admin UI.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Karetaker_Admin {

	const PAGE_SLUG = 'karetaker';

	const TABS = array( 'overview', 'activity', 'settings' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_karetaker_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_settings_notice' ) );
	}

	public static function register_menu() {
		add_management_page(
			__( 'Karetaker', 'karetaker' ),
			__( 'Karetaker', 'karetaker' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';

		if ( ! in_array( $tab, self::TABS, true ) ) {
			return 'overview';
		}

		return $tab;
	}

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
			} else {
				self::render_overview();
			}
			?>
		</div>
		<?php
	}

	private static function render_tabs( $current ) {
		$base = admin_url( 'tools.php?page=' . self::PAGE_SLUG );
		$labels = array(
			'overview'  => __( 'Overview', 'karetaker' ),
			'activity'  => __( 'Activity', 'karetaker' ),
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
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_activity() {
		$table = new Karetaker_List_Table();
		$table->prepare_items();
		?>
		<div style="margin-top:1em;">
			<?php $table->display(); ?>
		</div>
		<?php
	}

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
		<?php
	}

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

	public static function maybe_settings_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		if ( empty( $_GET['karetaker_saved'] ) ) {
			return;
		}
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html__( 'Settings saved.', 'karetaker' ); ?></p>
		</div>
		<?php
	}

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
