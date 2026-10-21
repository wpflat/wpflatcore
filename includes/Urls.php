<?php
namespace WPFlatCore;

defined( 'ABSPATH' ) || exit;

/** Addresses of WPFlat's own pages. Everything that builds a link to the app or the portal goes through here. */
class Urls {

	/** The team app, optionally at an in-app route: Urls::app( '/clients/3' ). */
	public static function app( $route = '' ) {
		$slug = sanitize_title( (string) Settings::get( 'app_slug' ) );
		$base = Settings::get( 'app_home' ) ? home_url( '/' ) : home_url( '/' . ( '' !== $slug ? $slug : 'app' ) . '/' );
		return $base . ( '' !== $route ? '#' . ( '/' === $route[0] ? $route : '/' . $route ) : '' );
	}

	/** A client-portal page: Urls::portal( 'billing/12' ). */
	public static function portal( $path = '', array $args = array() ) {
		return Portal::url( $path, $args );
	}

	/** Base address of Core's REST API for browser code (no trailing slash). The stealth layer can serve it under a neutral path. */
	public static function api() {
		return untrailingslashit( apply_filters( 'wpflatcore_api_base', rest_url( 'wpflatcore/v1' ) ) );
	}
}
