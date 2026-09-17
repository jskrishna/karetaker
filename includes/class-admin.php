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
 * Admin screen shell: menu, assets, setup, navigation, and the screens.
 *
 * Simple mode (default) shows Home, Activity, Protection and Settings. Advanced mode adds
 * Issues, Monitoring, Incidents, Reports and Access & API. Actions go through
 * Karetaker_Admin_Rest; downloads through Karetaker_Exports.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Admin {

	const PAGE_SLUG = 'karetaker';

	/**
	 * Data handed to admin.js (issues and events shown in the side panels).
	 *
	 * @var array<string, array<int|string, mixed>>
	 */
	private static $payload = array(
		'items' => array(),
	);

	/**
	 * Registers the menu page, assets, and remaining admin-post handlers.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_legacy_tools' ) );
		add_action( 'admin_post_karetaker_dismiss_mail_failed', array( __CLASS__, 'handle_dismiss_mail_failed' ) );
		add_action( 'admin_post_karetaker_email_preview', array( __CLASS__, 'handle_email_preview' ) );
	}

	/**
	 * Adds the top-level Karetaker menu for anyone who may view Karetaker.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Karetaker', 'karetaker' ),
			__( 'Karetaker', 'karetaker' ),
			'karetaker_view',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			self::menu_icon_data_uri(),
			80
		);
	}

	/**
	 * Returns a white menu mark as a data URI (avoids SVG file-cache staying gray).
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private static function menu_icon_data_uri() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><circle cx="10" cy="4" r="2.2" fill="#ffffff"/><rect x="7.6" y="7.2" width="4.8" height="2.2" fill="#ffffff"/><rect x="6" y="11" width="8" height="2.2" fill="#ffffff"/><rect x="4.4" y="14.8" width="11.2" height="2.2" fill="#ffffff"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- SVG data URI for admin menu icon.
	}

	/**
	 * Enqueues admin CSS and JS on the Karetaker page only.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		if ( 'reports' === self::current_tab() ) {
			wp_enqueue_media();
		}

		$path = KARETAKER_DIR . 'assets/admin.css';
		wp_enqueue_style(
			'karetaker-admin',
			plugins_url( 'assets/admin.css', KARETAKER_FILE ),
			array(),
			file_exists( $path ) ? (string) filemtime( $path ) : KARETAKER_VERSION
		);

		$js_path = KARETAKER_DIR . 'assets/admin.js';
		wp_enqueue_script(
			'karetaker-admin',
			plugins_url( 'assets/admin.js', KARETAKER_FILE ),
			array( 'wp-api-fetch' ),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : KARETAKER_VERSION,
			true
		);
		wp_localize_script(
			'karetaker-admin',
			'karetakerL10n',
			/**
			 * Filters the strings handed to the admin scripts.
			 *
			 * @since 1.0.2
			 * @param array $strings Key => translated string.
			 */
			apply_filters(
				'karetaker_admin_l10n',
				array(
					'saved'        => __( 'Saved.', 'karetaker' ),
					'error'        => __( 'That didn’t work. Please try again.', 'karetaker' ),
					'checking'     => __( 'Checking…', 'karetaker' ),
					'checkDone'    => __( 'Check finished.', 'karetaker' ),
					'setupDone'    => __( 'You’re set. Karetaker is now watching your site.', 'karetaker' ),
					/* translators: %d: number of things found */
					'foundSome'    => __( 'We found %d things to look at.', 'karetaker' ),
					'foundOne'     => __( 'We found 1 thing to look at.', 'karetaker' ),
					'foundNone'    => __( 'Nothing needs you right now.', 'karetaker' ),
					'foundSub'     => __( 'We’ll show you exactly what to do on the Home screen.', 'karetaker' ),
					'noneSub'      => __( 'Karetaker keeps watching in the background.', 'karetaker' ),
					'ok'           => __( 'OK', 'karetaker' ),
					'finish'       => __( 'Finish', 'karetaker' ),
					'continue'     => __( 'Continue', 'karetaker' ),
					'mine'         => __( 'Got it. We won’t flag this change again.', 'karetaker' ),
					'fixed'        => __( 'Marked as fixed. We’ll confirm on the next check.', 'karetaker' ),
					'paused'       => __( 'Alerts paused for 1 hour. Everything is still recorded.', 'karetaker' ),
					'resumed'      => __( 'Alerts resumed.', 'karetaker' ),
					/* translators: %s: destination name */
					'connected'    => __( '%s connected.', 'karetaker' ),
					/* translators: %s: destination name */
					'removed'      => __( '%s removed.', 'karetaker' ),
					/* translators: %s: destination names */
					'testSent'     => __( 'Test alert sent to %s.', 'karetaker' ),
					'testNone'     => __( 'No test alert was sent. Connect a destination and turn on a rule first.', 'karetaker' ),
					'signedOut'    => __( 'Signed out.', 'karetaker' ),
					'signedOutAll' => __( 'Every other administrator session was signed out.', 'karetaker' ),
					'confirmAll'   => __( 'Sign out every administrator everywhere, except you here?', 'karetaker' ),
				)
			)
		);
	}

	/**
	 * Builds an admin.php URL for the Karetaker screen.
	 *
	 * @since 1.0.0
	 * @param string               $tab  Optional tab slug.
	 * @param array<string, mixed> $args Extra query args.
	 * @return string
	 */
	public static function admin_page_url( $tab = '', array $args = array() ) {
		$query = array( 'page' => self::PAGE_SLUG );
		if ( '' !== $tab ) {
			$query['tab'] = sanitize_key( $tab );
		}
		return add_query_arg( array_merge( $query, $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Soft-redirects legacy Tools → Karetaker bookmarks to admin.php.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function maybe_redirect_legacy_tools() {
		if ( ! is_admin() || ! current_user_can( 'karetaker_view' ) ) {
			return;
		}

		global $pagenow;
		if ( 'tools.php' !== $pagenow ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Soft redirect of legacy bookmark; capability already checked.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( self::admin_page_url( $tab ) );
		exit;
	}

	/**
	 * Screens Karetaker can show, including any added by add-ons.
	 *
	 * @since 1.0.2
	 * @param bool $refresh Rebuild instead of using the cached list.
	 * @return array<string, array{label: string, cap: string, file: string, group: string}>
	 */
	public static function tabs( $refresh = false ) {
		static $tabs = null;
		if ( null !== $tabs && ! $refresh ) {
			return $tabs;
		}
		$tabs = array(
			'home'       => self::tab_entry( __( 'Home', 'karetaker' ), 'karetaker_view', 'home', 'main' ),
			'activity'   => self::tab_entry( __( 'Activity', 'karetaker' ), 'karetaker_view_log', 'activity', 'main' ),
			'protection' => self::tab_entry( __( 'Protection', 'karetaker' ), 'manage_options', 'protection', 'main' ),
			'settings'   => self::tab_entry( __( 'Settings', 'karetaker' ), 'manage_options', 'settings', 'main' ),
		);

		/**
		 * Filters the Karetaker screens.
		 *
		 * @since 1.0.2
		 * @param array $tabs Slug => label, cap, file (absolute path), group ('main' or 'pro').
		 */
		$tabs = (array) apply_filters( 'karetaker_admin_tabs', $tabs );

		return $tabs;
	}

	/**
	 * One built-in screen for the tab list.
	 *
	 * @since 1.0.2
	 * @param string $label   Menu label.
	 * @param string $cap     Capability needed.
	 * @param string $partial Partial file name without .php.
	 * @param string $group   'main' or 'pro'.
	 * @return array{label: string, cap: string, file: string, group: string}
	 */
	private static function tab_entry( $label, $cap, $partial, $group ) {
		return array(
			'label' => $label,
			'cap'   => $cap,
			'file'  => KARETAKER_DIR . 'includes/admin/partials/' . $partial . '.php',
			'group' => $group,
		);
	}

	/**
	 * Capability each screen needs.
	 *
	 * @since 1.0.0
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private static function tab_cap( $tab ) {
		$tabs = self::tabs();
		return isset( $tabs[ $tab ]['cap'] ) ? (string) $tabs[ $tab ]['cap'] : 'manage_options';
	}

	/**
	 * Resolves the active screen from the request, mapping pre-2.4 tab names.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function current_tab() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Tab switcher is a GET link; capability checked below.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'home';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$legacy = array(
			'overview' => 'home',
			'harden'   => 'protection',
			'watch'    => 'monitoring',
			'alerts'   => isset( self::tabs()['incidents'] ) ? 'incidents' : 'settings',
		);
		if ( isset( $legacy[ $tab ] ) ) {
			$tab = $legacy[ $tab ];
		}
		$tabs = self::tabs();
		if ( ! isset( $tabs[ $tab ]['file'] ) || ! current_user_can( self::tab_cap( $tab ) ) ) {
			return 'home';
		}
		return $tab;
	}

	/**
	 * Whether the one-minute setup should show instead of the app.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private static function show_setup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- "Run setup again" link; nothing is saved from it.
		return ! get_option( 'karetaker_onboarded' ) || ! empty( $_GET['setup'] );
	}

	/**
	 * Renders the Karetaker admin page.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'karetaker_view' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'karetaker' ), '', array( 'response' => 403 ) );
		}

		$tab   = self::current_tab();
		$home  = self::home_model();
		$setup = self::show_setup();
		$tabs  = self::tabs();
		$adv   = ! $setup && isset( $tabs[ $tab ]['group'] ) && 'pro' === $tabs[ $tab ]['group'];
		?>
		<div class="wrap karetaker-app<?php echo $adv ? ' is-adv' : ''; ?>" id="kt" data-state="<?php echo esc_attr( $home['state'] ); ?>" data-rest="<?php echo esc_attr( esc_url_raw( rest_url( Karetaker_Admin_Rest::NS . '/admin/' ) ) ); ?>">
			<hr class="wp-header-end" hidden>
			<?php
			if ( $setup ) {
				include KARETAKER_DIR . 'includes/admin/partials/onboarding.php';
			} else {
				self::render_head( $home );
				self::render_nav( $tab );
				if ( karetaker_is_disabled() ) {
					?>
					<div class="card note-off"><strong><?php echo esc_html__( 'Karetaker is switched off.', 'karetaker' ); ?></strong> <?php echo esc_html__( 'The emergency off switch is on, so nothing is being watched and protections are not applied.', 'karetaker' ); ?></div>
					<?php
				}
				echo $adv ? '<div class="kt-adv">' : '';
				include $tabs[ $tab ]['file'];
				echo $adv ? '</div>' : '';
				self::render_footer();
			}

			include KARETAKER_DIR . 'includes/admin/partials/sheet.php';

			/**
			 * Fires at the end of the Karetaker screen, inside the app container.
			 *
			 * @since 1.0.2
			 * @param string $tab Current screen.
			 */
			do_action( 'karetaker_admin_page_end', $tab );
			self::render_toast();
			?>
		</div>
		<?php
		wp_add_inline_script( 'karetaker-admin', 'window.karetakerData = ' . wp_json_encode( self::$payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}

	/**
	 * Home state from active issues: safe, check or act, with the tower tiers and copy.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public static function home_model() {
		static $model = null;
		if ( null !== $model ) {
			return $model;
		}
		$issues   = Karetaker_Issues::all( array( 'status' => 'active' ) );
		$act      = 0;
		$intruder = false;
		$tiers    = array( '', '', '' );
		foreach ( $issues as $issue ) {
			$level = 'act' === $issue['sev'] ? 'bad' : 'warn';
			if ( 'act' === $issue['sev'] ) {
				++$act;
				$intruder = $intruder || in_array( $issue['area'], array( 'files', 'users' ), true );
			}
			if ( 'files' === $issue['area'] ) {
				$tier = 0;
			} elseif ( in_array( $issue['area'], array( 'users', 'login' ), true ) ) {
				$tier = 1;
			} else {
				$tier = 2;
			}
			if ( 'bad' !== $tiers[ $tier ] ) {
				$tiers[ $tier ] = $level;
			}
		}

		if ( $act ) {
			$state = 'act';
			/* translators: %d: number of urgent issues */
			$title = sprintf( _n( '%d thing needs you now', '%d things need you now', $act, 'karetaker' ), $act );
			$sub   = $intruder ? __( 'Someone may have gotten into your site. Start with the first card below.', 'karetaker' ) : __( 'Something important changed on your site. Start with the first card below.', 'karetaker' );
			$pill  = __( 'Act now', 'karetaker' );
		} elseif ( $issues ) {
			$state = 'check';
			/* translators: %d: number of issues to look at */
			$title = sprintf( _n( '%d thing to look at', '%d things to look at', count( $issues ), 'karetaker' ), count( $issues ) );
			$sub   = __( 'Nothing urgent. Take a look when you have a minute.', 'karetaker' );
			$pill  = __( 'Check this', 'karetaker' );
		} else {
			$state = 'safe';
			$title = __( 'All quiet', 'karetaker' );
			$sub   = __( 'Nothing needs you. Karetaker keeps watching in the background.', 'karetaker' );
			$pill  = __( 'All clear', 'karetaker' );
		}

		$scan     = Karetaker_Scanner::state();
		$last_run = isset( $scan['last_run'] ) ? (string) $scan['last_run'] : '';
		if ( '' !== $last_run ) {
			/* translators: %s: time since the last check, like "12 minutes" */
			$last = sprintf( __( 'Last checked %s ago', 'karetaker' ), human_time_diff( (int) strtotime( $last_run . ' UTC' ), time() ) );
		} else {
			$last = __( 'Not checked yet', 'karetaker' );
		}

		$model = array(
			'issues' => $issues,
			'act'    => $act,
			'state'  => $state,
			'title'  => $title,
			'sub'    => $sub,
			'pill'   => $pill,
			'tiers'  => $tiers,
			'last'   => $last,
		);
		return $model;
	}

	/**
	 * Header: mark, name, tagline and status pill.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $home Home model.
	 * @return void
	 */
	private static function render_head( array $home ) {
		?>
		<div class="head">
			<?php self::brand_mark( 'mark' ); ?>
			<div>
				<h1><?php echo esc_html__( 'Karetaker', 'karetaker' ); ?></h1>
				<p class="tagline"><?php echo esc_html__( 'A watchtower, not a wall. Watches your site and tells you when something needs you.', 'karetaker' ); ?></p>
			</div>
			<span class="grow"></span>
			<span class="pill"><i></i><span><?php echo esc_html( $home['pill'] ); ?></span></span>
		</div>
		<?php
	}

	/**
	 * Outputs the brand mark SVG.
	 *
	 * @since 1.0.0
	 * @param string $class_name CSS class.
	 * @return void
	 */
	public static function brand_mark( $class_name = '' ) {
		?>
		<svg<?php echo $class_name ? ' class="' . esc_attr( $class_name ) . '"' : ''; ?> viewBox="0 0 256 256" aria-hidden="true" focusable="false"><rect width="256" height="256" rx="40" fill="#141414"/><g transform="translate(40 40) scale(1.76)"><circle cx="50" cy="20" r="8" fill="#f0c36d"/><rect x="39" y="36" width="22" height="10" fill="#F2EFE8"/><rect x="31" y="54" width="38" height="10" fill="#F2EFE8"/><rect x="23" y="72" width="54" height="10" fill="#F2EFE8"/></g></svg>
		<?php
	}

	/**
	 * Main navigation, with the Advanced tabs when Advanced mode is on.
	 *
	 * @since 1.0.0
	 * @param string $current Active tab.
	 * @return void
	 */
	private static function render_nav( $current ) {
		$simple   = array();
		$advanced = array();
		foreach ( self::tabs() as $slug => $tab ) {
			if ( 'pro' === $tab['group'] ) {
				$advanced[ $slug ] = $tab['label'];
			} else {
				$simple[ $slug ] = $tab['label'];
			}
		}
		?>
		<nav class="nav" aria-label="<?php echo esc_attr__( 'Karetaker', 'karetaker' ); ?>">
			<?php self::nav_links( $simple, $current ); ?>
			<?php if ( $advanced ) : ?>
				<span class="sep"></span>
				<span class="adv"><?php echo esc_html__( 'Advanced', 'karetaker' ); ?></span>
				<?php self::nav_links( $advanced, $current ); ?>
			<?php endif; ?>
		</nav>
		<?php
	}

	/**
	 * Tab links the current user may open.
	 *
	 * @since 1.0.0
	 * @param array<string, string> $tabs    Slug => label.
	 * @param string                $current Active tab.
	 * @return void
	 */
	private static function nav_links( array $tabs, $current ) {
		foreach ( $tabs as $slug => $label ) {
			if ( ! current_user_can( self::tab_cap( $slug ) ) ) {
				continue;
			}
			printf(
				'<a href="%1$s"%2$s>%3$s</a>',
				esc_url( self::admin_page_url( $slug ) ),
				$slug === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
	}

	/**
	 * Footer: version on the left, Team Krikir and Credits on the right.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function render_footer() {
		$credits = current_user_can( 'manage_options' ) ? self::admin_page_url( 'settings' ) . '#credits' : 'https://wordpress.org/plugins/karetaker/';
		?>
		<p class="app-foot">
			<span><?php echo esc_html( 'Karetaker ' . KARETAKER_VERSION ); ?></span>
			<span>
				<?php
				printf(
					/* translators: 1: Team Krikir link, 2: credits link */
					esc_html__( 'by %1$s · %2$s', 'karetaker' ),
					'<a href="' . esc_url( 'https://www.krikir.com/' ) . '" target="_blank" rel="noopener noreferrer">Team Krikir</a>',
					'<a href="' . esc_url( $credits ) . '">' . esc_html__( 'Credits', 'karetaker' ) . '</a>'
				);
				?>
			</span>
		</p>
		<?php
	}

	/**
	 * Toast, pre-filled from a redirect flag.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function render_toast() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Flash flags from our own authenticated redirects.
		$message = '';
		if ( isset( $_GET['kt_report'] ) ) {
			$message = 'sent' === sanitize_key( wp_unslash( $_GET['kt_report'] ) ) ? __( 'Report emailed to your client.', 'karetaker' ) : __( 'The report email could not be sent. Check the client email and your mail setup.', 'karetaker' );
		} elseif ( isset( $_GET['mail_dismissed'] ) ) {
			$message = __( 'Email warning dismissed.', 'karetaker' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="toast<?php echo $message ? ' show' : ''; ?>" id="toast" role="status"><?php echo esc_html( $message ); ?></div>
		<?php
	}

	/**
	 * Download link for Karetaker_Exports.
	 *
	 * @since 1.0.0
	 * @param string $type   Export type.
	 * @param string $format csv, json or html.
	 * @param string $id     Incident id.
	 * @param string $range  Activity range: 24h, 7d, 30d or all.
	 * @return string
	 */
	public static function export_url( $type, $format = '', $id = '', $range = '' ) {
		$args = array_filter(
			array(
				'format'   => $format,
				'id'       => $id,
				'kt_range' => $range,
			),
			'strlen'
		);
		return Karetaker_Exports::url( $type, $args );
	}

	/**
	 * Inline SVG icon by name.
	 *
	 * @since 1.0.0
	 * @param string $name alert, user, file, plug, key, check, refresh.
	 * @return string Static trusted markup.
	 */
	public static function icon( $name ) {
		$paths = array(
			'alert'   => '<path d="M10 3 2 17h16L10 3Z"/><path d="M10 8v4M10 14.5v.5"/>',
			'user'    => '<circle cx="10" cy="7" r="3.5"/><path d="M3.5 17c1-3.2 3.5-5 6.5-5s5.5 1.8 6.5 5"/>',
			'file'    => '<path d="M5 2h7l3 3v13H5z"/>',
			'plug'    => '<path d="M7 2v4M13 2v4M5 6h10v4a5 5 0 0 1-10 0zM10 15v3"/>',
			'key'     => '<circle cx="7" cy="13" r="3.5"/><path d="m9.5 10.5 7-7M14 6l2 2"/>',
			'check'   => '<path d="m4 10 4 4 8-8"/>',
			'refresh' => '<path d="M16 10a6 6 0 1 1-1.8-4.3M16 3v4h-4"/>',
			'chev'    => '<path d="m8 5 5 5-5 5"/>',
		);
		$path  = isset( $paths[ $name ] ) ? $paths[ $name ] : $paths['alert'];
		return '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}

	/**
	 * Brand logo for an alert destination, from Simple Icons (CC0 1.0). Logos are trademarks of their owners.
	 *
	 * @since 1.0.0
	 * @param string $channel telegram, slack, discord or teams.
	 * @return void
	 */
	public static function the_brand_icon( $channel ) {
		$icons = array(
			'telegram' => array( '#26A5E4', 'M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z' ),
			'slack'    => array( '#4A154B', 'M5.042 15.165a2.528 2.528 0 0 1-2.52 2.523A2.528 2.528 0 0 1 0 15.165a2.527 2.527 0 0 1 2.522-2.52h2.52v2.52zM6.313 15.165a2.527 2.527 0 0 1 2.521-2.52 2.527 2.527 0 0 1 2.521 2.52v6.313A2.528 2.528 0 0 1 8.834 24a2.528 2.528 0 0 1-2.521-2.522v-6.313zM8.834 5.042a2.528 2.528 0 0 1-2.521-2.52A2.528 2.528 0 0 1 8.834 0a2.528 2.528 0 0 1 2.521 2.522v2.52H8.834zM8.834 6.313a2.528 2.528 0 0 1 2.521 2.521 2.528 2.528 0 0 1-2.521 2.521H2.522A2.528 2.528 0 0 1 0 8.834a2.528 2.528 0 0 1 2.522-2.521h6.312zM18.956 8.834a2.528 2.528 0 0 1 2.522-2.521A2.528 2.528 0 0 1 24 8.834a2.528 2.528 0 0 1-2.522 2.521h-2.522V8.834zM17.688 8.834a2.528 2.528 0 0 1-2.523 2.521 2.527 2.527 0 0 1-2.52-2.521V2.522A2.527 2.527 0 0 1 15.165 0a2.528 2.528 0 0 1 2.523 2.522v6.312zM15.165 18.956a2.528 2.528 0 0 1 2.523 2.522A2.528 2.528 0 0 1 15.165 24a2.527 2.527 0 0 1-2.52-2.522v-2.522h2.52zM15.165 17.688a2.527 2.527 0 0 1-2.52-2.523 2.526 2.526 0 0 1 2.52-2.52h6.313A2.527 2.527 0 0 1 24 15.165a2.528 2.528 0 0 1-2.522 2.523h-6.313z' ),
			'discord'  => array( '#5865F2', 'M20.317 4.3698a19.7913 19.7913 0 00-4.8851-1.5152.0741.0741 0 00-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 00-.0785-.037 19.7363 19.7363 0 00-4.8852 1.515.0699.0699 0 00-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 00.0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 00.0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 00-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 01-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 01.0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 01.0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 01-.0066.1276 12.2986 12.2986 0 01-1.873.8914.0766.0766 0 00-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 00.0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 00.0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 00-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189Z' ),
			'teams'    => array( '#6264A7', 'M20.625 8.127q-.55 0-1.025-.205-.475-.205-.832-.563-.358-.357-.563-.832Q18 6.053 18 5.502q0-.54.205-1.02t.563-.837q.357-.358.832-.563.474-.205 1.025-.205.54 0 1.02.205t.837.563q.358.357.563.837.205.48.205 1.02 0 .55-.205 1.025-.205.475-.563.832-.357.358-.837.563-.48.205-1.02.205zm0-3.75q-.469 0-.797.328-.328.328-.328.797 0 .469.328.797.328.328.797.328.469 0 .797-.328.328-.328.328-.797 0-.469-.328-.797-.328-.328-.797-.328zM24 10.002v5.578q0 .774-.293 1.46-.293.685-.803 1.194-.51.51-1.195.803-.686.293-1.459.293-.445 0-.908-.105-.463-.106-.85-.329-.293.95-.855 1.729-.563.78-1.319 1.336-.756.557-1.67.861-.914.305-1.898.305-1.148 0-2.162-.398-1.014-.399-1.805-1.102-.79-.703-1.312-1.664t-.674-2.086h-5.8q-.411 0-.704-.293T0 16.881V6.873q0-.41.293-.703t.703-.293h8.59q-.34-.715-.34-1.5 0-.727.275-1.365.276-.639.75-1.114.475-.474 1.114-.75.638-.275 1.365-.275t1.365.275q.639.276 1.114.75.474.475.75 1.114.275.638.275 1.365t-.275 1.365q-.276.639-.75 1.113-.475.475-1.114.75-.638.276-1.365.276-.188 0-.375-.024-.188-.023-.375-.058v1.078h10.875q.469 0 .797.328.328.328.328.797zM12.75 2.373q-.41 0-.78.158-.368.158-.638.434-.27.275-.428.639-.158.363-.158.773 0 .41.158.78.159.368.428.638.27.27.639.428.369.158.779.158.41 0 .773-.158.364-.159.64-.428.274-.27.433-.639.158-.369.158-.779 0-.41-.158-.773-.159-.364-.434-.64-.275-.275-.639-.433-.363-.158-.773-.158zM6.937 9.814h2.25V7.94H2.814v1.875h2.25v6h1.875zm10.313 7.313v-6.75H12v6.504q0 .41-.293.703t-.703.293H8.309q.152.809.556 1.5.405.691.985 1.19.58.497 1.318.779.738.281 1.582.281.926 0 1.746-.352.82-.351 1.436-.966.615-.616.966-1.43.352-.815.352-1.752zm5.25-1.547v-5.203h-3.75v6.855q.305.305.691.452.387.146.809.146.469 0 .879-.176.41-.175.715-.48.304-.305.48-.715t.176-.879Z' ),
		);
		if ( ! isset( $icons[ $channel ] ) ) {
			return;
		}
		echo wp_kses( '<svg class="brand-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="' . $icons[ $channel ][0] . '" d="' . $icons[ $channel ][1] . '"/></svg>', self::svg_kses() );
	}

	/**
	 * Allowed markup for icon().
	 *
	 * @since 1.0.0
	 * @return array<string, array<string, bool>>
	 */
	public static function svg_kses() {
		$attrs = array(
			'viewbox'         => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'aria-hidden'     => true,
			'focusable'       => true,
			'class'           => true,
			'd'               => true,
			'cx'              => true,
			'cy'              => true,
			'r'               => true,
			'x'               => true,
			'y'               => true,
			'width'           => true,
			'height'          => true,
		);
		return array(
			'svg'    => $attrs,
			'path'   => $attrs,
			'circle' => $attrs,
			'rect'   => $attrs,
		);
	}

	/**
	 * Prints an icon.
	 *
	 * @since 1.0.0
	 * @param string $name Icon name.
	 * @return void
	 */
	public static function the_icon( $name ) {
		echo wp_kses( self::icon( $name ), self::svg_kses() );
	}

	/**
	 * Hands any other value to admin.js.
	 *
	 * @since 1.0.0
	 * @param string $group Group name.
	 * @param string $key   Key within the group.
	 * @param mixed  $value JSON-safe value.
	 * @return void
	 */
	public static function expose( $group, $key, $value ) {
		self::$payload[ $group ][ $key ] = $value;
	}

	/**
	 * Hands an issue to admin.js for the side panel and drawer.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $issue Issue from Karetaker_Issues.
	 * @return string Panel key.
	 */
	public static function expose_issue( array $issue ) {
		$key = 'i' . (int) $issue['id'];
		if ( ! isset( self::$payload['items'][ $key ] ) ) {
			self::$payload['items'][ $key ] = array(
				'kind'     => 'issue',
				'id'       => (int) $issue['id'],
				'title'    => $issue['title'],
				'sev'      => $issue['sev'],
				'status'   => $issue['status'],
				'area'     => $issue['area_label'],
				'opened'   => $issue['opened_h'],
				'owner'    => (int) $issue['owner'],
				'what'     => $issue['what'],
				'why'      => $issue['why'],
				'steps'    => $issue['steps'],
				'link'     => $issue['link'],
				'linkText' => $issue['link_label'],
				'details'  => self::detail_pairs( $issue['details'], $issue['who'], $issue['last_h'], (int) $issue['count'] ),
				'timeline' => Karetaker_Issues::timeline( $issue ),
				'raw'      => $issue['json'],
				'canMark'  => $issue['can_mark'] && current_user_can( 'manage_options' ),
				'canAct'   => current_user_can( 'karetaker_resolve' ),
			);
		}
		return $key;
	}

	/**
	 * Hands a single event to admin.js for the drawer.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $view Event from Karetaker_Admin_Data::event().
	 * @return string Panel key.
	 */
	public static function expose_event( array $view ) {
		$key = 'e' . (int) $view['id'];
		if ( ! isset( self::$payload['items'][ $key ] ) ) {
			self::$payload['items'][ $key ] = array(
				'kind'     => 'event',
				'id'       => (int) $view['id'],
				'title'    => $view['title'],
				'sev'      => 'watch' === $view['sev'] ? 'review' : $view['sev'],
				'status'   => $view['expected'] ? 'expected' : '',
				'area'     => Karetaker_Issues::area_label( Karetaker_Issues::area( $view['code'] ) ),
				'opened'   => $view['when'],
				'what'     => $view['summary'],
				'steps'    => $view['steps'],
				'link'     => $view['link'],
				'linkText' => $view['link_label'],
				'details'  => self::detail_pairs( $view['details'], $view['who'], $view['when'], 1 ),
				'timeline' => array(),
				'raw'      => $view['json'],
				'canMark'  => $view['can_mark'] && current_user_can( 'manage_options' ),
				'canAct'   => false,
			);
		}
		return $key;
	}

	/**
	 * Detail rows plus who / when.
	 *
	 * @since 1.0.0
	 * @param array<int, array{0: string, 1: string}> $details Label/value pairs.
	 * @param string                                  $who     Actor.
	 * @param string                                  $when    Last seen.
	 * @param int                                     $count   Times seen.
	 * @return array<int, array{0: string, 1: string}>
	 */
	private static function detail_pairs( array $details, $who, $when, $count ) {
		$out   = array_values( $details );
		$out[] = array( __( 'By', 'karetaker' ), (string) $who );
		$out[] = array( __( 'Last seen', 'karetaker' ), (string) $when );
		if ( $count > 1 ) {
			$out[] = array( __( 'Times seen', 'karetaker' ), (string) $count );
		}
		return $out;
	}

	/**
	 * Chip markup for importance.
	 *
	 * @since 1.0.0
	 * @param string $sev act, review/watch or log.
	 * @return void
	 */
	public static function sev_chip( $sev ) {
		if ( 'act' === $sev ) {
			$row = array( 'bad', __( 'Act now', 'karetaker' ) );
		} elseif ( in_array( $sev, array( 'review', 'watch' ), true ) ) {
			$row = array( 'warn', __( 'Review', 'karetaker' ) );
		} else {
			$row = array( 'mute', __( 'Logged', 'karetaker' ) );
		}
		printf( '<span class="chip %1$s">%2$s</span>', esc_attr( $row[0] ), esc_html( $row[1] ) );
	}

	/**
	 * Shows an email in the browser, built from this site's real data.
	 *
	 * Types: act and review use the newest event of that importance, weekly the last seven
	 * days, pause the last hour, test the test email.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function handle_email_preview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to preview emails.', 'karetaker' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'karetaker_email_preview' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked above.
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'act';

		if ( in_array( $type, array( 'act', 'review' ), true ) ) {
			$rows = Karetaker_Events::query(
				array(
					'severity' => 'act' === $type ? Karetaker_Events::SEVERITY_ACT : Karetaker_Events::SEVERITY_ATTENTION,
					'limit'    => 1,
				)
			);
			if ( ! $rows ) {
				wp_die( esc_html__( 'There is no event of that importance on this site yet, so there is nothing to preview.', 'karetaker' ) );
			}
			$row     = $rows[0];
			$context = is_array( $row->context ) ? $row->context : array();
			$subject = Karetaker_Alerts::subject_for( (string) $row->event_code, $context, $type );
			$args    = Karetaker_Alerts::email_args( (string) $row->event_code, $context, (int) $row->id, $type );
		} else {
			$args    = Karetaker_Alerts::test_args();
			$subject = Karetaker_Email::subject( '', __( 'Test alert', 'karetaker' ) );
			if ( in_array( $type, array( 'weekly', 'pause' ), true ) ) {
				$captured = Karetaker_Summary::preview( $type );
				if ( ! $captured ) {
					wp_die( esc_html__( 'Nothing happened in that time, so there is nothing to preview.', 'karetaker' ) );
				}
				$subject = $captured['subject'];
				$args    = $captured['args'];
			}
		}

		$mail = Karetaker_Email::render( $args );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		// Subject shown above the message, the way an inbox would.
		echo '<div style="font:13px/1.5 -apple-system,BlinkMacSystemFont,sans-serif;background:#fff;border-bottom:1px solid #dcdcde;padding:10px 16px;color:#1d2327"><b>' . esc_html__( 'Subject:', 'karetaker' ) . '</b> ' . esc_html( $subject ) . '</div>';
		echo $mail['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while rendering.
		exit;
	}

	/**
	 * Dismisses the sticky mail_failed Guard flag.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function handle_dismiss_mail_failed() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to dismiss this flag.', 'karetaker' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'karetaker_dismiss_mail_failed' );
		Karetaker_Guard::dismiss_mail_failed();

		wp_safe_redirect( self::admin_page_url( 'settings', array( 'mail_dismissed' => '1' ) ) );
		exit;
	}
}
