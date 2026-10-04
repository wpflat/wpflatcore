<?php
namespace BizyCore;

defined( 'ABSPATH' ) || exit;

/**
 * Where the Bizy suite keeps its files: wp-content/bizy-files, closed to direct web access and only ever read through
 * Bizy after checking who is asking. Layout:
 *   bizy-files/private/<kind>   one folder per kind a plugin asks for: messages, backups, tmp, docs…
 *   bizy-files/public           logos and images that appear on public pages (served by a Bizy address)
 *
 * It lives inside wp-content so WordPress core updates and managed-host refreshes leave it alone, and so hosts that
 * only allow writing there still work. `BIZYCORE_FILES_DIR` (wp-config.php) or the Security settings can point it elsewhere.
 */
class Paths {

	const OPTION = 'bizycore_files_state';
	const DENY   = "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";

	private static $state = null;

	public static function default_root() {
		return rtrim( wp_normalize_path( WP_CONTENT_DIR ), '/' ) . '/bizy-files';
	}

	public static function state() {
		if ( null === self::$state ) {
			$s = get_option( self::OPTION );
			if ( ! is_array( $s ) || empty( $s['root'] ) ) {
				$s = array( 'root' => self::default_root() );
				update_option( self::OPTION, $s, false );
			}
			self::$state = $s;
		}
		return self::$state;
	}

	public static function root() {
		if ( defined( 'BIZYCORE_FILES_DIR' ) && BIZYCORE_FILES_DIR ) {
			return rtrim( wp_normalize_path( BIZYCORE_FILES_DIR ), '/' );
		}
		return rtrim( wp_normalize_path( (string) self::state()['root'] ), '/' );
	}

	public static function outside_webroot() {
		$abs = rtrim( wp_normalize_path( ABSPATH ), '/' ) . '/';
		return 0 !== strpos( self::root() . '/', $abs );
	}

	/** Folder for one kind of file ('public' is the only public one). Created and protected on first use. */
	public static function dir( $kind ) {
		$kind = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $kind ) );
		if ( '' === $kind ) {
			$kind = 'tmp';
		}
		$d = self::root() . ( 'public' === $kind ? '/public' : '/private/' . $kind );
		self::ensure( self::root() );          // the whole folder is closed to direct access
		self::ensure( $d, 'public' !== $kind ); // and re-checked every time, so a deleted .htaccess comes back
		return $d;
	}

	/** Create a folder and close it: a deny-all .htaccess (not for "public", whose parent is closed anyway) and an empty index.html. */
	public static function ensure( $dir, $deny = true ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		if ( $deny && ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', self::DENY ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		}
		if ( ! file_exists( $dir . '/index.html' ) ) {
			@file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		}
	}

	/** Delete a folder and everything in it (used for scratch folders). */
	public static function rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			is_dir( $dir . '/' . $f ) ? self::rmdir( $dir . '/' . $f ) : @unlink( $dir . '/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
	}

	/** Where the files could live: inside wp-content (recommended) or beside WordPress (advanced; hosts may remove it on updates). */
	public static function targets() {
		$outside = dirname( rtrim( wp_normalize_path( ABSPATH ), '/' ) ) . '/bizy-files';
		return array(
			'content' => array( 'root' => self::default_root(), 'writable' => wp_is_writable( WP_CONTENT_DIR ) || is_dir( self::default_root() ) ),
			'outside' => array( 'root' => $outside, 'writable' => wp_is_writable( dirname( $outside ) ) ),
		);
	}

	public static function status() {
		return array( 'root' => self::root(), 'outside_webroot' => self::outside_webroot(), 'fixed_by_constant' => defined( 'BIZYCORE_FILES_DIR' ) && BIZYCORE_FILES_DIR, 'targets' => self::targets() );
	}
}
