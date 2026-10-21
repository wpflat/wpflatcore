<?php
namespace WPFlatCore;

defined( 'ABSPATH' ) || exit;

/**
 * WPFlat's own public addresses, so nothing it serves needs /wp-content/ or /wp-json/:
 *
 *   /bd/a/<version>/<file>   the app (wpflatcore.js / .css) and fonts, straight from the plugin folder (allow-listed names)
 *   /bd/f/<name>             public files (logo, images on public pages) from wpflat-files/public
 *   /bd/a/<version>/m/<module>/<file>   a module's own app bundle (declared in its registry entry)
 *   /bd/m/<id>               a file shared in a client conversation (access-checked)
 *   /bd/api/<route>          the WPFlat REST API (namespace wpflatcore/v1 only), called with the cookie + nonce
 */
class Assets {

	const BASE = 'bd';

	private static $ver = '';

	public static function version() {
		if ( '' === self::$ver ) {
			$f         = WPFLATCORE_DIR . 'build/wpflatcore.js';
			self::$ver = is_readable( $f ) ? (string) filemtime( $f ) : (string) WPFLATCORE_VERSION;
		}
		return self::$ver;
	}

	/** Address of an app file: 'wpflatcore.js', 'wpflatcore.css' or 'fonts/<name>.woff2'. */
	public static function url( $file ) {
		return home_url( '/' . self::BASE . '/a/' . self::version() . '/' . $file );
	}

	public static function font_base() {
		return self::url( 'fonts' ) . '/';
	}

	/** Where the browser should send API calls. */
	public static function api_base( $unused = '' ) {
		return untrailingslashit( home_url( '/' . self::BASE . '/api' ) );
	}

	/** Address of a module's bundle file, e.g. module_url( 'tasks', 'wpflattasks.js' ). */
	public static function module_url( $module, $file ) {
		// The address carries the bundle's own change time, so a module update is never hidden by a browser's cached copy.
		$ver = self::version();
		$m   = Registry::get( $module );
		if ( $m && ! empty( $m['assets_dir'] ) && is_readable( $m['assets_dir'] . '/' . $file ) ) {
			$ver = (string) filemtime( $m['assets_dir'] . '/' . $file );
		}
		return home_url( '/' . self::BASE . '/a/' . $ver . '/m/' . $module . '/' . $file );
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 0 );
		add_filter( 'wpflatcore_api_base', array( __CLASS__, 'api_base' ) );
		add_filter( 'wpflatcore_message_file_url', function ( $url, $id ) {
			return home_url( '/' . self::BASE . '/m/' . (int) $id );
		}, 10, 2 );
	}

	private static function path() {
		$p    = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return ltrim( substr( (string) $p, strlen( $home ) ), '/' );
	}

	public static function maybe_serve() {
		$path = self::path();
		if ( 0 !== strpos( $path, self::BASE . '/' ) ) {
			return;
		}
		$rest = substr( $path, strlen( self::BASE ) + 1 );
		if ( 0 === strpos( $rest, 'a/' ) ) {
			self::serve_asset( substr( $rest, 2 ) );
		} elseif ( 0 === strpos( $rest, 'm/' ) ) {
			Services\Messages::serve( absint( substr( $rest, 2 ) ) );
		} elseif ( 0 === strpos( $rest, 'f/' ) ) {
			self::serve_public( rawurldecode( substr( $rest, 2 ) ) );
		} elseif ( 0 === strpos( $rest, 'api/' ) || 'api' === $rest ) {
			self::gateway( '/' . ltrim( (string) substr( $rest, 3 ), '/' ) );
		}
	}

	private static function mime( $name ) {
		$m = array( 'js' => 'application/javascript; charset=UTF-8', 'css' => 'text/css; charset=UTF-8', 'woff2' => 'font/woff2', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=UTF-8', 'csv' => 'text/csv; charset=UTF-8', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		return $m[ strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ] ?? 'application/octet-stream';
	}

	private static function not_found() {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo 'Not Found';
		exit;
	}

	private static function send_file( $path, $mime, $cache, $disposition = 'inline' ) {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: ' . $cache );
		if ( 'inline' !== $disposition ) {
			header( 'Content-Disposition: ' . $disposition );
		}
		if ( 'image/svg+xml' === $mime || 'application/pdf' === $mime || 0 === strpos( $mime, 'text/' ) ) {
			header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox" );
		}
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	private static function serve_asset( $rel ) {
		$rel = preg_replace( '#^[0-9a-zA-Z._-]+/#', '', $rel, 1 ); // drop the version segment
		$map = array( 'wpflatcore.js' => WPFLATCORE_DIR . 'build/wpflatcore.js', 'wpflatcore.css' => WPFLATCORE_DIR . 'build/wpflatcore.css' );
		$p   = '';
		if ( preg_match( '#^m/([a-z0-9-]+)/([A-Za-z0-9._-]+)$#', $rel, $mm ) ) {
			$def = Registry::get( $mm[1] );
			if ( $def && ! empty( $def['assets_dir'] ) && in_array( $mm[2], array_values( (array) $def['assets'] ), true ) ) {
				$p = rtrim( $def['assets_dir'], '/' ) . '/' . $mm[2];
			}
		} elseif ( isset( $map[ $rel ] ) ) {
			$p = $map[ $rel ];
		} elseif ( preg_match( '#^fonts/([A-Za-z0-9_-]+\.woff2)$#', $rel, $m ) ) {
			$p = WPFLATCORE_DIR . 'assets/fonts/' . $m[1];
		}
		if ( '' === $p || ! is_readable( $p ) ) {
			self::not_found();
		}
		self::send_file( $p, self::mime( $p ), 'public, max-age=31536000, immutable' );
	}

	private static function serve_public( $name ) {
		$p = Media::path_of( $name );
		if ( '' === $p ) {
			self::not_found();
		}
		self::send_file( $p, self::mime( $p ), 'public, max-age=86400' );
	}

	/**
	 * Run a WPFlat REST call under /bd/api without exposing /wp-json. Same security as the REST API: the signed-in
	 * cookie identifies the person, and anything that changes data must carry the nonce.
	 */
	private static function gateway( $route ) {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		$send = function ( $status, $data ) {
			status_header( $status );
			header( 'Content-Type: application/json; charset=UTF-8' );
			echo wp_json_encode( $data ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		};
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_user_logged_in() && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				wp_set_current_user( 0 ); // as WordPress does: no valid nonce means the cookie does not count
			}
		}
		$req = new \WP_REST_Request( $method, '/wpflatcore/v1' . $route );
		$req->set_query_params( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$ct = isset( $_SERVER['CONTENT_TYPE'] ) ? strtolower( (string) $_SERVER['CONTENT_TYPE'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 0 === strpos( $ct, 'multipart/form-data' ) ) {
			$req->set_body_params( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$req->set_file_params( $_FILES ); // phpcs:ignore WordPress.Security.NonceVerification
		} else {
			$raw = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$req->set_body( $raw );
			$req->set_header( 'content-type', $ct ? $ct : 'application/json' );
			if ( 0 === strpos( $ct, 'application/x-www-form-urlencoded' ) ) {
				$req->set_body_params( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		}
		$server = rest_get_server();
		$res    = $server->dispatch( $req );
		$res    = rest_ensure_response( $res );
		$status = $res->get_status();
		$data   = $server->response_to_data( $res, false );
		foreach ( $res->get_headers() as $k => $v ) {
			if ( in_array( strtolower( $k ), array( 'x-wp-total', 'x-wp-totalpages' ), true ) ) {
				header( $k . ': ' . $v );
			}
		}
		$send( $status, $data );
	}
}
