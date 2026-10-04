<?php
namespace BizyCore\Rest;

use BizyCore\Migration\Legacy;
use BizyCore\Roles;

defined( 'ABSPATH' ) || exit;

/** Moving from the old single BizyDash plugin: choose a backup, import, then let each module take its part. */
class Migration_Routes {

	const NS = 'bizycore/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	private static function done( $r ) {
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public static function register() {
		$perm = array( Roles::class, 'can_admin' );
		register_rest_route( self::NS, '/migration', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'status' ), 'permission_callback' => $perm ),
			array( 'methods' => 'DELETE', 'callback' => function () {
				return rest_ensure_response( array( 'discarded' => Legacy::discard() ) );
			}, 'permission_callback' => $perm ),
		) );
		register_rest_route( self::NS, '/migration/adopt', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'adopt' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/migration/step', array( 'methods' => 'POST', 'callback' => function ( \WP_REST_Request $r ) {
			@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return self::done( Legacy::step( (bool) $r->get_param( 'replace' ) ) );
		}, 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/migration/module', array( 'methods' => 'POST', 'callback' => function ( \WP_REST_Request $r ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return self::done( Legacy::run_module( sanitize_key( (string) $r->get_param( 'module' ) ), (bool) $r->get_param( 'again' ) ) );
		}, 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/migration/users', array( 'methods' => 'POST', 'callback' => function () {
			return rest_ensure_response( Legacy::migrate_users() );
		}, 'permission_callback' => $perm ) );
	}

	public static function status() {
		return rest_ensure_response( array(
			'adopted'    => Legacy::adopted(),
			'candidates' => Legacy::candidates(),
			'analysis'   => Legacy::adopted() ? Legacy::analysis() : null,
		) );
	}

	/** Choose a backup found on this site (by path) or one uploaded from the person's computer. */
	public static function adopt( \WP_REST_Request $r ) {
		$pw   = (string) $r->get_param( 'password' );
		$file = $r->get_file_params();
		if ( ! empty( $file['file']['tmp_name'] ) && UPLOAD_ERR_OK === (int) $file['file']['error'] ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return self::done( Legacy::adopt( $file['file']['tmp_name'], $pw, true ) );
		}
		return self::done( Legacy::adopt( (string) $r->get_param( 'file' ), $pw ) );
	}
}
