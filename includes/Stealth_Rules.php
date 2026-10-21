<?php
namespace WPFlatCore;

defined( 'ABSPATH' ) || exit;

/**
 * The server-level half of stealth mode: a marked block at the top of .htaccess (Apache / LiteSpeed) that hides static
 * WordPress files PHP never sees (readme.html, license.txt, anything under wp-includes and wp-content) from visitors who
 * do not hold the admin gate cookie. A copy of the original file is kept, the result is checked straight away, and the
 * file is put back automatically if the site stops answering.
 */
class Stealth_Rules {

	const BEGIN = '# BEGIN WPFlat Stealth';
	const END   = '# END WPFlat Stealth';

	private static function file() {
		return rtrim( wp_normalize_path( ABSPATH ), '/' ) . '/.htaccess';
	}

	public static function supported() {
		$sw = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) $_SERVER['SERVER_SOFTWARE'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return false !== strpos( $sw, 'apache' ) || false !== strpos( $sw, 'litespeed' ) || false !== strpos( $sw, 'lsws' );
	}

	public static function rules_text() {
		$gate = '%{HTTP_COOKIE} !(^|;\\s*)' . Stealth::COOKIE . '=';
		$sa   = isset( $_SERVER['SERVER_ADDR'] ) ? (string) $_SERVER['SERVER_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return implode( "\n", array(
			'Options -Indexes',
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'# Files that identify WordPress',
			'RewriteRule ^(readme\\.html|license\\.txt|wp-config-sample\\.php|xmlrpc\\.php|wp-links-opml\\.php|wp-trackback\\.php|wp-comments-post\\.php|wp-signup\\.php|wp-activate\\.php|wp-mail\\.php)$ - [R=404,L]',
			'# wp-cron.php answers before WordPress loads, so only the server can hide it from outsiders (the site itself may still call it)',
			'RewriteCond %{REMOTE_ADDR} !^127\\.0\\.0\\.1$',
			'RewriteCond %{REMOTE_ADDR} !^::1$',
			$sa ? 'RewriteCond %{REMOTE_ADDR} !^' . preg_quote( $sa, '/' ) . '$' : '# (server address unknown)',
			'RewriteRule ^wp-cron\\.php$ - [R=404,L]',
			'# WordPress folders: only for a browser that opened the secret admin address',
			'RewriteCond ' . $gate,
			'RewriteRule ^wp-includes/ - [R=404,L]',
			'RewriteCond ' . $gate,
			'RewriteRule ^wp-content/ - [R=404,L]',
			'RewriteCond ' . $gate,
			'RewriteCond %{REQUEST_URI} !^/wp-admin/(admin-ajax|admin-post)\\.php$',
			'RewriteRule ^wp-admin/ - [R=404,L]',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'Header always unset X-Powered-By',
			'Header always unset X-Pingback',
			'Header always unset Link',
			'</IfModule>',
			'<FilesMatch "^(\\.htaccess|\\.user\\.ini|php\\.ini|debug\\.log|error_log|wp-config\\.php)$">',
			'<IfModule mod_authz_core.c>',
			'Require all denied',
			'</IfModule>',
			'<IfModule !mod_authz_core.c>',
			'Order deny,allow',
			'Deny from all',
			'</IfModule>',
			'</FilesMatch>',
		) );
	}

	public static function status() {
		$f  = self::file();
		$on = is_readable( $f ) && false !== strpos( (string) file_get_contents( $f ), self::BEGIN ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array( 'supported' => self::supported(), 'writable' => is_file( $f ) ? wp_is_writable( $f ) : wp_is_writable( dirname( $f ) ), 'applied' => $on, 'backup' => is_file( $f . '.bd-backup' ), 'rules' => self::rules_text() );
	}

	private static function strip_block( $text ) {
		return trim( preg_replace( '#\n?' . preg_quote( self::BEGIN, '#' ) . '.*?' . preg_quote( self::END, '#' ) . '\n?#s', "\n", $text ) ) . "\n";
	}

	private static function write( $content ) {
		$f   = self::file();
		$tmp = $f . '.bd-tmp';
		if ( false === file_put_contents( $tmp, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false;
		}
		return rename( $tmp, $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/** Is the site still answering normally? */
	private static function healthy() {
		foreach ( array( home_url( '/' ), App_Page::url(), Assets::url( 'wpflatcore.css' ) ) as $u ) {
			$r = wp_remote_get( $u, array( 'timeout' => 10, 'redirection' => 3, 'sslverify' => false ) );
			if ( is_wp_error( $r ) || (int) wp_remote_retrieve_response_code( $r ) >= 400 ) {
				return false;
			}
		}
		return true;
	}

	/** Write the block (keeping a copy of the file first); undo it at once if the site stops answering. @return true|\WP_Error */
	public static function apply() {
		if ( ! self::supported() ) {
			return new \WP_Error( 'bd_rules', 'This server does not read .htaccess (it looks like Nginx). Use the rules shown on the screen in your server configuration.', array( 'status' => 400 ) );
		}
		$f   = self::file();
		$old = is_file( $f ) ? (string) file_get_contents( $f ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ( is_file( $f ) && ! wp_is_writable( $f ) ) || ( ! is_file( $f ) && ! wp_is_writable( dirname( $f ) ) ) ) {
			return new \WP_Error( 'bd_rules', 'The .htaccess file cannot be written.', array( 'status' => 500 ) );
		}
		if ( '' !== $old && ! is_file( $f . '.bd-backup' ) ) {
			copy( $f, $f . '.bd-backup' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$block = self::BEGIN . "\n" . self::rules_text() . "\n" . self::END . "\n\n";
		if ( ! self::write( $block . ( '' === $old ? '' : self::strip_block( $old ) ) ) ) {
			return new \WP_Error( 'bd_rules', 'The .htaccess file could not be updated.', array( 'status' => 500 ) );
		}
		if ( ! self::healthy() ) {
			self::write( $old );
			return new \WP_Error( 'bd_rules_fail', 'The site stopped answering with the new server rules, so they were removed again. Nothing was changed.', array( 'status' => 500 ) );
		}
		return true;
	}

	public static function remove() {
		$f = self::file();
		if ( ! is_readable( $f ) ) {
			return true;
		}
		$old = (string) file_get_contents( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === strpos( $old, self::BEGIN ) ) {
			return true;
		}
		return self::write( self::strip_block( $old ) );
	}

	/**
	 * Does .htaccess work here? Adds a harmless rule for a made-up address, checks that the server obeys it, removes it
	 * and checks it is gone, leaving the file exactly as it was. @return array level, detail, fix
	 */
	public static function test() {
		if ( ! self::supported() ) {
			return array( 'level' => 'fail', 'detail' => 'This server does not look like Apache or LiteSpeed.', 'fix' => 'On Nginx, copy the rules shown on this screen into your server configuration. Everything except the server rules still works.' );
		}
		$f = self::file();
		if ( ( is_file( $f ) && ! wp_is_writable( $f ) ) || ( ! is_file( $f ) && ! wp_is_writable( dirname( $f ) ) ) ) {
			return array( 'level' => 'fail', 'detail' => 'The .htaccess file cannot be written by the site.', 'fix' => 'Make .htaccess writable (permission 644 owned by the web user) or add the rules yourself.' );
		}
		$old   = is_file( $f ) ? (string) file_get_contents( $f ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$path  = 'bd-rule-test-' . bin2hex( random_bytes( 5 ) );
		$block = "# BEGIN WPFlat RuleTest\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^" . $path . "$ - [G,L]\n</IfModule>\n# END WPFlat RuleTest\n\n";
		if ( '' !== $old && ! is_file( $f . '.bd-backup' ) ) {
			copy( $f, $f . '.bd-backup' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$poll = function ( $want_410 ) use ( $path ) {
			$code = 0;
			for ( $i = 0; $i < 8; $i++ ) { // servers such as LiteSpeed notice a changed .htaccess after a few seconds
				$r    = wp_remote_get( home_url( '/' . $path ), array( 'timeout' => 10, 'redirection' => 0, 'sslverify' => false ) );
				$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
				if ( ( 410 === $code ) === $want_410 ) {
					break;
				}
				sleep( 1 );
			}
			return $code;
		};
		self::write( $block . $old );
		$code = $poll( true );
		self::write( $old );
		$after = $poll( false );
		$same  = ( is_file( $f ) ? (string) file_get_contents( $f ) : '' ) === $old; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $same ) {
			self::write( $old );
		}
		if ( 410 === $code && 410 !== $after ) {
			return array( 'level' => 'pass', 'detail' => 'The server obeys .htaccess rules, and the test rule was removed again.', 'fix' => '' );
		}
		if ( 410 === $code ) {
			return array( 'level' => 'warn', 'detail' => 'The server obeys .htaccess, but a removed rule still answered for a moment (a cache?).', 'fix' => 'Wait a minute and run the test again before using the server rules.' );
		}
		return array( 'level' => 'fail', 'detail' => 'The server ignored a test rule in .htaccess (it answered ' . ( $code ?: 'nothing' ) . ' instead of 410).', 'fix' => 'Your host does not read .htaccess here (Nginx, or AllowOverride is off). Skip the server rules, or add the rules shown on this screen to the server configuration.' );
	}

	/** Keep the file in step with the settings. @return true|\WP_Error */
	public static function sync( array $s ) {
		$want = ! empty( $s['rules'] ) && Stealth::active_now();
		return $want ? self::apply() : self::remove();
	}
}
