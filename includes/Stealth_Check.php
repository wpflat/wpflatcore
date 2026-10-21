<?php
namespace WPFlatCore;

defined( 'ABSPATH' ) || exit;

/**
 * "What can an outsider see?": the server asks its own public addresses the way a stranger would (no cookies) and
 * reports every fingerprint that still says WordPress.
 */
class Stealth_Check {

	private static function get( $path ) {
		$r = wp_remote_get( home_url( $path ), array( 'timeout' => 10, 'redirection' => 0, 'sslverify' => false, 'cookies' => array(), 'headers' => array( 'Cache-Control' => 'no-cache' ), 'user-agent' => 'Mozilla/5.0 (StealthCheck)' ) );
		if ( is_wp_error( $r ) ) {
			return array( 0, '', array() );
		}
		return array( (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ), wp_remote_retrieve_headers( $r ) );
	}

	public static function run() {
		@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$items = array();
		$add   = function ( $group, $label, $ok, $detail = '', $info = false ) use ( &$items ) {
			$items[] = array( 'group' => $group, 'label' => $label, 'ok' => (bool) $ok, 'detail' => $detail, 'info' => $info );
		};
		// Addresses that must NOT answer like WordPress.
		$hidden = array(
			'/wp-login.php' => 'WordPress sign-in', '/wp-admin/' => 'WordPress admin', '/wp-json/' => 'REST API index', '/wp-json/wp/v2/users' => 'User list (REST)', '/?rest_route=/' => 'REST API (alternate address)', '/xmlrpc.php' => 'XML-RPC',
			'/feed/' => 'RSS feed', '/?author=1' => 'Author lookup (reveals usernames)', '/readme.html' => 'readme.html', '/license.txt' => 'license.txt', '/wp-includes/js/wp-emoji-release.min.js' => 'wp-includes files', '/wp-content/plugins/bizydash/bizydash.php' => 'Plugin file under wp-content',
			'/wp-content/uploads/' => 'Uploads folder', '/wp-sitemap.xml' => 'WordPress sitemap', '/wp-links-opml.php' => 'wp-links-opml.php', '/wp-signup.php' => 'wp-signup.php',
		);
		foreach ( $hidden as $p => $label ) {
			list( $code ) = self::get( $p );
			$leak = 0 !== $code && 404 !== $code; // only a plain 404 hides it (a 403 or a redirect still shows something is there)
			$add( 'Hidden addresses', $label . ' (' . $p . ')', ! $leak && 0 !== $code, $code ? 'Answers ' . $code : 'No answer' );
		}
		$rules = Stealth_Rules::status();
		$add( 'Hidden addresses', 'wp-cron.php hidden from outside', ! empty( $rules['applied'] ), ! empty( $rules['applied'] ) ? 'Hidden by the server rules (this check runs from the server itself, which is allowed in).' : 'Needs the server rules switched on.' );
		list( $code ) = self::get( '/zz-' . wp_generate_password( 8, false ) . '-not-here' );
		$add( 'Hidden addresses', 'Unknown address shows a plain 404', 404 === $code, 'Answers ' . $code );
		// Addresses that must work.
		foreach ( array( '/' => 'Home', App_Page::url() => 'App', Portal::url() => 'Client portal', Assets::url( 'wpflatcore.js' ) => 'App file', Assets::url( 'wpflatcore.css' ) => 'App styles' ) as $u => $label ) {
			$path = 0 === strpos( $u, 'http' ) ? (string) wp_parse_url( $u, PHP_URL_PATH ) : $u;
			list( $c ) = self::get( $path );
			$add( 'Still working', $label, in_array( $c, array( 200, 302 ), true ), 'Answers ' . $c );
		}
		// What the pages say about themselves.
		foreach ( array( '/' => 'Home', App_Page::url() => 'App sign-in', Portal::url() => 'Portal sign-in' ) as $u => $label ) {
			$path = 0 === strpos( $u, 'http' ) ? (string) wp_parse_url( $u, PHP_URL_PATH ) : $u;
			list( $c, $body, $h ) = self::get( $path );
			if ( 200 !== $c ) {
				continue;
			}
			$found = array();
			foreach ( array( 'wp-content', 'wp-includes', 'wp-json', 'wp-login', 'wp-admin', 'api.w.org', 'wlwmanifest', 'xmlrpc', 'name="generator"' ) as $needle ) {
				if ( false !== stripos( $body, $needle ) ) {
					$found[] = $needle;
				}
			}
			$add( 'Page source', $label . ' mentions no WordPress paths', ! $found, $found ? 'Found: ' . implode( ', ', $found ) : '' );
			if ( '/' === $path ) {
				$hdr = array();
				foreach ( array( 'x-powered-by', 'x-pingback', 'link', 'x-redirect-by' ) as $k ) {
					if ( isset( $h[ $k ] ) && ( 'link' !== $k || false !== stripos( (string) $h[ $k ], 'api.w.org' ) ) ) {
						$hdr[] = $k;
					}
				}
				$add( 'Headers', 'No WordPress or PHP headers', ! $hdr, $hdr ? 'Still sent: ' . implode( ', ', $hdr ) : '' );
				$host = array();
				foreach ( array( 'platform', 'panel', 'server' ) as $k ) {
					if ( isset( $h[ $k ] ) ) {
						$host[] = $k . ': ' . $h[ $k ];
					}
				}
				if ( $host ) {
					$add( 'Headers', 'Your host’s own headers (not WordPress)', true, implode( '  ·  ', $host ) . ' — these identify the hosting, not WordPress, and are added outside the site.', true );
				}
			}
		}
		$fail = count( array_filter( $items, function ( $i ) {
			return ! $i['ok'];
		} ) );
		return array( 'items' => $items, 'failed' => $fail, 'passed' => count( $items ) - $fail, 'at' => gmdate( 'Y-m-d H:i:s' ) );
	}
}
