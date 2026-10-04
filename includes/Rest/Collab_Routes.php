<?php
namespace BizyCore\Rest;

use BizyCore\Database\Schema;
use BizyCore\Portal;
use BizyCore\Roles;
use BizyCore\Services\Departments;
use BizyCore\Services\Home;
use BizyCore\Services\Messages;
use BizyCore\Services\Search;
use BizyCore\Services\Notifications;
use BizyCore\Services\Portal_Users;

defined( 'ABSPATH' ) || exit;

/** Core's people-facing routes: messages, notifications, client portal logins and access (roles). Namespace bizycore/v1. */
class Collab_Routes {

	const NS = 'bizycore/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	private static function reg( $route, $args ) {
		register_rest_route( self::NS, $route, $args );
	}

	private static function done( $r ) {
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public static function register() {
		$admin = array( Roles::class, 'can_admin' );
		$msg   = array( Messages::class, 'can_use' );
		$ntf   = array( Notifications::class, 'can_use' );
		$clients = function () {
			return current_user_can( 'bizycore_clients' ) || current_user_can( 'manage_options' );
		};
		$team = function () {
			return current_user_can( 'bizycore_team' ) || current_user_can( 'manage_options' );
		};

		/* Messages */
		self::reg( '/messages/threads', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'threads' ), 'permission_callback' => $msg ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'new_thread' ), 'permission_callback' => $msg ),
		) );
		self::reg( '/messages/threads/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'thread' ), 'permission_callback' => $msg ) );
		self::reg( '/messages/threads/(?P<id>\d+)/messages', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post' ), 'permission_callback' => $msg ) );
		self::reg( '/messages/threads/(?P<id>\d+)/read', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'read' ), 'permission_callback' => $msg ) );
		self::reg( '/messages/threads/(?P<id>\d+)/typing', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'typing' ), 'permission_callback' => $msg ) );
		self::reg( '/messages/threads/(?P<id>\d+)/status', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'status' ), 'permission_callback' => $msg ) );
		self::reg( '/messages/messages/(?P<id>\d+)', array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete' ), 'permission_callback' => $msg ) );
		self::reg( '/messages/unread', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'unread' ), 'permission_callback' => $msg ) );
		self::reg( '/messages/contexts', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'contexts' ), 'permission_callback' => $msg ) );

		/* Notifications */
		self::reg( '/notifications', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'notifications' ), 'permission_callback' => $ntf ) );
		self::reg( '/notifications/read', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'notifications_read' ), 'permission_callback' => $ntf ) );
		self::reg( '/notifications/summary', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'summary' ), 'permission_callback' => $ntf ) );
		self::reg( '/notifications/prefs', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'prefs' ), 'permission_callback' => $ntf ),
			array( 'methods' => 'POST,PUT', 'callback' => array( __CLASS__, 'save_prefs' ), 'permission_callback' => $ntf ),
		) );
		self::reg( '/notifications/catalog', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'catalog' ), 'permission_callback' => $admin ) );

		self::reg( '/home', array( 'methods' => 'GET', 'callback' => function () {
			return rest_ensure_response( Home::data() );
		}, 'permission_callback' => array( Resource_Controller::class, 'can' ) ) );

		self::reg( '/search', array( 'methods' => 'GET', 'callback' => function ( \WP_REST_Request $r ) {
			return rest_ensure_response( Search::run( (string) $r->get_param( 'q' ) ) );
		}, 'permission_callback' => array( Resource_Controller::class, 'can' ) ) );

		/* Department pages */
		self::reg( '/departments/overview', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'dept_all' ), 'permission_callback' => array( Resource_Controller::class, 'can' ) ) );
		self::reg( '/departments/(?P<id>\d+)/overview', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'dept_one' ), 'permission_callback' => array( Resource_Controller::class, 'can' ) ) );

		/* Access and client logins */
		self::reg( '/access', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'access' ), 'permission_callback' => $admin ) );
		self::reg( '/access/sync-clients', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'sync_clients' ), 'permission_callback' => $admin ) );
		self::reg( '/team/(?P<id>\d+)/access', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'team_access_get' ), 'permission_callback' => $team ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'team_access_set' ), 'permission_callback' => $team ),
		) );
		self::reg( '/clients/(?P<id>\d+)/logins', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'client_logins' ), 'permission_callback' => array( Resource_Controller::class, 'can' ) ) );
		self::reg( '/clients/(?P<id>\d+)/portal-invite', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'client_invite' ), 'permission_callback' => $clients ) );
	}

	/* ---- messages ---- */

	public static function threads( \WP_REST_Request $req ) {
		$a = Messages::actor();
		return rest_ensure_response( array(
			'threads' => Messages::list_threads( $a, array( 'client_id' => (int) $req->get_param( 'client_id' ), 'context_type' => sanitize_key( (string) $req->get_param( 'context_type' ) ), 'context_id' => (int) $req->get_param( 'context_id' ), 'q' => sanitize_text_field( (string) $req->get_param( 'q' ) ), 'status' => (string) ( $req->get_param( 'status' ) ? $req->get_param( 'status' ) : 'open' ), 'unread' => (bool) $req->get_param( 'unread' ) ) ),
			'unread'  => Messages::unread_total( $a ),
		) );
	}

	public static function thread( \WP_REST_Request $req ) {
		return self::done( Messages::get_thread( Messages::actor(), (int) $req['id'], (int) $req->get_param( 'after' ) ) );
	}

	public static function new_thread( \WP_REST_Request $req ) {
		$files = Messages::normalize_files( isset( $req->get_file_params()['files'] ) ? $req->get_file_params()['files'] : array() );
		return self::done( Messages::create_thread( Messages::actor(), (int) $req->get_param( 'client_id' ), (string) $req->get_param( 'context' ), (string) $req->get_param( 'subject' ), (string) $req->get_param( 'body' ), $files ) );
	}

	public static function post( \WP_REST_Request $req ) {
		$files = Messages::normalize_files( isset( $req->get_file_params()['files'] ) ? $req->get_file_params()['files'] : array() );
		return self::done( Messages::post_message( Messages::actor(), (int) $req['id'], (string) $req->get_param( 'body' ), $files ) );
	}

	public static function read( \WP_REST_Request $req ) {
		Messages::mark_read( Messages::actor(), (int) $req['id'] );
		return rest_ensure_response( array( 'unread' => Messages::unread_total( Messages::actor() ) ) );
	}

	public static function typing( \WP_REST_Request $req ) {
		Messages::typing( Messages::actor(), (int) $req['id'] );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function status( \WP_REST_Request $req ) {
		$p = (array) $req->get_json_params();
		return self::done( Messages::set_status( Messages::actor(), (int) $req['id'], (string) ( isset( $p['status'] ) ? $p['status'] : '' ) ) );
	}

	public static function delete( \WP_REST_Request $req ) {
		return self::done( Messages::delete_message( Messages::actor(), (int) $req['id'] ) );
	}

	public static function unread() {
		return rest_ensure_response( array( 'unread' => Messages::unread_total( Messages::actor() ) ) );
	}

	public static function contexts( \WP_REST_Request $req ) {
		return rest_ensure_response( Messages::contexts_for( Messages::actor(), (int) $req->get_param( 'client_id' ) ) );
	}

	/* ---- notifications ---- */

	public static function notifications( \WP_REST_Request $req ) {
		$uid = get_current_user_id();
		return rest_ensure_response( array( 'items' => Notifications::list_for( $uid, (string) ( $req->get_param( 'filter' ) ? $req->get_param( 'filter' ) : 'all' ), min( 100, max( 1, (int) ( $req->get_param( 'limit' ) ? $req->get_param( 'limit' ) : 30 ) ) ), (int) $req->get_param( 'before' ) ), 'unread' => Notifications::unread_count( $uid ) ) );
	}

	public static function notifications_read( \WP_REST_Request $req ) {
		$p = (array) $req->get_json_params();
		return rest_ensure_response( array( 'unread' => Notifications::mark_read( get_current_user_id(), ! empty( $p['all'] ) ? null : array_map( 'intval', (array) ( isset( $p['ids'] ) ? $p['ids'] : array() ) ) ) ) );
	}

	public static function summary() {
		$a = Messages::actor();
		return rest_ensure_response( array( 'notifications' => Notifications::unread_count( get_current_user_id() ), 'messages' => $a ? Messages::unread_total( $a ) : 0 ) );
	}

	public static function prefs() {
		return rest_ensure_response( array( 'prefs' => Notifications::prefs_for( get_current_user_id() ) ) );
	}

	public static function save_prefs( \WP_REST_Request $req ) {
		$p = (array) $req->get_json_params();
		Notifications::save_prefs( get_current_user_id(), (array) ( isset( $p['prefs'] ) ? $p['prefs'] : array() ) );
		return self::prefs();
	}

	public static function catalog() {
		$out = array();
		foreach ( Notifications::catalog() as $k => $c ) {
			$out[] = array( 'type' => $k, 'audience' => $c[1], 'group' => $c[2], 'label' => $c[3], 'default_email' => $c[4], 'external_email' => (bool) $c[5], 'enabled' => Notifications::enabled_type( $k ) );
		}
		return rest_ensure_response( $out );
	}

	/* ---- departments ---- */

	public static function dept_all( \WP_REST_Request $req ) {
		return rest_ensure_response( Departments::all( (string) $req->get_param( 'from' ), (string) $req->get_param( 'to' ) ) );
	}

	public static function dept_one( \WP_REST_Request $req ) {
		$d = Departments::one( (int) $req['id'], (string) $req->get_param( 'from' ), (string) $req->get_param( 'to' ) );
		return $d ? rest_ensure_response( $d ) : new \WP_Error( 'bizycore_not_found', 'Not found.', array( 'status' => 404 ) );
	}

	/* ---- access ---- */

	private static function role_options() {
		$out = array();
		foreach ( Roles::roles() as $k => $r ) {
			if ( ! empty( $r['internal'] ) ) {
				$out[] = array( 'key' => $k, 'label' => $r['label'], 'desc' => $r['desc'] );
			}
		}
		return $out;
	}

	private static function grant_labels() {
		return array_map( function ( $m ) {
			return $m['label'];
		}, Roles::manager_modules() );
	}

	public static function access() {
		$roles = array();
		foreach ( Roles::roles() as $k => $r ) {
			$caps    = array_keys( array_filter( $r['caps'] ) );
			$roles[] = array( 'key' => $k, 'label' => $r['label'], 'desc' => $r['desc'], 'caps' => array_values( array_diff( $caps, array( 'read', 'upload_files' ) ) ), 'users' => count( get_users( array( 'role' => $k, 'fields' => 'ID' ) ) ) );
		}
		return rest_ensure_response( array( 'roles' => $roles, 'auto' => Portal_Users::enabled(), 'stats' => Portal_Users::stats(), 'manager_modules' => self::grant_labels() ) );
	}

	public static function sync_clients( \WP_REST_Request $req ) {
		$p = (array) $req->get_json_params();
		return rest_ensure_response( Portal_Users::sync_all( (int) ( isset( $p['after'] ) ? $p['after'] : 0 ), 100 ) );
	}

	private static function member( $team_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, email, wp_user_id FROM ' . Schema::table( 'bizy_team_members' ) . ' WHERE id=%d', $team_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function team_access_get( \WP_REST_Request $req ) {
		$m = self::member( (int) $req['id'] );
		if ( ! $m ) {
			return new \WP_Error( 'bizycore_missing', 'Team member not found.', array( 'status' => 404 ) );
		}
		$uid                 = (int) $m['wp_user_id'];
		list( $role, $mods ) = $uid ? Roles::role_of( $uid ) : array( '', array() );
		$u                   = $uid ? get_userdata( $uid ) : null;
		return rest_ensure_response( array( 'has_login' => (bool) $u, 'login' => $u ? $u->user_login : '', 'is_wp_admin' => $u ? user_can( $u, 'manage_options' ) : false, 'role' => $role, 'modules' => $mods, 'roles' => self::role_options(), 'manager_modules' => self::grant_labels() ) );
	}

	public static function team_access_set( \WP_REST_Request $req ) {
		global $wpdb;
		$m = self::member( (int) $req['id'] );
		if ( ! $m ) {
			return new \WP_Error( 'bizycore_missing', 'Team member not found.', array( 'status' => 404 ) );
		}
		$p    = (array) $req->get_json_params();
		$role = (string) ( isset( $p['role'] ) ? $p['role'] : '' );
		$uid  = (int) $m['wp_user_id'];
		if ( ! $uid || ! get_userdata( $uid ) ) {
			if ( '' === $role ) {
				return rest_ensure_response( array( 'ok' => true ) );
			}
			$email = sanitize_email( $m['email'] );
			if ( ! $email ) {
				return new \WP_Error( 'bizycore_email', 'Add an email address to this person first.', array( 'status' => 400 ) );
			}
			$ex = get_user_by( 'email', $email );
			if ( $ex ) {
				$uid = (int) $ex->ID;
			} else {
				$uid = wp_insert_user( array( 'user_login' => sanitize_user( strstr( $email, '@', true ) . wp_rand( 10, 99 ), true ), 'user_email' => $email, 'user_pass' => wp_generate_password( 32, true, true ), 'display_name' => $m['name'], 'role' => '' ) );
				if ( is_wp_error( $uid ) ) {
					return $uid;
				}
				update_user_meta( $uid, 'show_admin_bar_front', 'false' );
				if ( ! empty( $p['notify'] ) ) {
					wp_new_user_notification( $uid, null, 'user' );
				}
			}
			$wpdb->update( Schema::table( 'bizy_team_members' ), array( 'wp_user_id' => $uid ), array( 'id' => (int) $m['id'] ) );
		}
		$r = Roles::assign( $uid, $role, (array) ( isset( $p['modules'] ) ? $p['modules'] : array() ) );
		return is_wp_error( $r ) ? $r : self::team_access_get( $req );
	}

	public static function client_logins( \WP_REST_Request $req ) {
		$id  = (int) $req['id'];
		$out = array();
		foreach ( Portal_Users::users_for_client( $id ) as $u ) {
			$last            = (int) get_user_meta( $u['id'], 'bizycore_last_login', true );
			$inv             = (int) get_user_meta( $u['id'], 'bizycore_portal_invited', true );
			$u['last_login'] = $last ? gmdate( 'Y-m-d H:i:s', $last ) : '';
			$u['invited']    = $inv ? gmdate( 'Y-m-d H:i:s', $inv ) : '';
			$out[]           = $u;
		}
		return rest_ensure_response( array( 'users' => $out, 'enabled' => Portal::enabled(), 'portal_url' => Portal::url(), 'preview_url' => Portal::url( '', array( 'as' => $id ) ) ) );
	}

	public static function client_invite( \WP_REST_Request $req ) {
		$p   = (array) $req->get_json_params();
		$uid = (int) ( isset( $p['user_id'] ) ? $p['user_id'] : 0 );
		if ( ! in_array( $uid, Portal_Users::users_for_client( (int) $req['id'], false ), true ) ) {
			return new \WP_Error( 'bizycore_invite', 'That person is not a login for this client.', array( 'status' => 400 ) );
		}
		$r = Portal::invite( $uid );
		return is_wp_error( $r ) ? $r : rest_ensure_response( array( 'sent' => true ) );
	}
}
