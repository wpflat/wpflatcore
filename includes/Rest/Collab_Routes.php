<?php
namespace WPFlatCore\Rest;

use WPFlatCore\Database\Schema;
use WPFlatCore\Portal;
use WPFlatCore\Roles;
use WPFlatCore\Services\Departments;
use WPFlatCore\Services\Home;
use WPFlatCore\Services\Messages;
use WPFlatCore\Services\Search;
use WPFlatCore\Services\Notifications;
use WPFlatCore\Services\Portal_Users;

defined( 'ABSPATH' ) || exit;

/** Core's people-facing routes: messages, notifications, client portal logins and access (roles). Namespace wpflatcore/v1. */
class Collab_Routes {

	const NS = 'wpflatcore/v1';

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
			return current_user_can( 'wpflatcore_clients' ) || current_user_can( 'manage_options' );
		};
		$team = function () {
			return current_user_can( 'wpflatcore_team' ) || current_user_can( 'manage_options' );
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
		self::reg( '/team/access-options', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'team_access_options' ), 'permission_callback' => $team ) );
		self::reg( '/team/(?P<id>\d+)/invite', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'team_invite' ), 'permission_callback' => $team ) );
		self::reg( '/team/(?P<id>\d+)/link-me', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'link_me_start' ), 'permission_callback' => $team ) );
		self::reg( '/team/(?P<id>\d+)/link-me/verify', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'link_me_verify' ), 'permission_callback' => $team ) );
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
		return $d ? rest_ensure_response( $d ) : new \WP_Error( 'wpflatcore_not_found', 'Not found.', array( 'status' => 404 ) );
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
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, email, wp_user_id FROM ' . Schema::table( 'wpflat_team_members' ) . ' WHERE id=%d', $team_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/* "This is me": linking a team record to the signed-in login is only allowed after proving the mailbox on the record is yours. */

	private static function link_key( $member_id ) {
		return 'wpflatcore_linkme_' . (int) $member_id . '_' . get_current_user_id();
	}

	/** Email a 6-digit code to the address on the team record. */
	public static function link_me_start( \WP_REST_Request $req ) {
		$m = self::member( (int) $req['id'] );
		if ( ! $m ) {
			return new \WP_Error( 'wpflatcore_missing', 'Team member not found.', array( 'status' => 404 ) );
		}
		$email = sanitize_email( $m['email'] );
		if ( ! $email ) {
			return new \WP_Error( 'wpflatcore_email', 'This person has no email address to send a code to.', array( 'status' => 400 ) );
		}
		$key = self::link_key( $m['id'] );
		$cur = get_transient( $key );
		if ( $cur && time() - (int) $cur['sent'] < 60 ) {
			return new \WP_Error( 'wpflatcore_wait', 'A code was just sent. Wait a minute before asking for another.', array( 'status' => 429 ) );
		}
		$code = (string) wp_rand( 100000, 999999 );
		set_transient( $key, array( 'hash' => wp_hash_password( $code ), 'sent' => time(), 'tries' => 0 ), 10 * MINUTE_IN_SECONDS );
		$first = '' !== trim( (string) $m['name'] ) ? trim( explode( ' ', trim( $m['name'] ) )[0] ) : 'there';
		$me    = wp_get_current_user();
		$html  = '<p>Hi ' . esc_html( $first ) . ',</p><p>Someone signed in as <strong>' . esc_html( $me->user_login ) . '</strong> asked to link this team record to their login. If that is you, enter this code:</p><p style="font-size:28px;letter-spacing:6px;font-weight:700;">' . esc_html( $code ) . '</p><p>It works for 10 minutes. If it was not you, ignore this email: nothing is linked without the code.</p>';
		$text  = "Hi {$first},\n\nSomeone signed in as {$me->user_login} asked to link this team record to their login. If that is you, enter this code: {$code}\nIt works for 10 minutes. If it was not you, ignore this email.\n";
		$r     = \WPFlatCore\Services\Mailer::send( array( 'to' => array( $email ), 'subject' => 'Your confirmation code: ' . $code, 'heading' => 'Confirm it is you', 'preheader' => 'Your code is ' . $code, 'html' => $html, 'text' => $text ) );
		if ( empty( $r['ok'] ) ) {
			delete_transient( $key );
			return new \WP_Error( 'wpflatcore_mail', 'The code could not be emailed: ' . ( isset( $r['error'] ) ? $r['error'] : 'mail failed' ), array( 'status' => 502 ) );
		}
		$at = strpos( $email, '@' );
		return rest_ensure_response( array( 'sent' => true, 'to' => substr( $email, 0, min( 2, $at ) ) . '***' . substr( $email, $at ) ) );
	}

	/** Check the code; on success the record is linked to the signed-in login. */
	public static function link_me_verify( \WP_REST_Request $req ) {
		global $wpdb;
		$m = self::member( (int) $req['id'] );
		if ( ! $m ) {
			return new \WP_Error( 'wpflatcore_missing', 'Team member not found.', array( 'status' => 404 ) );
		}
		$key = self::link_key( $m['id'] );
		$cur = get_transient( $key );
		if ( ! $cur ) {
			return new \WP_Error( 'wpflatcore_code', 'That code has expired. Ask for a new one.', array( 'status' => 400 ) );
		}
		$p    = (array) $req->get_json_params();
		$code = preg_replace( '/\D/', '', (string) ( isset( $p['code'] ) ? $p['code'] : '' ) );
		if ( ++$cur['tries'] > 5 ) {
			delete_transient( $key );
			return new \WP_Error( 'wpflatcore_code', 'Too many wrong codes. Ask for a new one.', array( 'status' => 429 ) );
		}
		if ( '' === $code || ! wp_check_password( $code, $cur['hash'] ) ) {
			set_transient( $key, $cur, 10 * MINUTE_IN_SECONDS );
			return new \WP_Error( 'wpflatcore_code', 'That code is not right.', array( 'status' => 400 ) );
		}
		delete_transient( $key );
		$wpdb->update( Schema::table( 'wpflat_team_members' ), array( 'wp_user_id' => get_current_user_id() ), array( 'id' => (int) $m['id'] ) );
		\WPFlatCore\Services\Activity::log( 'team', (int) $m['id'], 0, 'linked', sprintf( 'Team record %s linked to login %s after an email code', $m['name'], wp_get_current_user()->user_login ) );
		return rest_ensure_response( array( 'linked' => true ) );
	}

	public static function team_access_get( \WP_REST_Request $req ) {
		$m = self::member( (int) $req['id'] );
		if ( ! $m ) {
			return new \WP_Error( 'wpflatcore_missing', 'Team member not found.', array( 'status' => 404 ) );
		}
		$uid                 = (int) $m['wp_user_id'];
		list( $role, $mods ) = $uid ? Roles::role_of( $uid ) : array( '', array() );
		$u                   = $uid ? get_userdata( $uid ) : null;
		$pend                = ( ! $u ) ? \WPFlatCore\Services\Team_Invite::pending( (int) $m['id'] ) : null;
		if ( $pend ) {
			$role = (string) $pend['role'];
			$mods = (array) $pend['modules'];
		}
		return rest_ensure_response( array(
			'has_login'       => (bool) $u,
			'login'           => $u ? $u->user_login : '',
			'is_wp_admin'     => $u ? user_can( $u, 'manage_options' ) : false,
			'role'            => $role,
			'modules'         => $mods,
			'roles'           => self::role_options(),
			'manager_modules' => self::grant_labels(),
			'pending'         => (bool) $pend,
			'invited'         => ( $pend && ! empty( $pend['sent_at'] ) ) ? gmdate( 'Y-m-d H:i:s', (int) $pend['sent_at'] ) : '',
			'joined'          => $u ? ( (int) get_user_meta( $u->ID, 'wpflatcore_team_joined', true ) ? gmdate( 'Y-m-d H:i:s', (int) get_user_meta( $u->ID, 'wpflatcore_team_joined', true ) ) : '' ) : '',
			'email'           => $m['email'],
		) );
	}

	/** The roles and manager modules offered when adding a team member (before they have a record). */
	public static function team_access_options() {
		return rest_ensure_response( array( 'roles' => self::role_options(), 'manager_modules' => self::grant_labels() ) );
	}

	/**
	 * Give a team member a role. Someone who has no login yet gets a waiting invite (no WordPress login is created until they accept, and they
	 * choose their own username then). Someone who already has a login gets the role straight away.
	 */
	public static function team_access_set( \WP_REST_Request $req ) {
		global $wpdb;
		$m = self::member( (int) $req['id'] );
		if ( ! $m ) {
			return new \WP_Error( 'wpflatcore_missing', 'Team member not found.', array( 'status' => 404 ) );
		}
		$p      = (array) $req->get_json_params();
		$role   = (string) ( isset( $p['role'] ) ? $p['role'] : '' );
		$mods   = (array) ( isset( $p['modules'] ) ? $p['modules'] : array() );
		$invite = ! empty( $p['invite'] ) || ! empty( $p['notify'] );
		$uid    = (int) $m['wp_user_id'];
		$error  = '';
		if ( '' !== $role && ! isset( Roles::roles()[ $role ] ) ) {
			return new \WP_Error( 'wpflatcore_role', 'Unknown role.', array( 'status' => 400 ) );
		}
		if ( ! $uid || ! get_userdata( $uid ) ) {
			$email = sanitize_email( $m['email'] );
			$ex    = $email ? get_user_by( 'email', $email ) : false;
			if ( $ex ) { // they already have a login on this site: link it and give the role now
				$uid = (int) $ex->ID;
				$wpdb->update( Schema::table( 'wpflat_team_members' ), array( 'wp_user_id' => $uid ), array( 'id' => (int) $m['id'] ) );
				\WPFlatCore\Services\Team_Invite::forget( (int) $m['id'] );
			} elseif ( '' === $role ) {
				\WPFlatCore\Services\Team_Invite::forget( (int) $m['id'] );
				return self::team_access_get( $req );
			} else {
				if ( ! $email ) {
					return new \WP_Error( 'wpflatcore_email', 'Add an email address to this person first.', array( 'status' => 400 ) );
				}
				\WPFlatCore\Services\Team_Invite::set_pending( (int) $m['id'], $role, $mods );
				if ( $invite ) {
					$sent = \WPFlatCore\Services\Team_Invite::send( (int) $m['id'] );
					if ( is_wp_error( $sent ) ) {
						$error = $sent->get_error_message();
					}
				}
				$res = self::team_access_get( $req );
				if ( '' !== $error ) {
					$d                 = $res->get_data();
					$d['invite_error'] = $error;
					$res->set_data( $d );
				}
				return $res;
			}
		}
		$r = Roles::assign( $uid, $role, $mods );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( $invite && '' !== $role ) {
			$sent = \WPFlatCore\Services\Team_Invite::notify_existing( $uid );
			if ( is_wp_error( $sent ) ) {
				$error = $sent->get_error_message();
			}
		}
		$res = self::team_access_get( $req );
		if ( '' !== $error ) {
			$d                 = $res->get_data();
			$d['invite_error'] = $error;
			$res->set_data( $d );
		}
		return $res;
	}

	/** Send (or send again) the invite to a team member who has not joined yet. */
	public static function team_invite( \WP_REST_Request $req ) {
		$m = self::member( (int) $req['id'] );
		if ( ! $m ) {
			return new \WP_Error( 'wpflatcore_missing', 'Team member not found.', array( 'status' => 404 ) );
		}
		if ( (int) $m['wp_user_id'] && get_userdata( (int) $m['wp_user_id'] ) ) {
			return new \WP_Error( 'wpflatcore_invite', 'This person has already joined.', array( 'status' => 400 ) );
		}
		$r = \WPFlatCore\Services\Team_Invite::send( (int) $m['id'] );
		return is_wp_error( $r ) ? $r : self::team_access_get( $req );
	}

	public static function client_logins( \WP_REST_Request $req ) {
		$id  = (int) $req['id'];
		$out = array();
		foreach ( Portal_Users::users_for_client( $id ) as $u ) {
			$last            = (int) get_user_meta( $u['id'], 'wpflatcore_last_login', true );
			$inv             = (int) get_user_meta( $u['id'], 'wpflatcore_portal_invited', true );
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
			return new \WP_Error( 'wpflatcore_invite', 'That person is not a login for this client.', array( 'status' => 400 ) );
		}
		$r = Portal::invite( $uid );
		return is_wp_error( $r ) ? $r : rest_ensure_response( array( 'sent' => true ) );
	}
}
