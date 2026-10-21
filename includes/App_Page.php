<?php
namespace WPFlatCore;

defined( 'ABSPATH' ) || exit;

/**
 * The WPFlat app on the front end: https://site/app/ (the word is configurable).
 * A theme-less page with no WordPress admin around it — the same app, drawing its own navigation.
 */
class App_Page {

	public static function slug() {
		$s = sanitize_title( (string) Settings::get( 'app_slug' ) );
		return '' !== $s ? $s : 'app';
	}

	/** The app's address: the site's home page when chosen in Settings, otherwise /app/. */
	public static function url() {
		return Settings::get( 'app_home' ) ? home_url( '/' ) : home_url( '/' . self::slug() . '/' );
	}

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
		App_Login::init();
	}

	private static function requested() {
		$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return trim( substr( (string) $path, strlen( $home ) ), '/' );
	}

	/** Parameters other WPFlat pages use on the home URL (payslips, certificates…) — never hijack those. */
	private static function foreign_request() {
		$own = array( 'bd_error', 'bd_msg', 'bd_view', 'bd_sent', 'key', 'login' );
		foreach ( array_keys( $_GET ) as $k ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( 0 === strpos( (string) $k, 'bd_' ) && ! in_array( $k, $own, true ) ) {
				return true; // includes ?bd_site=1, the way back to the normal home page
			}
		}
		return false;
	}

	/** Is this request for the app — /app/, or the home page when the app is the home page? */
	private static function is_app_request() {
		if ( ! Settings::get( 'app_frontend' ) ) {
			return false;
		}
		$path = self::requested();
		if ( $path === self::slug() ) {
			return true;
		}
		if ( Settings::get( 'app_home' ) && '' === $path ) {
			global $wp;
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( ! in_array( $method, array( 'GET', 'HEAD', 'POST' ), true ) || ! empty( $wp->query_vars ) || self::foreign_request() ) {
				return false;
			}
			// A signed-in person who is not on the team (a customer, say) still gets the ordinary home page.
			return ! is_user_logged_in() || current_user_can( 'manage_wpflatcore' );
		}
		return false;
	}

	public static function maybe_render() {
		if ( ! self::is_app_request() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'wpflatcore app' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );

		if ( ! is_user_logged_in() ) {
			if ( Settings::get( 'app_login' ) ) {
				App_Login::route(); // our own branded sign-in and password reset
				exit;
			}
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}
		if ( ! current_user_can( 'manage_wpflatcore' ) ) {
			wp_die( 'You do not have access to WPFlat.', 'Not allowed', array( 'response' => 403 ) );
		}

		status_header( 200 );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Content-Type-Options: nosniff' );
		// A page of our own: no theme, no WordPress head, and every address is a WPFlat one.
		$boot = wp_json_encode( Shell::boot_data( '/', true ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		?>
<!doctype html>
<html <?php language_attributes(); ?> class="bd-frontend">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>WPFlat — <?php echo esc_html( Settings::get( 'business_name' ) ); ?></title>
<link rel="preload" href="<?php echo esc_url( Assets::font_base() . 'google-sans-latin.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<style><?php echo Shell::font_face_css(); // phpcs:ignore WordPress.Security.EscapeOutput ?></style>
<link rel="stylesheet" href="<?php echo esc_url( Assets::url( 'wpflatcore.css' ) ); ?>">
<?php foreach ( Registry::assets() as $mod_files ) : if ( ! empty( $mod_files['style'] ) ) : ?>
<link rel="stylesheet" href="<?php echo esc_url( $mod_files['style'] ); ?>">
<?php endif; endforeach; ?>
</head>
<body class="wpflatcore-screen wpflatcore-frontend wpflatcore-fullscreen">
<div id="wpflatcore-root" class="wpflatcore-root"><p style="padding:24px;font-family:sans-serif">Loading…</p></div>
<script>window.wpflatcoreBoot=<?php echo $boot; // phpcs:ignore WordPress.Security.EscapeOutput ?>;</script>
<script src="<?php echo esc_url( Assets::url( 'wpflatcore.js' ) ); ?>"></script>
<?php foreach ( Registry::assets() as $mod_files ) : if ( ! empty( $mod_files['script'] ) ) : ?>
<script src="<?php echo esc_url( $mod_files['script'] ); ?>"></script>
<?php endif; endforeach; ?>
</body>
</html>
		<?php
		exit;
	}
}
