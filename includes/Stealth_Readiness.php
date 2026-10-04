<?php
namespace BizyCore;

defined( 'ABSPATH' ) || exit;

/**
 * "Will this hosting cope?" A set of small, non-destructive tests run BEFORE anything risky can be switched on. Each
 * test says pass / warn / fail / info, and which layers a failure blocks:
 *   files    the Bizy files folder and moving files into it
 *   stealth  the PHP layer that hides WordPress's entry points
 *   rules    the .htaccess server rules
 * The result and the owner's acknowledgement are stored; the server refuses to enable a layer without both.
 */
class Stealth_Readiness {

	const OPTION   = 'bizycore_stealth_ready';
	const VALID_FOR = DAY_IN_SECONDS;

	public static function steps() {
		return array(
			'server'    => 'Server and PHP',
			'files'     => 'Files folder',
			'addresses' => 'Bizy addresses',
			'probe'     => 'Folder protection',
			'rules'     => 'Server rules',
			'loopback'  => 'The site calling itself',
			'plugins'   => 'Other plugins',
			'cdn'       => 'CDN and caches',
		);
	}

	public static function stored() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? wp_parse_args( $s, array( 'items' => array(), 'done' => array(), 'at' => 0, 'verdicts' => array(), 'ack_at' => 0 ) ) : array( 'items' => array(), 'done' => array(), 'at' => 0, 'verdicts' => array(), 'ack_at' => 0 );
	}

	private static function save( array $s ) {
		update_option( self::OPTION, $s, false );
	}

	public static function acknowledge( $yes ) {
		$s           = self::stored();
		$s['ack_at'] = $yes ? time() : 0;
		self::save( $s );
		return self::overview();
	}

	/** Is this layer allowed to be enabled right now? @return true|\WP_Error */
	public static function allows( $layer ) {
		$s = self::stored();
		if ( empty( $s['ack_at'] ) ) {
			return new \WP_Error( 'bd_not_ack', 'Read the instructions and tick “I have read the instructions and saved my recovery details” first.', array( 'status' => 400 ) );
		}
		if ( empty( $s['at'] ) || time() - (int) $s['at'] > self::VALID_FOR ) {
			return new \WP_Error( 'bd_not_ready', 'Run the hosting test first (it must have been run in the last 24 hours).', array( 'status' => 400 ) );
		}
		$v = $s['verdicts'][ $layer ] ?? 'blocked';
		if ( 'blocked' === $v ) {
			return new \WP_Error( 'bd_blocked', 'The hosting test found something this server cannot do for this step. Open the test results to see what and how to fix it.', array( 'status' => 400 ) );
		}
		return true;
	}

	public static function overview() {
		$s = self::stored();
		$fresh = ! empty( $s['at'] ) && time() - (int) $s['at'] <= self::VALID_FOR;
		return array(
			'steps'    => self::steps(),
			'items'    => array_values( $s['items'] ),
			'done'     => array_keys( $s['done'] ),
			'tested_at' => $s['at'] ? gmdate( 'Y-m-d H:i:s', (int) $s['at'] ) : '',
			'fresh'    => $fresh,
			'verdicts' => $fresh ? $s['verdicts'] : array(),
			'ack'      => ! empty( $s['ack_at'] ),
			'complete' => count( $s['done'] ) === count( self::steps() ),
		);
	}

	/** Run one step (or reset with 'start'). Called repeatedly by the screen so progress is visible. */
	public static function run_step( $id ) {
		$s = self::stored();
		if ( 'start' === $id ) {
			$s['items'] = array();
			$s['done']  = array();
			$s['at']    = 0;
			$s['verdicts'] = array();
			self::save( $s );
			return self::overview();
		}
		if ( ! isset( self::steps()[ $id ] ) ) {
			return new \WP_Error( 'bd_step', 'Unknown test.', array( 'status' => 400 ) );
		}
		@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$items = call_user_func( array( __CLASS__, 't_' . $id ) );
		foreach ( $items as &$i ) {
			$i['step'] = $id;
		}
		unset( $i );
		$s['items']        = array_values( array_filter( $s['items'], function ( $x ) use ( $id ) {
			return $x['step'] !== $id;
		} ) );
		$s['items']        = array_merge( $s['items'], $items );
		$s['done'][ $id ]  = true;
		if ( count( $s['done'] ) === count( self::steps() ) ) {
			$s['at']       = time();
			$s['verdicts'] = self::verdicts( $s['items'] );
		}
		self::save( $s );
		return self::overview();
	}

	/** ready | warn | blocked per layer. */
	private static function verdicts( array $items ) {
		$v = array( 'files' => 'ready', 'stealth' => 'ready', 'rules' => 'ready' );
		foreach ( $items as $i ) {
			foreach ( array_keys( $v ) as $layer ) {
				$affects = in_array( $layer, (array) ( $i['layers'] ?? array() ), true );
				if ( ! $affects ) {
					continue;
				}
				if ( 'fail' === $i['level'] ) {
					$v[ $layer ] = 'blocked';
				} elseif ( 'warn' === $i['level'] && 'blocked' !== $v[ $layer ] ) {
					$v[ $layer ] = 'warn';
				}
			}
		}
		return $v;
	}

	private static function item( $label, $level, $detail = '', $fix = '', array $layers = array() ) {
		return array( 'label' => $label, 'level' => $level, 'detail' => $detail, 'fix' => $fix, 'layers' => $layers );
	}

	private static function get( $url, array $args = array() ) {
		return wp_remote_get( $url, array_merge( array( 'timeout' => 10, 'redirection' => 0, 'sslverify' => false, 'cookies' => array(), 'headers' => array( 'Cache-Control' => 'no-cache' ) ), $args ) );
	}

	/* ───────────────────────── The tests ───────────────────────── */

	private static function t_server() {
		$out = array();
		$sw  = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$out[] = self::item( 'Web server: ' . $sw, 'info', Stealth_Rules::supported() ? 'Apache-style server: the server rules can work.' : 'This does not look like Apache or LiteSpeed, so the .htaccess server rules will not apply (the rest still works).' );
		$out[] = self::item( 'PHP ' . PHP_VERSION, version_compare( PHP_VERSION, '7.4', '>=' ) ? 'pass' : 'fail', '', 'Ask your host to use PHP 7.4 or newer.', array( 'files', 'stealth', 'rules' ) );
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		foreach ( array( 'random_bytes' => array( 'files', 'stealth' ), 'hash_hmac' => array( 'stealth' ), 'finfo_open' => array( 'files' ), 'readfile' => array( 'files', 'stealth' ), 'copy' => array( 'files' ), 'rename' => array( 'files', 'rules' ), 'chmod' => array() ) as $fn => $layers ) {
			$ok = function_exists( $fn ) && ! in_array( $fn, $disabled, true );
			$out[] = self::item( 'PHP function ' . $fn . '()', $ok ? 'pass' : ( $layers ? 'fail' : 'warn' ), $ok ? '' : 'Not available on this server.', $ok ? '' : 'Ask your host to enable ' . $fn . '().', $layers );
		}
		$ob = (string) ini_get( 'open_basedir' );
		$out[] = self::item( 'open_basedir', $ob ? 'warn' : 'pass', $ob ? 'PHP is restricted to: ' . $ob . '. Keep the files folder inside wp-content.' : 'No restriction.', '', array( 'files' ) );
		return $out;
	}

	private static function t_files() {
		$out  = array();
		$root = Paths::root();
		$t    = Paths::targets();
		if ( ! wp_is_writable( WP_CONTENT_DIR ) && ! is_dir( $root ) ) {
			return array( self::item( 'wp-content is read-only', 'fail', WP_CONTENT_DIR . ' cannot be written by the site.', 'Give the web server write permission to the wp-content folder (usually 755 or 775), or ask your host.', array( 'files' ) ) );
		}
		$ok = false;
		if ( wp_mkdir_p( $root . '/private' ) ) {
			$f = $root . '/private/zz-test-' . bin2hex( random_bytes( 4 ) );
			$ok = false !== file_put_contents( $f, 'bizycore' ) && 'bizycore' === file_get_contents( $f ) && unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$out[] = self::item( 'Create, write, read and delete a file in wp-content/bizy-files', $ok ? 'pass' : 'fail', $root, $ok ? '' : 'The folder cannot be created or written. Check permissions on wp-content.', array( 'files' ) );
		$free = @disk_free_space( WP_CONTENT_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$out[] = self::item( 'Free disk space', false === $free || $free > 200 * MB_IN_BYTES ? 'pass' : ( $free > 30 * MB_IN_BYTES ? 'warn' : 'fail' ), false === $free ? 'Could not be read.' : size_format( (int) $free ) . ' free', 'Free some space; backups and files need room.', array( 'files' ) );
		$out[] = self::item( 'Outside the web root (advanced)', $t['outside']['writable'] ? 'info' : 'info', $t['outside']['writable'] ? 'Possible here, but a host update, restore or migration may remove a folder beside WordPress. wp-content is the safer home.' : 'Not possible on this host (the folder beside WordPress is not writable). That is fine: wp-content is recommended anyway.' );
		return $out;
	}

	private static function t_addresses() {
		$out = array();
		$css = self::get( Assets::url( 'bizycore.css' ) );
		$ok  = ! is_wp_error( $css ) && 200 === (int) wp_remote_retrieve_response_code( $css ) && false !== stripos( (string) wp_remote_retrieve_header( $css, 'content-type' ), 'css' );
		$out[] = self::item( 'App files load from /bd/a/…', $ok ? 'pass' : 'fail', is_wp_error( $css ) ? $css->get_error_message() : 'Answered ' . wp_remote_retrieve_response_code( $css ), 'Bizy addresses need pretty permalinks (Settings → Permalinks, anything but “Plain”) and a working rewrite setup.', array( 'stealth', 'rules' ) );
		$name = 'zz-ready-' . bin2hex( random_bytes( 4 ) ) . '.txt';
		$pub  = Paths::dir( 'public' );
		file_put_contents( $pub . '/' . $name, 'bizycore-ok' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$r = self::get( Media::url( $name ) );
		@unlink( $pub . '/' . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		$ok = ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) && 'bizycore-ok' === trim( (string) wp_remote_retrieve_body( $r ) );
		$out[] = self::item( 'Public files load from /bd/f/…', $ok ? 'pass' : 'fail', is_wp_error( $r ) ? $r->get_error_message() : 'Answered ' . wp_remote_retrieve_response_code( $r ), 'Check permalinks and that nothing (a cache or firewall) intercepts /bd/ addresses.', array( 'files', 'stealth', 'rules' ) );
		$api = self::get( Assets::api_base() . '/zz-nothing' );
		$ok  = ! is_wp_error( $api ) && false !== stripos( (string) wp_remote_retrieve_header( $api, 'content-type' ), 'json' );
		$out[] = self::item( 'The Bizy API answers at /bd/api/…', $ok ? 'pass' : 'fail', is_wp_error( $api ) ? $api->get_error_message() : 'Answered ' . wp_remote_retrieve_response_code( $api ), 'Same as above: permalinks and rewrites.', array( 'stealth', 'rules' ) );
		$out[] = self::item( 'Pretty permalinks', '' !== (string) get_option( 'permalink_structure' ) ? 'pass' : 'warn', get_option( 'permalink_structure' ) ?: 'Plain', 'Settings → Permalinks → choose “Post name”.', array( 'stealth' ) );
		return $out;
	}

	/** The private folder must not be readable from the web. */
	private static function t_probe() {
		$root = Paths::root();
		$dir  = $root . '/private/probe';
		Paths::ensure( $root );
		wp_mkdir_p( $dir );
		Paths::ensure( $dir );
		$name = 'zz-' . bin2hex( random_bytes( 6 ) ) . '.txt';
		file_put_contents( $dir . '/' . $name, 'private' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$abs = rtrim( wp_normalize_path( ABSPATH ), '/' );
		$url = home_url( str_replace( $abs, '', wp_normalize_path( $dir ) ) . '/' . $name );
		$r   = null;
		for ( $i = 0; $i < 8; $i++ ) { // a folder created just now may take a few seconds to be covered by its .htaccess
			$r = self::get( $url );
			if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
				break;
			}
			sleep( 1 );
		}
		Paths::rmdir( $dir );
		$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
		$leak = 200 === $code && false !== strpos( (string) wp_remote_retrieve_body( $r ), 'private' );
		if ( 0 === $code ) {
			return array( self::item( 'Is the private folder closed to the web?', 'warn', 'The check could not reach the site.', 'Run it again; if it keeps failing, see “The site calling itself”.', array( 'files' ) ) );
		}
		return array( self::item( 'Is the private folder closed to the web?', $leak ? 'fail' : 'pass', $leak ? 'A test file in the private folder was readable from the web.' : 'A test file in the private folder answered ' . $code . ' (not readable).', $leak ? 'Your server ignores .htaccess. Add a rule that denies access to wp-content/bizy-files (Nginx: location ~ ^/wp-content/bizy-files/ { deny all; return 404; }) or ask your host, then run the test again.' : '', array( 'files' ) ) );
	}

	private static function t_rules() {
		$r = Stealth_Rules::test();
		return array( self::item( 'Server rules (.htaccess)', $r['level'], $r['detail'], $r['fix'], array( 'rules' ) ) );
	}

	private static function t_loopback() {
		$r = self::get( home_url( '/' ) );
		if ( is_wp_error( $r ) ) {
			return array( self::item( 'The site can call itself', 'fail', $r->get_error_message(), 'Some hosts block a site from requesting its own address. Ask the host to allow loopback requests (needed for health checks and WordPress cron).', array( 'stealth', 'rules' ) ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		return array( self::item( 'The site can call itself', $code > 0 && $code < 500 ? 'pass' : 'fail', 'Answered ' . $code, 'The home address returned a server error.', array( 'stealth', 'rules' ) ) );
	}

	private static function t_plugins() {
		$active = array_map( function ( $p ) {
			return strtolower( dirname( $p ) );
		}, (array) get_option( 'active_plugins', array() ) );
		$has = function ( array $slugs ) use ( $active ) {
			return array_values( array_intersect( $slugs, $active ) );
		};
		$out  = array();
		$login = $has( array( 'wordfence', 'better-wp-security', 'ithemes-security-pro', 'solid-security', 'all-in-one-wp-security-and-firewall', 'wps-hide-login', 'two-factor', 'wp-2fa', 'miniorange-2-factor-authentication', 'google-authenticator', 'loginizer', 'limit-login-attempts-reloaded' ) );
		$out[] = $login ? self::item( 'Login / security plugins found: ' . implode( ', ', $login ), 'warn', 'These work on the WordPress sign-in page. Bizy’s own sign-in still runs WordPress’s checks (passwords, lockouts), but two-factor prompts and hide-login features will not appear on the app or portal pages.', 'Use one hiding method, not two. Test signing in on /app/ after enabling.', array( 'stealth' ) ) : self::item( 'Login / security plugins', 'pass', 'None found.' );
		$rest = $has( array( 'contact-form-7', 'fluentform', 'fluent-forms', 'woocommerce', 'jetpack', 'elementor', 'wpforms-lite', 'wpforms', 'gravityforms', 'newsletter', 'mailchimp-for-wp' ) );
		$out[] = $rest ? self::item( 'Plugins that may need the public API: ' . implode( ', ', $rest ), 'warn', 'Stealth hides the WordPress API from visitors who are not signed in. Forms and shop features on public pages call it.', 'Add the plugin’s API name to the allow-list on this screen (for example contact-form-7, fluentform, wc, wc-store). Public pages other than Bizy’s are hidden by stealth anyway.', array( 'stealth' ) ) : self::item( 'Plugins that need the public API', 'pass', 'None found.' );
		$cache = $has( array( 'litespeed-cache', 'wp-rocket', 'w3-total-cache', 'wp-super-cache', 'wp-fastest-cache', 'cache-enabler', 'sg-cachepress', 'breeze' ) );
		$out[] = $cache ? self::item( 'Page cache found: ' . implode( ', ', $cache ), 'info', in_array( 'litespeed-cache', $cache, true ) ? 'Bizy clears the LiteSpeed cache itself when you change these settings.' : 'Clear this plugin’s cache after changing these settings, or hidden pages may still be served from the cache.' ) : self::item( 'Page cache plugins', 'pass', 'None found.' );
		$dropins = array_intersect( array( 'object-cache.php', 'advanced-cache.php' ), array_map( 'basename', glob( WP_CONTENT_DIR . '/*.php' ) ?: array() ) );
		if ( $dropins ) {
			$out[] = self::item( 'Cache drop-ins: ' . implode( ', ', $dropins ), 'info', 'Normal. They can serve pages before Bizy runs, which is why the cache is cleared on every change.' );
		}
		return $out;
	}

	private static function t_cdn() {
		$r = self::get( home_url( '/' ) );
		if ( is_wp_error( $r ) ) {
			return array( self::item( 'CDN / proxy', 'info', 'Could not be checked.' ) );
		}
		$h    = wp_remote_retrieve_headers( $r );
		$cdn  = array();
		foreach ( array( 'cf-ray' => 'Cloudflare', 'x-sucuri-id' => 'Sucuri', 'x-cdn' => 'a CDN', 'x-amz-cf-id' => 'CloudFront', 'x-akamai-transformed' => 'Akamai', 'x-fastly-request-id' => 'Fastly' ) as $k => $n ) {
			if ( isset( $h[ $k ] ) ) {
				$cdn[] = $n;
			}
		}
		return array( $cdn ? self::item( 'A CDN or proxy is in front: ' . implode( ', ', $cdn ), 'warn', 'It may cache pages and hide the visitor’s real address (the wp-cron rule uses it).', 'Purge its cache after changing these settings. If it caches “200” answers for /wp-login.php or /wp-json/, add the same blocks at the CDN.', array( 'rules' ) ) : self::item( 'CDN or proxy', 'pass', 'None detected.' ) );
	}
}
