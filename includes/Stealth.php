<?php
namespace BizyCore;

defined( 'ABSPATH' ) || exit;

/**
 * Stealth mode: stop the site announcing that it runs WordPress.
 *
 * Everything here works in PHP, on any host: WordPress's own entry points (login, admin, REST index, XML-RPC, feeds,
 * author pages, cron from outside) answer with a plain "Not Found" unless the visitor holds the admin "gate", and any
 * address Bizy does not own does the same. Bizy's own pages, sign-in, portal, signing links, invoice links and
 * /bd/ addresses are untouched. Server rules (class Stealth_Rules) add the same protection for static files.
 *
 * Safety: it can always be switched off by BIZYCORE_STEALTH_OFF in wp-config.php, by creating the file STEALTH_OFF in
 * the bizy-files folder, or with WP-CLI. Turning it on starts a short confirm-or-revert timer.
 */
class Stealth {

	const OPTION  = 'bizycore_stealth';
	const COOKIE  = 'bd_gate';
	const CONFIRM = 300; // seconds to confirm after switching on

	public static function defaults() {
		return array(
			'enabled'       => false,
			'secret'        => '',
			'hide_login'    => true,
			'hide_admin'    => true,
			'hide_rest'     => true,
			'hide_misc'     => true,
			'only_allowlist' => true,
			'strip'         => true,
			'confirmed'     => false,
			'deadline'      => 0,
			'rules'         => false,
			'rules_error'   => '',
			'rest_allow'    => 'novamira, wp-abilities',
		);
	}

	public static function settings() {
		return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function save( array $in ) {
		$s = self::settings();
		if ( ! empty( $in['rules'] ) && empty( $s['rules'] ) ) {
			$ok = Stealth_Readiness::allows( 'rules' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		foreach ( array( 'hide_login', 'hide_admin', 'hide_rest', 'hide_misc', 'only_allowlist', 'strip', 'rules' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$s[ $k ] = (bool) $in[ $k ];
			}
		}
		if ( isset( $in['rest_allow'] ) ) {
			$s['rest_allow'] = implode( ', ', array_filter( array_map( 'sanitize_key', preg_split( '/[\s,;]+/', (string) $in['rest_allow'] ) ) ) );
		}
		if ( isset( $in['secret'] ) ) {
			$sec = sanitize_title( (string) $in['secret'] );
			if ( '' !== $sec && ( strlen( $sec ) < 8 || in_array( $sec, array( 'wp-admin', 'wp-login', 'login', 'admin', 'dashboard' ), true ) ) ) {
				return new \WP_Error( 'bd_secret', 'Choose a secret address of at least 8 characters that is not an obvious word.', array( 'status' => 400 ) );
			}
			$s['secret'] = $sec;
		}
		if ( isset( $in['enabled'] ) ) {
			$want = (bool) $in['enabled'];
			if ( $want && ! $s['enabled'] ) {
				$ok = Stealth_Readiness::allows( 'stealth' );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				if ( '' === $s['secret'] ) {
					return new \WP_Error( 'bd_secret', 'Choose your secret admin address first, so you can still reach WordPress.', array( 'status' => 400 ) );
				}
				$s['enabled']   = true;
				$s['confirmed'] = false;
				$s['deadline']  = time() + self::CONFIRM;
			} elseif ( ! $want ) {
				$s['enabled']   = false;
				$s['confirmed'] = false;
				$s['deadline']  = 0;
			}
		}
		update_option( self::OPTION, $s, false );
		self::$active = null;
		self::purge_cache();
		return $s;
	}

	/** Page caches would keep answering with what they stored before. */
	public static function purge_cache() {
		do_action( 'litespeed_purge_all' );
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
	}

	public static function confirm() {
		$s = self::settings();
		if ( $s['enabled'] ) {
			$s['confirmed'] = true;
			$s['deadline']  = 0;
			update_option( self::OPTION, $s, false );
		}
		return $s;
	}

	/** Is a recovery switch pulled? */
	public static function recovery_flag() {
		if ( defined( 'BIZYCORE_STEALTH_OFF' ) && BIZYCORE_STEALTH_OFF ) {
			return 'constant';
		}
		if ( is_file( Paths::root() . '/STEALTH_OFF' ) ) {
			return 'file';
		}
		return '';
	}

	/** Should the rules apply to this request? Switches itself off if the confirm window passed unconfirmed. */
	private static $active = null;

	public static function active() {
		if ( null !== self::$active ) {
			return self::$active;
		}
		return self::$active = self::compute_active();
	}

	/** The setting as stored right now (not the value cached for this request). */
	public static function active_now() {
		self::$active = null;
		return self::active();
	}

	private static function compute_active() {
		$s = self::settings();
		if ( self::recovery_flag() && ! empty( $s['rules'] ) ) {
			Stealth_Rules::remove(); // a recovery switch also takes the server rules away, so the admin loads normally
			$s['rules'] = false;
			update_option( self::OPTION, $s, false );
		}
		if ( empty( $s['enabled'] ) || self::recovery_flag() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( empty( $s['confirmed'] ) && time() > (int) $s['deadline'] ) {
			$s['enabled']  = false;
			$s['deadline'] = 0;
			update_option( self::OPTION, $s, false ); // nobody confirmed in time: back to normal
			Stealth_Rules::remove();
			return false;
		}
		return true;
	}

	/* ───────────────────────── The gate ───────────────────────── */

	private static function sign( $exp ) {
		return hash_hmac( 'sha256', 'bd-gate|' . $exp, wp_salt( 'auth' ) );
	}

	public static function has_gate() {
		$c = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( false === strpos( $c, '.' ) ) {
			return false;
		}
		list( $exp, $sig ) = explode( '.', $c, 2 );
		return ctype_digit( $exp ) && (int) $exp > time() && hash_equals( self::sign( $exp ), $sig );
	}

	private static function open_gate() {
		$exp = time() + 12 * HOUR_IN_SECONDS;
		setcookie( self::COOKIE, $exp . '.' . self::sign( $exp ), array( 'expires' => $exp, 'path' => '/', 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
	}

	/* ───────────────────────── Request handling ───────────────────────── */

	public static function init() {
		// Always: leave nothing behind that tells (the logout / login addresses become Bizy's own).
		add_filter( 'logout_url', array( __CLASS__, 'logout_url' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'maybe_logout' ), 0 );
		if ( ! self::active() ) {
			return;
		}
		$s = self::settings();
		self::early();
		add_filter( 'login_url', array( __CLASS__, 'login_url' ), 10, 3 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'rest_gate' ), 999 );
		add_action( 'init', array( __CLASS__, 'admin_gate' ), 1 );
		if ( $s['only_allowlist'] ) {
			add_action( 'template_redirect', array( __CLASS__, 'allowlist' ), 99 );
		}
		if ( $s['strip'] ) {
			self::strip();
		}
	}

	private static function path() {
		$p    = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return trim( rawurldecode( substr( (string) $p, strlen( $home ) ) ), '/' );
	}

	/** A plain "Not Found", the way a bare web server says it: nothing that hints at WordPress. */
	public static function deny() {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		status_header( 404 );
		header( 'Content-Type: text/html; charset=iso-8859-1' );
		nocache_headers();
		echo "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">\n<html><head>\n<title>404 Not Found</title>\n</head><body>\n<h1>Not Found</h1>\n<p>The requested URL was not found on this server.</p>\n</body></html>\n";
		exit;
	}

	/** Checks that need nothing but the address: login, XML-RPC, cron from outside, feeds, authors, sitemaps. */
	private static function early() {
		$s      = self::settings();
		$path   = self::path();
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) $_SERVER['SCRIPT_NAME'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// The secret address: open the gate and go to the WordPress sign-in.
		if ( '' !== $s['secret'] && $path === $s['secret'] ) {
			self::open_gate();
			wp_safe_redirect( site_url( 'wp-login.php' ) );
			exit;
		}
		if ( 'wp-login.php' === $script && $s['hide_login'] && ! self::has_gate() ) {
			self::deny();
		}
		if ( $s['hide_misc'] ) {
			if ( in_array( $script, array( 'xmlrpc.php', 'wp-comments-post.php', 'wp-trackback.php', 'wp-signup.php', 'wp-activate.php', 'wp-links-opml.php', 'wp-mail.php' ), true ) ) {
				self::deny();
			}
			if ( 'wp-cron.php' === $script ) {
				$ra = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$sa = isset( $_SERVER['SERVER_ADDR'] ) ? (string) $_SERVER['SERVER_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( ! in_array( $ra, array( '127.0.0.1', '::1', $sa ), true ) ) {
					self::deny();
				}
			}
			if ( preg_match( '#^(feed|comments/feed|author|wp-sitemap|sitemap[^/]*\.xml|index\.php/)#i', $path ) || '' !== (string) ( $_GET['author'] ?? '' ) || isset( $_GET['feed'] ) || isset( $_GET['author_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				self::deny();
			}
		}
	}

	/** Unauthenticated REST (and the root index) is hidden; signed-in tools and Application Passwords keep working. */
	public static function rest_gate( $result ) {
		if ( ! self::settings()['hide_rest'] ) {
			return $result;
		}
		if ( ! is_user_logged_in() && ! self::rest_allowed() ) {
			self::deny();
		}
		return $result;
	}

	/** Namespaces a tool may call without a WordPress login (they carry their own token). */
	private static function rest_allowed() {
		$uri  = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$uri  = (string) $uri;
		$pos  = strpos( $uri, '/wp-json/' );
		$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : ( false !== $pos ? substr( $uri, $pos + 8 ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification
		$ns   = strtolower( trim( explode( '/', ltrim( $route, '/' ) )[0] ) );
		$list = array_filter( array_map( 'trim', explode( ',', strtolower( (string) self::settings()['rest_allow'] ) ) ) );
		return '' !== $ns && in_array( $ns, $list, true );
	}

	/** WordPress admin needs the gate; admin-ajax / admin-post stay for people who are signed in. */
	public static function admin_gate() {
		if ( ! self::settings()['hide_admin'] || ! is_admin() || self::has_gate() ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) $_SERVER['SCRIPT_NAME'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( in_array( $script, array( 'admin-ajax.php', 'admin-post.php' ), true ) && is_user_logged_in() ) {
			return;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}
		self::deny();
	}

	/** Anything Bizy does not own is a plain 404. Runs last, so every Bizy page has already answered. */
	public static function allowlist() {
		$path = self::path();
		if ( '' === $path ) {
			wp_safe_redirect( App_Page::url() );
			exit;
		}
		self::deny();
	}

	private static function strip() {
		header_remove( 'X-Powered-By' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'rest_output_link_wp_head' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'feed_links', 2 );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		add_filter( 'the_generator', '__return_empty_string' );
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', '__return_empty_array' );
		add_filter( 'wp_headers', function ( $h ) {
			unset( $h['X-Pingback'] );
			return $h;
		} );
		add_filter( 'redirect_canonical', function ( $url ) {
			return isset( $_GET['author'] ) ? false : $url; // phpcs:ignore WordPress.Security.NonceVerification
		} );
		add_filter( 'x_redirect_by', '__return_false' ); // "X-Redirect-By: WordPress" on redirects
	}

	/* ───────────────────────── Own sign-out / sign-in addresses ───────────────────────── */

	public static function login_url( $url, $redirect, $force ) {
		return $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), App_Page::url() ) : App_Page::url();
	}

	public static function logout_url( $url, $redirect ) {
		if ( ! self::active() ) {
			return $url; // WordPress's own address while stealth is off
		}
		$args = array( 'bd_logout' => 1, '_wpnonce' => wp_create_nonce( 'bd_logout' ) );
		if ( $redirect ) {
			$args['to'] = rawurlencode( $redirect );
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	public static function maybe_logout() {
		if ( empty( $_GET['bd_logout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( is_user_logged_in() && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'bd_logout' ) ) {
			wp_logout();
		}
		$to = isset( $_GET['to'] ) ? rawurldecode( wp_unslash( $_GET['to'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification
		wp_safe_redirect( $to ? $to : App_Page::url() );
		exit;
	}
}
