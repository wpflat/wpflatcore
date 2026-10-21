<?php
namespace WPFlatCore\Rest;

use WPFlatCore\Links;
use WPFlatCore\Registry;
use WPFlatCore\Terminology;

defined( 'ABSPATH' ) || exit;

/** Core's own REST routes: modules, links and terminology. Namespace wpflatcore/v1. */
class Routes {

	const NS = 'wpflatcore/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * How a module adds its own routes (inside the `wpflatcore_register_routes` action): everything lives in
	 * wpflatcore/v1 so the neutral /bd/api address reaches it. The route disappears when the module is off, and a route
	 * that only asks for "can use the app" is narrowed to the module's capability.
	 */
	public static function add( $module, $route, array $args ) {
		if ( ! Registry::enabled( $module ) ) {
			return;
		}
		$cap  = Registry::module_cap( $module );
		$wrap = function ( $a ) use ( $cap ) {
			if ( $cap && isset( $a['permission_callback'] ) && array( Resource_Controller::class, 'can' ) === $a['permission_callback'] ) {
				$a['permission_callback'] = function () use ( $cap ) {
					return current_user_can( 'manage_options' ) || ( current_user_can( 'manage_wpflatcore' ) && current_user_can( $cap ) );
				};
			}
			return $a;
		};
		register_rest_route( self::NS, $route, isset( $args['methods'] ) ? $wrap( $args ) : array_map( $wrap, $args ) );
	}

	/** Anyone who may use the app. */
	public static function can_use() {
		return current_user_can( 'manage_options' ) || current_user_can( 'manage_wpflatcore' );
	}

	/** Settings, modules and renaming words. */
	public static function can_admin() {
		return current_user_can( 'manage_options' ) || current_user_can( 'wpflatcore_admin' );
	}

	public static function register() {
		do_action( 'wpflatcore_register_routes' );
		register_rest_route( self::NS, '/modules', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'modules' ), 'permission_callback' => array( __CLASS__, 'can_use' ) ),
		) );
		register_rest_route( self::NS, '/modules/(?P<id>[a-z0-9-]+)', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'toggle' ), 'permission_callback' => array( __CLASS__, 'can_admin' ) ),
		) );
		register_rest_route( self::NS, '/terminology', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'terms' ), 'permission_callback' => array( __CLASS__, 'can_use' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_terms' ), 'permission_callback' => array( __CLASS__, 'can_admin' ) ),
		) );
		register_rest_route( self::NS, '/links', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'links' ), 'permission_callback' => array( __CLASS__, 'can_use' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'add_link' ), 'permission_callback' => array( __CLASS__, 'can_use' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'remove_link' ), 'permission_callback' => array( __CLASS__, 'can_use' ) ),
		) );
		register_rest_route( self::NS, '/group-roles', array(
			array( 'methods' => 'GET', 'callback' => function () { return rest_ensure_response( \WPFlatCore\Services\Groups::assignable_roles() ); }, 'permission_callback' => array( __CLASS__, 'can_use' ) ),
		) );
		register_rest_route( self::NS, '/links/types', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'link_types' ), 'permission_callback' => array( __CLASS__, 'can_use' ) ),
		) );
		register_rest_route( self::NS, '/links/search', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'link_search' ), 'permission_callback' => array( __CLASS__, 'can_use' ) ),
		) );
	}

	public static function modules() {
		return rest_ensure_response( Registry::summary() );
	}

	public static function toggle( \WP_REST_Request $r ) {
		$res = Registry::set_enabled( (string) $r['id'], (bool) $r->get_param( 'enabled' ) );
		return is_wp_error( $res ) ? $res : rest_ensure_response( Registry::summary() );
	}

	public static function terms() {
		return rest_ensure_response( Terminology::export() );
	}

	public static function save_terms( \WP_REST_Request $r ) {
		$in = $r->get_param( 'words' );
		return rest_ensure_response( Terminology::save( is_array( $in ) ? $in : array() ) );
	}

	public static function link_types() {
		return rest_ensure_response( Links::available_types() );
	}

	public static function links( \WP_REST_Request $r ) {
		return rest_ensure_response( Links::for_record( sanitize_key( $r->get_param( 'type' ) ), (int) $r->get_param( 'id' ), sanitize_key( (string) $r->get_param( 'with' ) ) ) );
	}

	public static function add_link( \WP_REST_Request $r ) {
		$res = Links::add( sanitize_key( $r->get_param( 'from_type' ) ), (int) $r->get_param( 'from_id' ), sanitize_key( $r->get_param( 'to_type' ) ), (int) $r->get_param( 'to_id' ), (string) $r->get_param( 'kind' ) );
		return is_wp_error( $res ) ? $res : rest_ensure_response( array( 'linked' => true ) );
	}

	public static function remove_link( \WP_REST_Request $r ) {
		$n = Links::remove( sanitize_key( $r->get_param( 'from_type' ) ), (int) $r->get_param( 'from_id' ), sanitize_key( $r->get_param( 'to_type' ) ), (int) $r->get_param( 'to_id' ), (string) ( $r->get_param( 'kind' ) ? $r->get_param( 'kind' ) : 'related' ) );
		return rest_ensure_response( array( 'removed' => $n ) );
	}

	public static function link_search( \WP_REST_Request $r ) {
		return rest_ensure_response( Links::search( sanitize_key( $r->get_param( 'type' ) ), (string) $r->get_param( 'q' ), (int) $r->get_param( 'limit' ) ? (int) $r->get_param( 'limit' ) : 10 ) );
	}
}
