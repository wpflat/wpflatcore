<?php
namespace BizyCore;

defined( 'ABSPATH' ) || exit;

/**
 * The app shell inside WordPress: the admin menu (one top-level entry per enabled module), the full-screen app
 * screen, keeping team members and clients out of wp-admin, and the boot data the browser app starts from.
 */
class Shell {

	const PAGE = 'bizycore';

	/** WordPress admin page slug => the in-app route it opens (core plus each enabled module). */
	public static function pages() {
		$pages = array( self::PAGE => '/' );
		foreach ( Registry::active() as $id => $m ) {
			$pages[ self::PAGE . '-' . $id ] = $m['route'];
		}
		return $pages;
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'in_admin_header', array( __CLASS__, 'quiet_admin' ), 1000 );
		// Team members who only work in Bizy never see WordPress.
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
		add_action( 'admin_init', array( __CLASS__, 'confine_staff' ) );
		add_action( 'admin_init', array( __CLASS__, 'confine_clients' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
	}

	/* ───────────────────────── WordPress admin menus ───────────────────────── */

	public static function admin_menu() {
		add_menu_page( 'Bizy', 'Bizy', 'manage_bizycore', self::PAGE, array( __CLASS__, 'render' ), 'dashicons-chart-area', 26 );
		$core  = array(
			Terminology::label( 'home', true ) => '/',
			Terminology::label( 'client', true ) => '/clients',
			Terminology::label( 'team', true ) => '/team',
			Terminology::label( 'department', true ) => '/departments',
			'Activity' => '/activity',
			'Settings' => '/settings',
		);
		$first = true;
		foreach ( $core as $label => $route ) {
			// Submenu items are in-app hash routes; the first one replaces the auto-generated duplicate parent entry.
			$slug = $first ? self::PAGE : self::PAGE . '#' . $route;
			add_submenu_page( self::PAGE, $label, $label, 'manage_bizycore', $slug, $first ? array( __CLASS__, 'render' ) : '__return_null' );
			$first = false;
		}
		// Each enabled module is its own top-level menu with its own sub-menu.
		foreach ( Registry::active() as $id => $m ) {
			$parent = self::PAGE . '-' . $id;
			add_menu_page( 'Bizy ' . $m['label'], 'Bizy ' . $m['label'], 'manage_bizycore', $parent, array( __CLASS__, 'render' ), $m['wp'][0], $m['wp'][1] );
			$first = true;
			foreach ( $m['menu'] as $it ) {
				if ( ! empty( $it['url'] ) ) {
					continue; // external tools (e.g. a public verify page) are not WordPress admin pages
				}
				$cap  = ( ! $first && ! empty( $it['cap'] ) ) ? $it['cap'] : 'manage_bizycore';
				$slug = $first ? $parent : $parent . '#' . $it['route'];
				add_submenu_page( $parent, $it['label'], $it['label'], $cap, $slug, $first ? array( __CLASS__, 'render' ) : '__return_null' );
				$first = false;
			}
		}
	}

	public static function render() {
		echo '<div id="bizycore-root" class="bizycore-root"><p style="padding:24px">Loading…</p></div>';
	}

	private static function current_page() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return array_key_exists( $page, self::pages() ) ? $page : '';
	}

	private static function is_screen() {
		return '' !== self::current_page();
	}

	public static function body_class( $classes ) {
		if ( ! self::is_screen() ) {
			return $classes;
		}
		return $classes . ' bizycore-screen' . ( Settings::get( 'app_fullscreen' ) ? ' bizycore-fullscreen' : '' );
	}

	/** In full-screen mode no stray WordPress notices appear over the app. */
	public static function quiet_admin() {
		if ( self::is_screen() && Settings::get( 'app_fullscreen' ) ) {
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
			remove_all_actions( 'user_admin_notices' );
			remove_all_actions( 'network_admin_notices' );
		}
	}

	/* ───────────────────────── Keeping team members and clients out of wp-admin ───────────────────────── */

	/** A user who works in Bizy but is not a WordPress administrator. */
	public static function is_staff_only() {
		return is_user_logged_in() && current_user_can( 'manage_bizycore' ) && ! current_user_can( 'manage_options' );
	}

	/** Where staff-only users land: the front-end app if enabled, else the Bizy admin screen. */
	public static function home_for_staff() {
		return Settings::get( 'app_frontend' ) ? Urls::app() : admin_url( 'admin.php?page=' . self::PAGE );
	}

	/** A client login: portal only, never WordPress admin or the internal app. */
	public static function is_client_only() {
		return is_user_logged_in() && current_user_can( 'bizycore_portal' ) && ! current_user_can( 'manage_bizycore' ) && ! current_user_can( 'manage_options' );
	}

	public static function hide_admin_bar( $show ) {
		if ( self::is_client_only() ) {
			return false;
		}
		return ( Settings::get( 'app_keep_staff' ) && self::is_staff_only() ) ? false : $show;
	}

	public static function confine_clients() {
		if ( ! self::is_client_only() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		global $pagenow;
		if ( in_array( $pagenow, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
			return;
		}
		wp_safe_redirect( Portal::enabled() ? Portal::url() : home_url( '/' ) );
		exit;
	}

	public static function confine_staff() {
		if ( ! Settings::get( 'app_keep_staff' ) || ! self::is_staff_only() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		global $pagenow;
		if ( in_array( $pagenow, array( 'admin-post.php', 'async-upload.php', 'media-upload.php', 'admin-ajax.php' ), true ) ) {
			return;
		}
		if ( self::is_screen() && ! Settings::get( 'app_frontend' ) ) {
			return; // no front-end app: the Bizy admin screen is where they work
		}
		wp_safe_redirect( self::home_for_staff() );
		exit;
	}

	public static function login_redirect( $to, $requested, $user ) {
		if ( $user instanceof \WP_User && user_can( $user, 'bizycore_portal' ) && ! user_can( $user, 'manage_bizycore' ) && Portal::enabled() ) {
			return Portal::url();
		}
		if ( $user instanceof \WP_User && Settings::get( 'app_keep_staff' ) && user_can( $user, 'manage_bizycore' ) && ! user_can( $user, 'manage_options' ) ) {
			return self::home_for_staff();
		}
		return $to;
	}

	/* ───────────────────────── Boot data and assets ───────────────────────── */

	public static function theme() {
		$t = get_user_meta( get_current_user_id(), 'bizycore_theme', true );
		return in_array( $t, array( 'dark', 'auto' ), true ) ? $t : 'light'; // 'auto' follows the device's light/dark setting
	}

	/** What the browser app needs besides its own code: lookups and settings (modules add enums through `bizycore_meta`). */
	public static function meta_payload() {
		return apply_filters( 'bizycore_meta', array( 'settings' => Settings::get(), 'lookups' => Lookups::grouped(), 'enums' => array() ) );
	}

	/**
	 * The JavaScript boot object shared by the admin screen and the front-end app page. Modules (and Core's own
	 * features such as backup) add keys with the `bizycore_boot` filter.
	 */
	public static function boot_data( $initial_route, $frontend ) {
		$u = wp_get_current_user();
		$d = array(
			'restUrl'      => Urls::api(),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'meta'         => self::meta_payload(),
			'nav'          => Registry::nav(),
			'modules'      => Registry::summary(),
			'terms'        => Terminology::export()['words'],
			'moduleAssets' => Registry::assets(),
			'initialRoute' => $initial_route,
			'frontend'     => (bool) $frontend,
			'fullscreen'   => (bool) Settings::get( 'app_fullscreen' ) || $frontend,
			'adminUrl'     => Stealth::active() ? '' : admin_url(),
			'appUrl'       => Urls::app(),
			'logoutUrl'    => ( $frontend && Settings::get( 'app_login' ) ) ? App_Login::logout_url() : wp_specialchars_decode( wp_logout_url(), ENT_QUOTES ),
			'canManage'    => Roles::can_admin(),
			'wpAdmin'      => current_user_can( 'manage_options' ),
			'caps'         => Roles::ui_caps(),
			'staffOnly'    => self::is_staff_only(),
			'user'         => $u->display_name,
			'userEmail'    => $u->user_email,
			'userId'       => get_current_user_id(),
			'homeUrl'      => home_url( '/' ),
			'theme'        => self::theme(), // light | dark | auto
		);
		return apply_filters( 'bizycore_boot', $d, $initial_route, $frontend );
	}

	public static function enqueue( $hook ) {
		$page = self::current_page();
		if ( '' === $page || 0 !== strpos( (string) $hook, 'toplevel_page_' ) ) {
			return;
		}
		self::enqueue_app( self::pages()[ $page ], false );
	}

	public static function enqueue_app( $initial_route, $frontend ) {
		$js = BIZYCORE_DIR . 'build/bizycore.js';
		if ( ! is_readable( $js ) ) {
			return;
		}
		$ver = (string) filemtime( $js );
		wp_enqueue_script( 'bizycore-app', Assets::url( 'bizycore.js' ), array(), $ver, true );
		wp_add_inline_script( 'bizycore-app', 'window.bizycoreBoot=' . wp_json_encode( self::boot_data( $initial_route, $frontend ) ) . ';', 'before' );
		// Module bundles load after Core, so they find window.BizyCore ready.
		foreach ( Registry::assets() as $id => $files ) {
			if ( ! empty( $files['style'] ) ) {
				wp_enqueue_style( 'bizycore-mod-' . $id, $files['style'], array( 'bizycore-app' ), $ver );
			}
			if ( ! empty( $files['script'] ) ) {
				wp_enqueue_script( 'bizycore-mod-' . $id, $files['script'], array( 'bizycore-app' ), $ver, true );
			}
		}
		if ( is_readable( BIZYCORE_DIR . 'build/bizycore.css' ) ) {
			wp_enqueue_style( 'bizycore-app', Assets::url( 'bizycore.css' ), array(), $ver );
			wp_add_inline_style( 'bizycore-app', self::font_face_css() );
		}
		add_action( $frontend ? 'wp_head' : 'admin_head', array( __CLASS__, 'preload_font' ), 1 );
	}

	public static function preload_font() {
		echo '<link rel="preload" href="' . esc_url( Assets::font_base() . 'google-sans-latin.woff2' ) . '" as="font" type="font/woff2" crossorigin>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Self-hosted Google Sans with Inter supplying ₦. unicode-range lets the browser fetch only the subsets a page uses. */
	public static function font_face_css() {
		$base = Assets::font_base();
		$face = "@font-face{font-family:'Inter';font-style:normal;font-weight:100 900;font-display:swap;src:url('%s') format('woff2');unicode-range:%s;}";
		$gs   = "@font-face{font-family:'Google Sans';font-style:normal;font-weight:400 700;font-display:swap;src:url('%s') format('woff2');unicode-range:%s;}";
		$lat  = 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD';
		$ext  = 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF';
		return sprintf( $gs, $base . 'google-sans-latin.woff2', $lat ) . sprintf( $gs, $base . 'google-sans-latin-ext.woff2', $ext )
			. sprintf( $face, $base . 'inter-latin.woff2', $lat ) . sprintf( $face, $base . 'inter-latin-ext.woff2', $ext );
	}
}
