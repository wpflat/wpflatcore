<?php
namespace WPFlatCore;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → The app → "Site home page": send visitors who open the site's home page straight to one module's own page
 * (for example WPFlat Files' /files/), so a site that installed WPFlat for a single module never shows the rest.
 * The site's normal home page stays reachable at ?bd_site=1, and the app at its own address.
 */
class Home_Module {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 0 );
	}

	/** The module id the home page points at, or '' (off, or the module is gone / off / has no front page now). */
	public static function module() {
		$id = (string) Settings::get( 'home_module' );
		return ( '' !== $id && '' !== Registry::front_url( $id ) ) ? $id : '';
	}

	public static function maybe_redirect() {
		$id = self::module();
		if ( '' === $id || is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_customize_preview() ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== trim( substr( (string) $path, strlen( $home ) ), '/' ) ) {
			return; // only the home page itself
		}
		foreach ( array_keys( $_GET ) as $k ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( 0 === strpos( (string) $k, 'bd_' ) || in_array( $k, array( 'key', 'login', 'action', 'p', 'page_id', 's', 'preview' ), true ) ) {
				return; // other WPFlat pages, the way back (?bd_site=1), password-reset links and previews are left alone
			}
		}
		do_action( 'litespeed_control_set_nocache', 'wpflat home module' );
		nocache_headers();
		wp_safe_redirect( Registry::front_url( $id ), 302 );
		exit;
	}
}
