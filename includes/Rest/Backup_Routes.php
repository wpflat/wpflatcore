<?php
namespace BizyCore\Rest;

use BizyCore\Roles;

defined( 'ABSPATH' ) || exit;

/** Backup and restore routes. Namespace bizycore/v1. */
class Backup_Routes {

	const NS = 'bizycore/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	private static function reg( $route, $args ) {
		register_rest_route( self::NS, $route, $args );
	}

	public static function register() {
		$admin = array( Roles::class, 'can_admin' );
		self::reg( '/backups', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list' ), 'permission_callback' => $admin ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'start' ), 'permission_callback' => $admin ),
		) );
		self::reg( '/backups/step', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'step' ), 'permission_callback' => $admin ) );
		self::reg( '/backups/rescan', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rescan' ), 'permission_callback' => $admin ) );
		self::reg( '/backups/settings', array(
			array( 'methods' => 'GET', 'callback' => function () {
				return rest_ensure_response( \BizyCore\Backup\Backup_Manager::settings() );
			}, 'permission_callback' => $admin ),
			array( 'methods' => 'POST,PUT', 'callback' => function ( \WP_REST_Request $req ) {
				return rest_ensure_response( \BizyCore\Backup\Backup_Manager::save_settings( (array) $req->get_json_params() ) );
			}, 'permission_callback' => $admin ),
		) );
		self::reg( '/backups/fresh-dismiss', array( 'methods' => 'POST', 'callback' => function () {
			delete_option( 'bizycore_fresh_install' );
			return rest_ensure_response( array( 'dismissed' => true ) );
		}, 'permission_callback' => $admin ) );
		self::reg( '/backups/analyze', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'analyze' ), 'permission_callback' => $admin ) );
		self::reg( '/backups/restore', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'restore' ), 'permission_callback' => $admin ) );
		self::reg( '/backups/restore-step', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'restore_step' ), 'permission_callback' => $admin ) );
		self::reg( '/backups/restore-cancel', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'restore_cancel' ), 'permission_callback' => $admin ) );
		self::reg( '/backups/upload', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'upload' ), 'permission_callback' => $admin ) );
		self::reg( '/backups/(?P<id>[a-f0-9]{32})', array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete' ), 'permission_callback' => $admin ) );
		self::reg( '/backups/(?P<id>[a-f0-9]{32})/download-token', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'link' ), 'permission_callback' => $admin ) );
	}

	public static function list() {
		$m = \BizyCore\Backup\Backup_Manager::class;
		return rest_ensure_response( array( 'items' => $m::store()->all(), 'settings' => $m::settings(), 'health' => $m::health(), 'active' => $m::active() ) );
	}

	public static function start( \WP_REST_Request $req ) {
		$p = (array) $req->get_json_params();
		$r = \BizyCore\Backup\Backup_Manager::start( 'manual', array( 'files' => ! isset( $p['files'] ) || $p['files'], 'logs' => ! isset( $p['logs'] ) || $p['logs'], 'encrypt' => ! empty( $p['encrypt'] ) ) );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public static function step( \WP_REST_Request $req ) {
		$p = (array) $req->get_json_params();
		$r = \BizyCore\Backup\Backup_Manager::step( preg_replace( '/[^a-f0-9]/', '', (string) ( $p['job'] ?? '' ) ), (string) ( $p['password'] ?? '' ) );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public static function analyze( \WP_REST_Request $req ) {
		$p = (array) $req->get_json_params();
		$r = \BizyCore\Backup\Importer::analyze( (string) ( $p['id'] ?? '' ), (string) ( $p['password'] ?? '' ) );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public static function restore( \WP_REST_Request $req ) {
		$p = (array) $req->get_json_params();
		$r = \BizyCore\Backup\Importer::start( (string) ( $p['id'] ?? '' ), (string) ( $p['password'] ?? '' ), (array) ( $p['modules'] ?? array() ), (array) ( $p['opts'] ?? array() ) );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public static function restore_step( \WP_REST_Request $req ) {
		$p = (array) $req->get_json_params();
		$r = \BizyCore\Backup\Importer::step( (string) ( $p['job'] ?? '' ), (string) ( $p['password'] ?? '' ) );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public static function restore_cancel( \WP_REST_Request $req ) {
		$p = (array) $req->get_json_params();
		$r = \BizyCore\Backup\Importer::cancel( (string) ( $p['job'] ?? '' ) );
		return is_wp_error( $r ) ? $r : rest_ensure_response( array( 'cancelled' => true ) );
	}

	public static function upload( \WP_REST_Request $req ) {
		$files = $req->get_file_params();
		if ( empty( $files['file']['tmp_name'] ) ) {
			return new \WP_Error( 'bizycore_upload', 'No file piece was received.', array( 'status' => 400 ) );
		}
		$r = \BizyCore\Backup\Backup_Manager::receive_chunk( (string) $req->get_param( 'upload' ), (int) $req->get_param( 'index' ), (int) $req->get_param( 'total' ), $files['file']['tmp_name'] );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public static function rescan() {
		$n = \BizyCore\Backup\Backup_Manager::rescan();
		return rest_ensure_response( array( 'added' => $n ) );
	}

	public static function delete( \WP_REST_Request $req ) {
		\BizyCore\Backup\Backup_Manager::store()->delete( (string) $req['id'] );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	public static function link( \WP_REST_Request $req ) {
		$p = (array) $req->get_json_params();
		$r = \BizyCore\Backup\Backup_Download::make_link( (string) $req['id'], (string) ( $p['password'] ?? '' ) );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}
}
