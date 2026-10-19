<?php
namespace BizyCore;

use BizyCore\Services\Portal_Users;

defined( 'ABSPATH' ) || exit;

/**
 * The client portal: https://site/portal/ (the word is configurable).
 *
 * A theme-less, server-rendered page set where a client signs in and sees only their own organisation. Core provides
 * the shell — sign-in routing, layout, overview, messages, notifications and the account page — and every installed
 * module adds its own pages (projects and tasks, invoices, contracts…) with Portal::register_page(). Every query is
 * scoped to the client ids linked to the signed-in user (user meta); staff can open the same pages for any client with
 * ?as=<client id> to see what the client sees; modules must never select anything internal (costs, team pay, notes).
 */
class Portal {

	/** @var array slug => page definition */
	private static $pages = array();

	/**
	 * Add a portal page. Call from the `bizycore_register_portal` action.
	 *
	 * @param string $slug  address segment and nav key, e.g. 'billing'
	 * @param array  $def   module (id), label, order, render( $ctx, $id, $sub ) (echo-free: return the page body HTML, or exit itself),
	 *                      overview( $ctx ) optional => array( 'cards' => html, 'attention' => html, 'panels' => html )
	 */
	public static function register_page( $slug, array $def ) {
		/** also: options => array( array( key, label ) ) — extra on/off switches for this page in Settings → Client portal */
		if ( ! preg_match( '/^[a-z][a-z0-9-]{1,30}$/', $slug ) || empty( $def['module'] ) || empty( $def['render'] ) ) {
			return false;
		}
		self::$pages[ $slug ] = $def + array( 'label' => ucfirst( $slug ), 'order' => 50, 'overview' => null, 'options' => array() );
		return true;
	}

	/** Pages the signed-in client can see: the module is on and the owner has not hidden the section (setting portal_show_<slug>). */
	public static function pages() {
		$out = array();
		foreach ( self::$pages as $slug => $p ) {
			if ( Registry::enabled( $p['module'] ) && false !== Settings::get( 'portal_show_' . str_replace( '-', '_', $slug ) ) ) {
				$out[ $slug ] = $p;
			}
		}
		uasort( $out, function ( $a, $b ) {
			return $a['order'] <=> $b['order'];
		} );
		return $out;
	}

	public static function messages_on() {
		return ! empty( Settings::get( 'portal_show_messages' ) );
	}

	/** The core client row the portal shows. */
	public static function client( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, contact_person, email FROM ' . Database\Schema::table( 'bizy_clients' ) . ' WHERE id=%d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** The request being served: user, ids, client, preview. For module render callbacks. */
	public static function ctx() {
		return self::$ctx;
	}

	public static function slug() {
		$s = sanitize_title( (string) Settings::get( 'portal_slug' ) );
		return '' !== $s ? $s : 'portal';
	}

	/** Address of a portal page. $path e.g. 'projects/12', $args extra query args. */
	public static function url( $path = '', array $args = array() ) {
		$u = home_url( '/' . self::slug() . '/' . ( '' !== $path ? trim( $path, '/' ) . '/' : '' ) );
		return $args ? add_query_arg( $args, $u ) : $u;
	}

	public static function enabled() {
		return (bool) Settings::get( 'portal_enabled' );
	}

	/** Every registered page (even of switched-off modules) as the Settings screen lists them. */
	public static function settings_pages() {
		$out = array();
		foreach ( self::$pages as $slug => $p ) {
			if ( Registry::installed( $p['module'] ) ) {
				$out[] = array( 'slug' => $slug, 'label' => $p['label'], 'module' => $p['module'], 'key' => 'portal_show_' . str_replace( '-', '_', $slug ), 'options' => $p['options'], 'order' => $p['order'] );
			}
		}
		usort( $out, function ( $a, $b ) {
			return $a['order'] <=> $b['order'];
		} );
		return $out;
	}

	public static function init() {
		// A page's on/off switch and its options are real settings, on by default.
		add_filter( 'bizycore_settings_defaults', function ( $d ) {
			foreach ( self::$pages as $slug => $p ) {
				$d[ 'portal_show_' . str_replace( '-', '_', $slug ) ] = true;
				foreach ( (array) $p['options'] as $o ) {
					$d[ $o['key'] ] = true;
				}
			}
			return $d;
		} );
		add_filter( 'bizycore_meta', function ( $m ) {
			$m['portal_pages'] = self::settings_pages();
			return $m;
		} );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
		add_action( 'wp_login', array( __CLASS__, 'note_login' ), 10, 2 );
	}

	public static function note_login( $login, $user ) {
		if ( $user instanceof \WP_User && user_can( $user, 'bizycore_portal' ) ) {
			update_user_meta( $user->ID, 'bizycore_last_login', time() );
		}
	}

	private static function requested() {
		$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return trim( substr( (string) $path, strlen( $home ) ), '/' );
	}

	/* ───────────────────────── Request handling ───────────────────────── */

	private static $ctx = array();

	public static function maybe_render() {
		$path = self::requested();
		$slug = self::slug();
		if ( $path !== $slug && 0 !== strpos( $path, $slug . '/' ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'bizycore portal' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: same-origin' );

		$rest = array_values( array_filter( explode( '/', substr( $path, strlen( $slug ) ) ), 'strlen' ) );

		if ( ! is_user_logged_in() ) {
			if ( ! self::enabled() ) {
				self::message_page( \BizyCore\Terminology::fmt( 'The {client} portal is not available' ), 'Please contact us if you need access.', 404 );
			}
			App_Login::route_for_portal( self::url() );
			exit;
		}
		$user    = wp_get_current_user();
		$as      = isset( $_GET['as'] ) ? absint( $_GET['as'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$preview = false;
		if ( $as && current_user_can( 'manage_bizycore' ) && current_user_can( 'bizycore_clients' ) ) {
			$ids     = array( $as );
			$preview = true;
		} elseif ( user_can( $user, 'bizycore_portal' ) || Portal_Users::client_ids( $user->ID ) ) {
			if ( current_user_can( 'manage_bizycore' ) ) { // staff who are also somebody's contact use the team app
				wp_safe_redirect( Urls::app() );
				exit;
			}
			$ids = Portal_Users::client_ids( $user->ID );
		} else {
			self::message_page( \BizyCore\Terminology::fmt( 'This is the {client} portal' ), 'Your account is not set up for the portal.' . ( current_user_can( 'manage_bizycore' ) ? ' Team members: open a client in the app and choose “View portal as client”.' : '' ), 403, true );
		}
		if ( ! self::enabled() && ! $preview ) {
			self::message_page( \BizyCore\Terminology::fmt( 'The {client} portal is not available' ), 'Please contact us if you need access.', 404, true );
		}
		if ( ! $ids ) {
			self::message_page( 'No organisation linked yet', 'Your login is not linked to a client yet. Please contact us and we will sort it out.', 200, true );
		}
		// Which organisation is being looked at.
		$cur = (int) $ids[0];
		if ( count( $ids ) > 1 ) {
			$want = isset( $_GET['org'] ) ? absint( $_GET['org'] ) : (int) get_user_meta( $user->ID, 'bizycore_portal_org', true ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( $want && in_array( $want, $ids, true ) ) {
				$cur = $want;
				if ( ! $preview && (int) get_user_meta( $user->ID, 'bizycore_portal_org', true ) !== $want ) {
					update_user_meta( $user->ID, 'bizycore_portal_org', $want );
				}
			}
		}
		$client = self::client( $cur );
		if ( ! $client ) {
			self::message_page( 'Not found', 'That organisation could not be found.', 404, true );
		}
		self::$ctx = array( 'user' => $user, 'ids' => $ids, 'client' => $client, 'preview' => $preview, 'pages' => self::pages() );

		$page = isset( $rest[0] ) ? $rest[0] : '';
		$id   = isset( $rest[1] ) && ctype_digit( $rest[1] ) ? (int) $rest[1] : 0;
		$sub  = isset( $rest[2] ) ? $rest[2] : '';
		$post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( 'notifications' === $page && ! $preview ) {
			$post ? self::notifications_post() : self::page_notifications();
		} elseif ( 'notifications' === $page ) {
			self::message_page( 'Notifications', 'Notifications are personal, so they are not shown in a preview.', 200, false );
		}
		if ( 'account' === $page ) {
			$post ? self::account_post() : self::page_account();
		} elseif ( 'messages' === $page && self::messages_on() ) {
			self::page_messages( $id );
		} elseif ( '' !== $page && isset( self::$ctx['pages'][ $page ] ) ) {
			$body = call_user_func( self::$ctx['pages'][ $page ]['render'], self::$ctx, $id, $sub );
			if ( is_string( $body ) ) {
				self::layout( self::$ctx['pages'][ $page ]['label'], $body, $page );
			}
		} elseif ( '' === $page ) {
			self::page_overview();
		}
		self::message_page( 'Page not found', 'We could not find that page.', 404, true );
	}

	/* ───────────────────────── Helpers (public: modules build their pages with these) ───────────────────────── */

	public static function e( $s ) {
		return esc_html( (string) $s );
	}

	/** A portal link that keeps staff in preview mode and the chosen organisation. */
	public static function link( $path = '', array $args = array() ) {
		if ( ! empty( self::$ctx['preview'] ) ) {
			$args['as'] = (int) self::$ctx['client']['id'];
		}
		return self::url( $path, $args );
	}

	public static function date( $d ) {
		return $d && '0000-00-00' !== substr( (string) $d, 0, 10 ) ? wp_date( get_option( 'date_format' ), strtotime( (string) $d ) ) : '—';
	}

	/** tone: neutral | info | success | warning | danger */
	public static function badge( $label, $tone = 'neutral' ) {
		return '<span class="badge badge--' . esc_attr( $tone ) . '">' . self::e( $label ) . '</span>';
	}

	public static function progress( $pct ) {
		return '<div class="bar" role="progressbar" aria-valuenow="' . (int) $pct . '" aria-valuemin="0" aria-valuemax="100"><span style="width:' . (int) $pct . '%"></span></div>';
	}

	public static function empty_state( $title, $text ) {
		return '<div class="empty"><strong>' . self::e( $title ) . '</strong><p>' . self::e( $text ) . '</p></div>';
	}

	/* ───────────────────────── Pages ───────────────────────── */

	private static function page_overview() {
		$c       = self::$ctx['client'];
		$user    = self::$ctx['user'];
		$name    = self::$ctx['preview'] ? $c['contact_person'] : $user->display_name;
		$h       = '<h1>Hello' . ( $name ? ', ' . self::e( explode( ' ', trim( $name ) )[0] ) : '' ) . '</h1>';
		$welcome = (string) Settings::get( 'portal_welcome' );
		$h      .= '<p class="lead">' . ( '' !== $welcome ? nl2br( self::e( $welcome ) ) : 'Here is everything we are doing for ' . self::e( $c['name'] ) . '.' ) . '</p>';
		$cards   = '';
		$attn    = '';
		$panels  = '';
		foreach ( self::$ctx['pages'] as $p ) {
			if ( is_callable( $p['overview'] ) ) {
				$o = (array) call_user_func( $p['overview'], self::$ctx );
				$cards  .= isset( $o['cards'] ) ? $o['cards'] : '';
				$attn   .= isset( $o['attention'] ) ? $o['attention'] : '';
				$panels .= isset( $o['panels'] ) ? $o['panels'] : '';
			}
		}
		if ( '' !== $cards ) {
			$h .= '<div class="stats">' . $cards . '</div>';
		}
		if ( '' !== $attn ) {
			$h .= '<section class="panel"><h2>Needs your attention</h2><ul class="list">' . $attn . '</ul></section>';
		}
		$h .= $panels;
		if ( '' === $cards . $attn . $panels && ! self::messages_on() ) {
			$h .= self::empty_state( 'Nothing to show yet', 'When we have something to share with you, it will appear here.' );
		}
		self::layout( 'Overview', $h, '' );
	}

	private static function unread() {
		if ( self::$ctx['preview'] ) {
			return 0;
		}
		$a = Services\Messages::actor();
		return $a ? Services\Messages::unread_total( $a ) : 0;
	}

	private static function page_messages( $thread_id ) {
		self::layout( 'Messages', Portal_Messages::render( self::$ctx, $thread_id ), 'messages' );
	}

	private static function page_notifications() {
		$u = self::$ctx['user'];
		if ( isset( $_GET['go'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$n = Services\Notifications::get_own( $u->ID, absint( $_GET['go'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( $n ) {
				Services\Notifications::mark_read( $u->ID, array( (int) $n['id'] ) );
				wp_safe_redirect( $n['link'] ? $n['link'] : self::url( 'notifications' ) );
				exit;
			}
		}
		$msg = isset( $_GET['bizycore_msg'] ) ? sanitize_key( wp_unslash( $_GET['bizycore_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$h   = '<div class="head"><h1>Notifications</h1><form method="post" action="' . esc_url( self::link( 'notifications' ) ) . '">' . wp_nonce_field( 'bizycore_portal_notif', '_wpnonce', true, false ) . '<input type="hidden" name="do" value="read_all"><button class="btn btn--ghost" type="submit">Mark all as read</button></form></div><p class="lead">What has happened on your account.</p>';
		if ( 'saved' === $msg ) {
			$h .= '<div class="notice notice--ok">Your notification settings are saved.</div>';
		}
		$items = Services\Notifications::list_for( $u->ID, 'all', 40 );
		$h    .= '<section class="panel nlist">';
		if ( ! $items ) {
			$h .= '<p class="muted" style="margin:0">Nothing yet. You will see invoices, payments, contracts, project progress and messages here.</p>';
		}
		foreach ( $items as $n ) {
			$h .= '<a class="nitem' . ( $n['read'] ? '' : ' is-unread' ) . '" href="' . esc_url( self::link( 'notifications', array( 'go' => $n['id'] ) ) ) . '"><span class="ndot"></span><span><strong>' . self::e( $n['title'] ) . '</strong>' . ( $n['body'] ? '<span class="muted" style="display:block">' . self::e( $n['body'] ) . '</span>' : '' ) . '<span class="muted" style="display:block;font-size:12.5px">' . self::e( $n['group'] ) . ' · ' . self::e( wp_date( get_option( 'date_format' ) . ' H:i', strtotime( $n['at'] . ' UTC' ) ) ) . '</span></span></a>';
		}
		$h .= '</section><section class="panel"><h2>How you hear from us</h2><p class="muted">For each kind of update, choose whether it shows here and whether you also get an email.</p><form method="post" action="' . esc_url( self::link( 'notifications' ) ) . '">' . wp_nonce_field( 'bizycore_portal_notif', '_wpnonce', true, false ) . '<input type="hidden" name="do" value="prefs">';
		foreach ( Services\Notifications::prefs_for( $u->ID ) as $p ) {
			$h .= '<div class="prefrow"><span>' . self::e( $p['label'] ) . '</span><label><input type="checkbox" name="app[' . esc_attr( $p['type'] ) . ']" value="1" ' . checked( $p['app'], true, false ) . '> Show here</label>' . ( $p['external_email'] ? '<span class="muted">Email is sent separately</span>' : '<select name="email[' . esc_attr( $p['type'] ) . ']"><option value="instant" ' . selected( $p['email'], 'instant', false ) . '>Email me straight away</option><option value="digest" ' . selected( $p['email'], 'digest', false ) . '>Email me a daily summary</option><option value="off" ' . selected( $p['email'], 'off', false ) . '>Do not email me</option></select>' ) . '</div>';
		}
		$h .= '<p><button class="btn" type="submit">Save</button></p></form></section>';
		self::layout( 'Notifications', $h, 'notifications' );
	}

	private static function notifications_post() {
		$u = self::$ctx['user'];
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'bizycore_portal_notif' ) ) {
			wp_safe_redirect( self::link( 'notifications' ) );
			exit;
		}
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'read_all' === $do ) {
			Services\Notifications::mark_read( $u->ID );
		} elseif ( 'prefs' === $do ) {
			$app = isset( $_POST['app'] ) && is_array( $_POST['app'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['app'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$em  = isset( $_POST['email'] ) && is_array( $_POST['email'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['email'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$out = array();
			foreach ( Services\Notifications::prefs_for( $u->ID ) as $p ) {
				$out[ $p['type'] ] = array( 'app' => isset( $app[ $p['type'] ] ), 'email' => $p['external_email'] ? $p['email'] : ( $em[ $p['type'] ] ?? 'off' ) );
			}
			Services\Notifications::save_prefs( $u->ID, $out );
			wp_safe_redirect( self::link( 'notifications', array( 'bizycore_msg' => 'saved' ) ) );
			exit;
		}
		wp_safe_redirect( self::link( 'notifications' ) );
		exit;
	}

	private static function page_account() {
		$u   = self::$ctx['user'];
		$msg = isset( $_GET['bizycore_msg'] ) ? sanitize_key( wp_unslash( $_GET['bizycore_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$h   = '<h1>Your account</h1>';
		if ( 'reset' === $msg ) {
			$h .= '<div class="notice notice--ok">If your email is registered, a link to choose a new password is on its way.</div>';
		}
		$h .= '<section class="panel"><h2>Details</h2><dl class="dl"><dt>Name</dt><dd>' . self::e( self::$ctx['preview'] ? ( self::$ctx['client']['contact_person'] ?: '—' ) : $u->display_name ) . '</dd><dt>Email</dt><dd>' . self::e( self::$ctx['preview'] ? '—' : $u->user_email ) . '</dd><dt>Organisation</dt><dd>' . self::e( self::$ctx['client']['name'] ) . '</dd></dl></section>';
		if ( ! self::$ctx['preview'] ) {
			$h .= '<section class="panel"><h2>Password</h2><p class="muted">We will email you a link to choose a new password.</p><form method="post" action="' . esc_url( self::link( 'account' ) ) . '">' . wp_nonce_field( 'bizycore_portal_pw', '_wpnonce', true, false ) . '<button class="btn" type="submit">Email me a password link</button></form></section>';
		}
		self::layout( 'Account', $h, 'account' );
	}

	private static function account_post() {
		if ( self::$ctx['preview'] || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'bizycore_portal_pw' ) ) {
			wp_safe_redirect( self::link( 'account' ) );
			exit;
		}
		App_Login::send_portal_reset( self::$ctx['user']->user_login );
		wp_safe_redirect( self::link( 'account', array( 'bizycore_msg' => 'reset' ) ) );
		exit;
	}

	/* ───────────────────────── Layout ───────────────────────── */

	private static function brand() {
		$name = (string) Settings::get( 'business_name' );
		$logo = (string) Settings::get( 'business_logo_url' );
		return $logo ? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '">' : '<span class="brand__name">' . self::e( $name ?: Terminology::fmt( '{Client} portal' ) ) . '</span>';
	}

	private static function css() {
		return <<<'CSS'
:root{--bg:#f6f7f9;--card:#fff;--ink:#101828;--muted:#667085;--ring:#e4e7ec;--ring2:#cfd4dc;--accent:#14161d;--on:#fff;--link:#2f54e0;--ok:#067647;--ok-bg:#ecfdf3;--warn:#b54708;--warn-bg:#fffaeb;--info:#175cd3;--info-bg:#eff8ff;--bad:#b42318;--bad-bg:#fef3f2;--neu:#344054;--neu-bg:#f2f4f7;--logo-bg:transparent}
@media (prefers-color-scheme:dark){:root:not([data-theme=light]){--bg:#0e1014;--card:#16181d;--ink:#f2f4f7;--muted:#98a2b3;--ring:#262a31;--ring2:#363b44;--accent:#f2f4f7;--on:#101828;--link:#8ea5ff;--ok:#75e0a7;--ok-bg:#0f2a1c;--warn:#fec84b;--warn-bg:#2b2108;--info:#84caff;--info-bg:#0b2236;--bad:#fda29b;--bad-bg:#2a1513;--neu:#d0d5dd;--neu-bg:#222630;--logo-bg:#fff}}
:root[data-theme=dark]{--bg:#0e1014;--card:#16181d;--ink:#f2f4f7;--muted:#98a2b3;--ring:#262a31;--ring2:#363b44;--accent:#f2f4f7;--on:#101828;--link:#8ea5ff;--ok:#75e0a7;--ok-bg:#0f2a1c;--warn:#fec84b;--warn-bg:#2b2108;--info:#84caff;--info-bg:#0b2236;--bad:#fda29b;--bad-bg:#2a1513;--neu:#d0d5dd;--neu-bg:#222630;--logo-bg:#fff}
*{box-sizing:border-box}html,body{margin:0}body{background:var(--bg);color:var(--ink);font:400 15px/1.55 'Google Sans',Inter,-apple-system,'Segoe UI',Roboto,sans-serif}
a{color:var(--link);text-underline-offset:3px}h1{margin:0 0 6px;font-size:26px;line-height:1.2;font-weight:500;letter-spacing:-.015em}h2{margin:0 0 12px;font-size:16px;font-weight:500}
header.top{background:var(--card);border-bottom:1px solid var(--ring);position:sticky;top:0;z-index:5}
.bar-in{max-width:1080px;margin:0 auto;padding:0 20px;display:flex;align-items:center;gap:20px;min-height:60px;flex-wrap:wrap}
.brand{display:flex;align-items:center;color:var(--ink);text-decoration:none;font-weight:500;font-size:17px}.brand img{height:34px;width:auto;min-width:60px;max-width:180px;object-fit:contain;background:var(--logo-bg);padding:4px 8px;border-radius:8px}
nav.main{display:flex;gap:2px;overflow-x:auto;flex:1}nav.main a{padding:8px 12px;border-radius:8px;color:var(--muted);text-decoration:none;white-space:nowrap;font-weight:500}nav.main a:hover{background:var(--bg);color:var(--ink)}nav.main a.is-on{background:var(--bg);color:var(--ink)}
.who{display:flex;align-items:center;gap:10px;font-size:13px;color:var(--muted)}.who select{height:34px;border:1px solid var(--ring2);border-radius:8px;background:var(--card);color:var(--ink);padding:0 8px;font:inherit;font-size:13px}.who a{color:var(--muted)}
main.wrap{max-width:1080px;margin:0 auto;padding:28px 20px 60px}
.lead{margin:0 0 22px;color:var(--muted)}.muted{color:var(--muted);font-size:13.5px}.crumb{margin:0 0 10px;font-size:13.5px}
.preview{background:var(--warn-bg);color:var(--warn);text-align:center;padding:8px 12px;font-size:13px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:0 0 22px}
.stat{display:flex;flex-direction:column;gap:2px;background:var(--card);border:1px solid var(--ring);border-radius:12px;padding:16px;text-decoration:none;color:var(--ink)}a.stat:hover{border-color:var(--ring2)}.stat__l{color:var(--muted);font-size:13px}.stat__v{font-size:21px;font-weight:500;letter-spacing:-.01em}.stat__s{color:var(--muted);font-size:13px}
.panel{background:var(--card);border:1px solid var(--ring);border-radius:12px;padding:20px;margin:0 0 16px}
.head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.head h1,.head h2{margin:0}
.list{list-style:none;margin:0;padding:0}.list li{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid var(--ring);flex-wrap:wrap}.list li:first-child{border-top:0}.list li span{color:var(--muted);font-size:13.5px}.list a{color:var(--ink)}
.list__proj{display:grid!important;grid-template-columns:1fr 160px auto;align-items:center}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px}.card{display:block;background:var(--card);border:1px solid var(--ring);border-radius:12px;padding:18px;text-decoration:none;color:var(--ink)}.card:hover{border-color:var(--ring2)}.card__top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-bottom:8px}.card__meta{display:flex;justify-content:space-between;gap:8px;color:var(--muted);font-size:13px;margin-top:8px}.clamp{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:0 0 10px}
.bar{height:8px;background:var(--neu-bg);border-radius:999px;overflow:hidden}.bar span{display:block;height:100%;background:var(--accent);border-radius:999px}
.badge{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:500;white-space:nowrap}.badge--success{background:var(--ok-bg);color:var(--ok)}.badge--warning{background:var(--warn-bg);color:var(--warn)}.badge--info{background:var(--info-bg);color:var(--info)}.badge--danger{background:var(--bad-bg);color:var(--bad)}.badge--neutral{background:var(--neu-bg);color:var(--neu)}
.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:14px}th{text-align:left;color:var(--muted);font-weight:500;font-size:12.5px;padding:8px 10px;border-bottom:1px solid var(--ring);white-space:nowrap}td{padding:11px 10px;border-bottom:1px solid var(--ring);vertical-align:top}tfoot td{border-bottom:0;font-weight:500}.num{text-align:right;white-space:nowrap}td a{color:var(--ink)}
.tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 16px}.tab{padding:7px 14px;border:1px solid var(--ring);border-radius:999px;color:var(--muted);text-decoration:none;font-size:13.5px;background:var(--card)}.tab.is-on{background:var(--accent);color:var(--on);border-color:var(--accent)}
.btn{display:inline-block;padding:10px 18px;border-radius:8px;background:var(--accent);color:var(--on);text-decoration:none;font:inherit;font-weight:500;border:1px solid var(--accent);cursor:pointer}.btn--ghost{background:transparent;color:var(--ink);border-color:var(--ring2)}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.notice{background:var(--info-bg);color:var(--info);border-radius:10px;padding:12px 14px;margin:0 0 16px}.notice--ok{background:var(--ok-bg);color:var(--ok)}
.empty{background:var(--card);border:1px dashed var(--ring2);border-radius:12px;padding:34px 20px;text-align:center}.empty p{margin:4px 0 0;color:var(--muted)}
.tasks{list-style:none;margin:8px 0 0;padding:0}.task{display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--ring)}.task:first-child{border-top:0}.task__t{flex:1}.task__m{color:var(--muted);font-size:13px}.task.is-done .task__t{color:var(--muted);text-decoration:line-through}
.meta{display:flex;gap:18px;color:var(--muted);font-size:13.5px;margin:6px 0 14px;flex-wrap:wrap}.signers{list-style:none;margin:12px 0 0;padding:0}.signers li{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-top:1px solid var(--ring)}
.dl{display:grid;grid-template-columns:140px 1fr;gap:8px 14px;margin:0}.dl dt{color:var(--muted)}.dl dd{margin:0}.prose{color:var(--ink)}
footer.foot{text-align:center;color:var(--muted);font-size:12.5px;padding:0 0 30px}
a .navbadge{display:inline-block;min-width:18px;height:18px;line-height:18px;border-radius:999px;background:var(--accent);color:var(--on);font-size:11px;text-align:center;padding:0 5px;margin-left:4px;font-weight:600}.navbadge[hidden]{display:none}
@media (max-width:640px){nav.main{order:3;flex-basis:100%}.who{margin-left:auto}main.wrap{padding:20px 14px 50px}h1{font-size:22px}.list__proj{grid-template-columns:1fr}.dl{grid-template-columns:1fr}.bar-in{gap:10px;padding:8px 14px}}

.nlist{padding:6px 20px}.nitem{display:flex;gap:12px;padding:13px 0;border-top:1px solid var(--ring);text-decoration:none;color:var(--ink)}.nitem:first-child{border-top:0}.ndot{flex:none;width:9px;height:9px;border-radius:50%;margin-top:7px}.nitem.is-unread .ndot{background:var(--link)}.nitem:not(.is-unread) strong{font-weight:400;color:var(--muted)}
.prefrow{display:flex;align-items:center;gap:16px;padding:10px 0;border-top:1px solid var(--ring);flex-wrap:wrap}.prefrow>span:first-child{flex:1;min-width:200px}.prefrow select{height:36px;border:1px solid var(--ring2);border-radius:8px;background:var(--card);color:var(--ink);padding:0 8px;font:inherit}
@media print{header.top,.tabs,.actions,.preview{display:none}body{background:#fff}}
CSS;
	}

	private static function layout( $title, $body, $active ) {
		$c    = self::$ctx['client'];
		$nav  = array( '' => 'Overview' );
		foreach ( self::$ctx['pages'] as $slug => $pg ) {
			$nav[ $slug ] = $pg['label'];
		}
		if ( self::messages_on() ) {
			$nav['messages'] = 'Messages';
		}
		$label = (string) Settings::get( 'portal_title' );
		$label = '' !== $label ? $label : Terminology::fmt( '{Client} portal' );
		status_header( 200 );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title><?php echo esc_html( $title . ' — ' . $label ); ?></title>
<style>@font-face{font-family:'Google Sans';font-style:normal;font-weight:400 700;font-display:swap;src:url('<?php echo esc_url( class_exists( __NAMESPACE__ . '\\Assets' ) ? Assets::font_base() . 'google-sans-latin.woff2' : '' ); ?>') format('woff2');}<?php echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput ?></style>
</head>
<body>
		<?php if ( self::$ctx['preview'] ) : ?>
<div class="preview">Preview: this is what <?php echo esc_html( $c['name'] ); ?> sees. <a href="<?php echo esc_url( Urls::app( '/clients/' . (int) $c['id'] ) ); ?>">Back to Bizy</a></div>
		<?php endif; ?>
<header class="top"><div class="bar-in">
	<a class="brand" href="<?php echo esc_url( self::link() ); ?>"><?php echo self::brand(); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
	<nav class="main" aria-label="Portal">
		<?php foreach ( $nav as $k => $l ) : ?>
		<a href="<?php echo esc_url( self::link( $k ) ); ?>" class="<?php echo $active === $k ? 'is-on' : ''; ?>"><?php echo esc_html( $l ); ?><?php if ( 'messages' === $k ) : $un = self::unread(); ?> <span class="navbadge" id="bdm-nav-badge" <?php echo $un ? '' : 'hidden'; ?>><?php echo $un ? (int) $un : ''; ?></span><?php endif; ?></a>
		<?php endforeach; ?>
	</nav>
	<div class="who">
		<?php if ( count( self::$ctx['ids'] ) > 1 && ! self::$ctx['preview'] ) : ?>
		<form method="get" action="<?php echo esc_url( self::url() ); ?>"><select name="org" onchange="this.form.submit()" aria-label="Organisation">
			<?php foreach ( self::$ctx['ids'] as $oid ) : $o = self::client( $oid ); if ( ! $o ) { continue; } ?>
			<option value="<?php echo (int) $oid; ?>" <?php selected( (int) $oid, (int) $c['id'] ); ?>><?php echo esc_html( $o['name'] ); ?></option>
			<?php endforeach; ?>
		</select></form>
		<?php else : ?>
		<span><?php echo esc_html( $c['name'] ); ?></span>
		<?php endif; ?>
		<?php if ( ! self::$ctx['preview'] ) : $nn = Services\Notifications::unread_count( self::$ctx['user']->ID ); ?>
		<a href="<?php echo esc_url( self::link( 'notifications' ) ); ?>" aria-label="Notifications">🔔<?php if ( $nn ) : ?> <span class="navbadge"><?php echo (int) $nn; ?></span><?php endif; ?></a>
		<a href="<?php echo esc_url( self::link( 'account' ) ); ?>">Account</a>
		<a href="<?php echo esc_url( wp_logout_url( self::url( '', array( 'bizycore_msg' => 'out' ) ) ) ); ?>">Sign out</a>
		<?php endif; ?>
	</div>
</div></header>
<main class="wrap"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput ?></main>
<footer class="foot"><?php echo esc_html( Settings::get( 'business_name' ) ); ?></footer>
</body>
</html>
		<?php
		exit;
	}

	/** A plain page for "not available / not found / not linked" (with sign-out for signed-in people). */
	private static function message_page( $title, $text, $status = 200, $signed_in = false ) {
		status_header( $status );
		$name = (string) Settings::get( 'business_name' );
		?>
<!doctype html>
<html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><meta name="color-scheme" content="light dark"><title><?php echo esc_html( $title ); ?></title>
<style><?php echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput ?>body{display:grid;place-items:center;min-height:100vh;padding:20px}.msg{max-width:440px;text-align:center}</style></head>
<body><div class="msg"><h1><?php echo esc_html( $title ); ?></h1><p class="lead"><?php echo esc_html( $text ); ?></p>
<?php if ( $signed_in ) : ?><p><a class="btn btn--ghost" href="<?php echo esc_url( wp_logout_url( self::url() ) ); ?>">Sign out</a></p><?php endif; ?>
<p class="muted"><?php echo esc_html( $name ); ?></p></div></body></html>
		<?php
		exit;
	}

	/* ───────────────────────── Invites ───────────────────────── */

	/** Email a client contact their portal address with a link to choose a password. @return true|\WP_Error */
	public static function invite( $user_id ) {
		$u = get_userdata( (int) $user_id );
		if ( ! $u || ! user_can( $u, 'bizycore_portal' ) ) {
			return new \WP_Error( 'bizycore_invite', 'That person does not have a client login.', array( 'status' => 400 ) );
		}
		$name = (string) Settings::get( 'business_name' );
		$name = $name ? $name : wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$key  = get_password_reset_key( $u );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$link = add_query_arg( array( 'key' => $key, 'login' => rawurlencode( $u->user_login ) ), self::url() );
		$html = '<p>Hi ' . esc_html( $u->first_name ?: $u->display_name ) . ',</p><p>' . esc_html( $name ) . ' has set up a ' . Terminology::fmt( '{client} portal' ) . ' for you. There you can follow your projects and tasks, see your invoices and what is coming up, and download signed contracts.</p><p><a href="' . esc_url( $link ) . '">Choose your password and sign in</a></p><p>Your username is <strong>' . esc_html( $u->user_login ) . '</strong>, or you can use your email address. This link works for a limited time; after that you can always use “Forgot password” on the sign-in page: ' . esc_url( self::url() ) . '</p>';
		$r = Services\Mailer::send( array( 'to' => array( $u->user_email ), 'subject' => 'Your ' . $name . ' ' . Terminology::fmt( '{client} portal' ), 'heading' => 'Your ' . Terminology::fmt( '{client} portal' ) . ' is ready', 'html' => $html, 'text' => "Hi,\n\n{$name} has set up a " . Terminology::fmt( '{client} portal' ) . " for you.\nChoose your password and sign in: {$link}\n" ) );
		if ( empty( $r['ok'] ) ) {
			return new \WP_Error( 'bizycore_mail', $r['error'] ?? 'The email could not be sent.', array( 'status' => 500 ) );
		}
		update_user_meta( $u->ID, 'bizycore_portal_invited', time() );
		return true;
	}
}
