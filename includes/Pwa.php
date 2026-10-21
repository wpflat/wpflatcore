<?php
namespace WPFlatCore;

defined( 'ABSPATH' ) || exit;

/**
 * Makes the app installable on a phone: a web app manifest, a small service worker, an offline page and the home-screen icons.
 * Everything is answered at the app's own address (for example /app/manifest.webmanifest) and uses the owner's own app name,
 * colours and logo, never the product name. The service worker never stores signed-in pages or API answers: it keeps the
 * app's scripts, styles and fonts so it opens quickly, and shows a friendly page when there is no connection.
 */
class Pwa {

	const FILES = array( 'manifest.webmanifest', 'sw.js', 'offline.html', 'icon-192.png', 'icon-512.png', 'apple-touch-icon.png' );

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), -1 );
	}

	/** Address of one of the files, e.g. url( 'sw.js' ). */
	public static function url( $file ) {
		return home_url( '/' . App_Page::slug() . '/' . $file );
	}

	/** The part of the address the installed app lives under, e.g. /app/ (or / when the app is the home page). */
	public static function scope() {
		$p = (string) wp_parse_url( App_Page::url(), PHP_URL_PATH );
		return '' !== $p ? trailingslashit( $p ) : '/';
	}

	/** What goes in the page head so phones offer "install". */
	public static function head_tags() {
		$name  = Settings::app_name();
		$color = '#f4f2ee';
		return '<meta name="theme-color" content="' . esc_attr( $color ) . '">'
			. '<link rel="manifest" href="' . esc_url( self::url( 'manifest.webmanifest' ) ) . '">'
			. '<link rel="apple-touch-icon" href="' . esc_url( self::url( 'apple-touch-icon.png' ) ) . '">'
			. '<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes">'
			. '<meta name="apple-mobile-web-app-title" content="' . esc_attr( $name ) . '"><meta name="apple-mobile-web-app-status-bar-style" content="default">';
	}

	public static function serve() {
		$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$rel  = trim( substr( (string) $path, strlen( $home ) ), '/' );
		$pre  = App_Page::slug() . '/';
		if ( 0 !== strpos( $rel, $pre ) ) {
			return;
		}
		$file = substr( $rel, strlen( $pre ) );
		if ( ! in_array( $file, self::FILES, true ) || ! Settings::get( 'app_frontend' ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		status_header( 200 ); // WordPress has already marked these addresses "not found"
		header( 'X-Content-Type-Options: nosniff' );
		switch ( $file ) {
			case 'manifest.webmanifest':
				header( 'Content-Type: application/manifest+json; charset=utf-8' );
				header( 'Cache-Control: no-cache' );
				echo wp_json_encode( self::manifest(), JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'sw.js':
				header( 'Content-Type: application/javascript; charset=utf-8' );
				header( 'Cache-Control: no-cache' );
				header( 'Service-Worker-Allowed: ' . self::scope() );
				echo self::worker(); // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'offline.html':
				header( 'Content-Type: text/html; charset=utf-8' );
				header( 'Cache-Control: no-cache' );
				echo self::offline(); // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			default:
				self::icon( 'apple-touch-icon.png' === $file ? 180 : (int) preg_replace( '/\D/', '', $file ) );
		}
		exit;
	}

	private static function manifest() {
		$name  = Settings::app_name();
		$short = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 12 ) : substr( $name, 0, 12 );
		return array(
			'id'               => self::scope(),
			'name'             => $name,
			'short_name'       => $short,
			'description'      => $name,
			'start_url'        => add_query_arg( 'source', 'pwa', App_Page::url() ),
			'scope'            => self::scope(),
			'display'          => 'standalone',
			'orientation'      => 'portrait-primary',
			'background_color' => '#f4f2ee',
			'theme_color'      => '#f4f2ee',
			'icons'            => array(
				array( 'src' => self::url( 'icon-192.png' ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => self::url( 'icon-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => self::url( 'icon-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
			),
		);
	}

	private static function worker() {
		$scope   = wp_json_encode( self::scope() );
		$offline = wp_json_encode( self::url( 'offline.html' ) );
		return <<<JS
/* Offline support for the installed app. Signed-in pages and API answers are never stored. */
const V = 'wpflat-app-v1';
const SCOPE = {$scope};
const OFFLINE = {$offline};
self.addEventListener('install', (e) => { e.waitUntil(caches.open(V).then((c) => c.add(OFFLINE)).then(() => self.skipWaiting())); });
self.addEventListener('activate', (e) => { e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== V).map((k) => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', (e) => {
	const r = e.request;
	if (r.method !== 'GET') return;
	const u = new URL(r.url);
	if (u.origin !== location.origin) return;
	if (r.mode === 'navigate' && u.pathname.indexOf(SCOPE) === 0) {
		e.respondWith(fetch(r).catch(() => caches.match(OFFLINE)));
		return;
	}
	if (/\\/wp-content\\/(plugins\\/wpflat[a-z]*\\/(build|assets)|uploads\\/wpflat-fonts)\\//.test(u.pathname) && /\\.(js|css|woff2?|png|svg)$/.test(u.pathname)) {
		e.respondWith(caches.open(V).then((c) => c.match(r).then((hit) => {
			const net = fetch(r).then((res) => { if (res.ok) c.put(r, res.clone()); return res; }).catch(() => hit);
			return hit || net;
		})));
	}
});
JS;
	}

	private static function offline() {
		$name = esc_html( Settings::app_name() );
		return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex"><title>' . $name . '</title>'
			. '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f4f2ee;color:#1e2230;font-family:-apple-system,system-ui,Roboto,sans-serif;padding:24px;box-sizing:border-box}.c{max-width:340px;text-align:center}.m{width:64px;height:64px;border-radius:20px;background:#1e2230;color:#f4f2ee;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;margin:0 auto 20px;text-transform:uppercase}h1{font-size:24px;margin:0 0 8px}p{color:#5f6477;line-height:1.5;margin:0 0 20px}a{display:inline-block;padding:14px 28px;border-radius:16px;background:#1e2230;color:#fff;text-decoration:none;font-weight:700}</style></head>'
			. '<body><div class="c"><div class="m">' . esc_html( function_exists( 'mb_substr' ) ? mb_substr( Settings::app_name(), 0, 2 ) : substr( Settings::app_name(), 0, 2 ) ) . '</div><h1>You are offline</h1><p>' . $name . ' needs a connection to show your latest information. Check your connection and try again.</p><a href="' . esc_url( App_Page::url() ) . '">Try again</a></div></body></html>';
	}

	/** A square PNG: the business logo centred on white, or the app name's first letters on the brand colour. Cached in uploads. */
	private static function icon( $size ) {
		$size = in_array( (int) $size, array( 180, 192, 512 ), true ) ? (int) $size : 192;
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			status_header( 404 );
			return;
		}
		$logo_id = (int) Settings::get( 'business_logo_id' );
		$logo    = $logo_id ? (string) get_attached_file( $logo_id ) : '';
		if ( $logo && ( ! is_readable( $logo ) || preg_match( '/\.svg$/i', $logo ) ) ) {
			$logo = '';
		}
		$initials = strtoupper( substr( preg_replace( '/[^A-Za-z0-9]/', '', Settings::app_name() ), 0, 2 ) );
		$initials = '' !== $initials ? $initials : 'W';
		$key      = md5( $logo . ( $logo ? filemtime( $logo ) : '' ) . $initials . $size );
		$dir      = trailingslashit( wp_upload_dir()['basedir'] ) . 'wpflat-pwa/';
		$cache    = $dir . $key . '.png';
		if ( ! is_file( $cache ) ) {
			wp_mkdir_p( $dir );
			$im = imagecreatetruecolor( $size, $size );
			if ( $logo ) {
				imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 255, 255 ) );
				$ed = wp_get_image_editor( $logo );
				if ( ! is_wp_error( $ed ) ) {
					$inner = (int) round( $size * 0.62 );
					$ed->resize( $inner, $inner, false );
					$tmp = $dir . $key . '-logo.png';
					$ed->save( $tmp, 'image/png' );
					$lg = is_file( $tmp ) ? imagecreatefromstring( (string) file_get_contents( $tmp ) ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
					if ( $lg ) {
						imagealphablending( $im, true );
						imagecopy( $im, $lg, (int) ( ( $size - imagesx( $lg ) ) / 2 ), (int) ( ( $size - imagesy( $lg ) ) / 2 ), 0, 0, imagesx( $lg ), imagesy( $lg ) );
						imagedestroy( $lg );
					}
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				}
			} else {
				imagefill( $im, 0, 0, imagecolorallocate( $im, 30, 34, 48 ) );
				$small = imagecreatetruecolor( 48, 48 ); // draw the letters small, then scale up smoothly
				imagefill( $small, 0, 0, imagecolorallocate( $small, 30, 34, 48 ) );
				$w = imagefontwidth( 5 ) * strlen( $initials );
				imagestring( $small, 5, (int) ( ( 48 - $w ) / 2 ), (int) ( ( 48 - imagefontheight( 5 ) ) / 2 ), $initials, imagecolorallocate( $small, 244, 242, 238 ) );
				imagecopyresampled( $im, $small, 0, 0, 0, 0, $size, $size, 48, 48 );
				imagedestroy( $small );
			}
			imagepng( $im, $cache );
			imagedestroy( $im );
		}
		header( 'Content-Type: image/png' );
		header( 'Cache-Control: public, max-age=86400' );
		readfile( $cache ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
