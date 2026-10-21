<?php
namespace WPFlatCore;

defined( 'ABSPATH' ) || exit;

/**
 * Images and documents WPFlat shows or links (logos, receipts, attachments) live in wpflat-files/public and are
 * addressed as /bd/f/<name>, never under wp-content. They are still ordinary WordPress attachment ids in the database,
 * flagged with the `_bd_file` meta: that flag makes WordPress report the WPFlat path and address for them, so every
 * place that already stores an attachment id keeps working.
 */
class Media {

	const META = '_wpflat_file';

	/** Types the uploader accepts. */
	const ALLOWED = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt' );

	public static function init() {
		add_filter( 'get_attached_file', array( __CLASS__, 'file' ), 10, 2 );
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'filter_url' ), 10, 2 );
	}

	public static function dir() {
		return Paths::dir( 'public' );
	}

	public static function url( $name ) {
		return home_url( '/bd/f/' . str_replace( '%2F', '/', rawurlencode( $name ) ) );
	}

	public static function base_url() {
		return home_url( '/bd/f/' );
	}

	private static function flagged( $id ) {
		static $cache = array();
		$id = (int) $id;
		if ( ! array_key_exists( $id, $cache ) ) {
			$cache[ $id ] = $id > 0 ? (string) get_post_meta( $id, self::META, true ) : '';
		}
		return $cache[ $id ];
	}

	public static function file( $file, $id ) {
		$n = self::flagged( $id );
		return '' !== $n ? self::dir() . '/' . $n : $file;
	}

	public static function filter_url( $url, $id ) {
		$n = self::flagged( $id );
		return '' !== $n ? self::url( $n ) : $url;
	}

	/** Absolute path of a public file by the name used in its address ("abc-logo.svg"), or ''. */
	public static function path_of( $name ) {
		$name = ltrim( $name, '/' );
		if ( '' === $name || false !== strpos( $name, '..' ) || false !== strpos( $name, "\0" ) || preg_match( '#(^|/)\.#', $name ) ) {
			return '';
		}
		$p = self::dir() . '/' . $name;
		return is_file( $p ) ? $p : '';
	}

	/** A file that older document text points at under /wp-content/uploads/<rel>: the WPFlat copy if there is one. */
	public static function resolve_upload( $rel ) {
		$legacy = self::dir() . '/legacy/' . $rel;
		if ( is_file( $legacy ) ) {
			return $legacy;
		}
		$u = wp_upload_dir();
		$p = trailingslashit( $u['basedir'] ) . $rel;
		return is_file( $p ) ? $p : '';
	}

	/**
	 * Show-time only: pictures in older document text point at /wp-content/uploads/...; where WPFlat holds a copy,
	 * show that address instead. The stored text (and its sealed fingerprint) is never changed.
	 */
	public static function rewrite( $html ) {
		$base = trailingslashit( wp_upload_dir()['baseurl'] );
		if ( false === strpos( (string) $html, $base ) ) {
			return $html;
		}
		$prefix = preg_quote( $base, '#' );
		return preg_replace_callback( '#' . $prefix . '([A-Za-z0-9_\-./ %]+\.(?:png|jpe?g|gif|webp|svg|pdf))#i', function ( $m ) {
			$rel = rawurldecode( $m[1] );
			return ( false === strpos( $rel, '..' ) && is_file( self::dir() . '/legacy/' . $rel ) ) ? self::base_url() . 'legacy/' . $m[1] : $m[0];
		}, (string) $html );
	}

	/* ───────────────────────── Uploading ───────────────────────── */

	/** Store one uploaded file as a WPFlat attachment. @return array|\WP_Error id, url, name */
	public static function store( array $f ) {
		if ( UPLOAD_ERR_OK !== (int) $f['error'] || empty( $f['tmp_name'] ) ) {
			return new \WP_Error( 'wpflatcore_upload', 'The file could not be uploaded.', array( 'status' => 400 ) );
		}
		$name = sanitize_file_name( wp_unslash( (string) $f['name'] ) );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED, true ) ) {
			return new \WP_Error( 'wpflatcore_type', 'That file type is not allowed. Use an image, PDF, Office document, CSV or text file.', array( 'status' => 400 ) );
		}
		if ( (int) $f['size'] > 20 * MB_IN_BYTES || (int) $f['size'] <= 0 ) {
			return new \WP_Error( 'wpflatcore_size', 'Files can be up to 20 MB.', array( 'status' => 400 ) );
		}
		$mime = '';
		if ( function_exists( 'finfo_open' ) ) {
			$fi   = finfo_open( FILEINFO_MIME_TYPE );
			$mime = (string) finfo_file( $fi, $f['tmp_name'] );
			finfo_close( $fi );
		}
		if ( preg_match( '#(php|x-httpd|javascript|x-sh|x-msdownload|text/html|x-executable)#i', $mime ) ) {
			return new \WP_Error( 'wpflatcore_type', 'That file looks like a program or web page and cannot be uploaded.', array( 'status' => 400 ) );
		}
		if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) && 0 !== strpos( $mime, 'image/' ) ) {
			return new \WP_Error( 'wpflatcore_type', 'That is not a real image.', array( 'status' => 400 ) );
		}
		if ( 'svg' === $ext ) {
			$svg = (string) file_get_contents( $f['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( preg_match( '/<script|onload\s*=|onerror\s*=|javascript:|<foreignObject/i', $svg ) ) {
				return new \WP_Error( 'wpflatcore_type', 'That SVG contains scripts, so it was not accepted.', array( 'status' => 400 ) );
			}
			$mime = 'image/svg+xml';
		}
		$stored = bin2hex( random_bytes( 6 ) ) . '-' . $name;
		$dest   = self::dir() . '/' . $stored;
		if ( ! ( is_uploaded_file( $f['tmp_name'] ) ? move_uploaded_file( $f['tmp_name'], $dest ) : copy( $f['tmp_name'], $dest ) ) ) {
			return new \WP_Error( 'wpflatcore_io', 'The file could not be saved.', array( 'status' => 500 ) );
		}
		chmod( $dest, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$id = self::register( $stored, $name, $mime ? $mime : 'application/octet-stream' );
		if ( is_wp_error( $id ) ) {
			@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			return $id;
		}
		return array( 'id' => $id, 'url' => self::url( $stored ), 'name' => $name );
	}

	/** Create the attachment record for a file already sitting in wpflat-files/public. @return int|\WP_Error */
	public static function register( $stored, $title, $mime ) {
		$id = wp_insert_attachment( array( 'post_mime_type' => $mime, 'post_title' => preg_replace( '/\.[^.]+$/', '', $title ), 'post_status' => 'inherit', 'guid' => self::url( $stored ) ), false, 0, true );
		if ( is_wp_error( $id ) || ! $id ) {
			return is_wp_error( $id ) ? $id : new \WP_Error( 'wpflatcore_db', 'The file could not be registered.', array( 'status' => 500 ) );
		}
		update_post_meta( $id, '_wp_attached_file', $stored );
		update_post_meta( $id, self::META, $stored );
		return (int) $id;
	}
}
