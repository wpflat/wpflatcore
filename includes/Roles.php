<?php
namespace BizyCore;

defined( 'ABSPATH' ) || exit;

/**
 * Bizy's WordPress roles and capabilities. Core owns the people-and-access roles; every module adds its own
 * capabilities, team role and "manager can also manage…" grants through its registry definition, so installing a
 * module extends the role list and removing it leaves Core's roles intact.
 *
 * Core capabilities
 *   manage_bizycore     sign in to the Bizy app (every internal role)
 *   bizycore_clients    add / edit / delete clients, contacts and departments
 *   bizycore_team       add / edit / delete team members, and give them access
 *   bizycore_messages   the client inbox: read and answer clients' messages
 *   bizycore_admin      everything in Bizy, settings, modules, backups (but not WordPress itself)
 *   bizycore_portal     the client portal (clients only; never the internal app)
 *
 * Module definition keys used here: caps (all added to the admin role), roles (slug => label, desc, caps),
 * client_manager_caps, team_manager_caps, manager_grants (id => label, caps).
 */
class Roles {

	const CORE_CAPS = array( 'manage_bizycore', 'bizycore_clients', 'bizycore_team', 'bizycore_messages', 'bizycore_admin' );
	const HASH_OPT  = 'bizycore_roles_hash';

	/** Every capability Bizy knows about, core and installed modules. */
	public static function all_caps() {
		$caps = self::CORE_CAPS;
		foreach ( Registry::all() as $m ) {
			$caps = array_merge( $caps, (array) $m['caps'] );
		}
		return array_values( array_unique( $caps ) );
	}

	/** Extra modules a Team Manager can be given, chosen per person. */
	public static function manager_modules() {
		$out = array(
			'clients'  => array( 'label' => 'Clients', 'caps' => array( 'bizycore_clients' ) ),
			'messages' => array( 'label' => 'Client messages', 'caps' => array( 'bizycore_messages' ) ),
		);
		foreach ( Registry::all() as $m ) {
			foreach ( (array) $m['manager_grants'] as $id => $g ) {
				$out[ $id ] = $g;
			}
		}
		return $out;
	}

	private static function module_caps( $key ) {
		$caps = array();
		foreach ( Registry::all() as $m ) {
			$caps = array_merge( $caps, (array) $m[ $key ] );
		}
		return array_values( array_unique( $caps ) );
	}

	private static function flag( array $caps ) {
		return array_fill_keys( $caps, true );
	}

	public static function roles() {
		$base  = array( 'read' => true, 'upload_files' => true, 'manage_bizycore' => true );
		$roles = array(
			'bizy_user'           => array( 'label' => 'Bizy User', 'desc' => 'A person’s own login for the portal (a client, student, learner, patient… whatever you call them). Cannot open the Bizy app or WordPress admin.', 'caps' => array( 'read' => true, 'bizycore_portal' => true ), 'internal' => false ),
			'bizy_client_manager' => array( 'label' => 'Bizy Client Manager', 'desc' => 'Looks after clients: edits clients and contacts, answers messages and sees what the installed modules show about them.', 'caps' => $base + self::flag( array_merge( array( 'bizycore_clients', 'bizycore_messages' ), self::module_caps( 'client_manager_caps' ) ) ), 'internal' => true ),
			'bizy_team_manager'   => array( 'label' => 'Bizy Team Manager', 'desc' => 'Manages the team. You choose which other parts of Bizy each manager can also manage.', 'caps' => $base + self::flag( array_merge( array( 'bizycore_team', 'bizycore_messages' ), self::module_caps( 'team_manager_caps' ) ) ), 'internal' => true ),
			'bizy_admin'          => array( 'label' => 'Bizy Admin', 'desc' => 'Full access to Bizy, including settings, modules and backups. No access to WordPress itself.', 'caps' => $base + self::flag( self::all_caps() ), 'internal' => true ),
		);
		foreach ( Registry::all() as $m ) {
			foreach ( (array) $m['roles'] as $slug => $r ) {
				$roles[ $slug ] = array( 'label' => $r['label'], 'desc' => isset( $r['desc'] ) ? $r['desc'] : '', 'caps' => $base + self::flag( (array) $r['caps'] ), 'internal' => true, 'module' => $m['id'] );
			}
		}
		return $roles;
	}

	/** Create or refresh every role when the set of roles or capabilities changed (a module was added, say). Cheap otherwise. */
	public static function sync() {
		$hash = md5( wp_json_encode( self::roles() ) );
		if ( get_option( self::HASH_OPT ) === $hash ) {
			return;
		}
		self::install();
		update_option( self::HASH_OPT, $hash, false );
	}

	/** The portal role used to be called bizy_client; the word "client" is renamable, so it is now bizy_user. */
	private static function migrate_renamed() {
		if ( get_option( 'bizycore_role_user_renamed' ) ) {
			return;
		}
		if ( get_role( 'bizy_user' ) && get_role( 'bizy_client' ) ) {
			foreach ( get_users( array( 'role' => 'bizy_client', 'fields' => 'ID' ) ) as $uid ) {
				$u = new \WP_User( (int) $uid );
				$u->add_role( 'bizy_user' );
				$u->remove_role( 'bizy_client' );
			}
			remove_role( 'bizy_client' );
			update_option( 'bizycore_role_user_renamed', 1, false );
		}
	}

	public static function install() {
		$admin = get_role( 'administrator' );
		foreach ( self::all_caps() as $cap ) {
			if ( $admin && ! $admin->has_cap( $cap ) ) {
				$admin->add_cap( $cap );
			}
		}
		foreach ( self::roles() as $key => $r ) {
			$role = get_role( $key );
			if ( ! $role ) {
				add_role( $key, $r['label'], $r['caps'] );
				continue;
			}
			foreach ( $r['caps'] as $cap => $grant ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap, $grant );
				}
			}
		}
		self::migrate_renamed();
	}

	/** May this user manage Bizy itself (settings, modules, backups)? WordPress administrators and Bizy Admins. */
	public static function can_admin() {
		return current_user_can( 'manage_options' ) || current_user_can( 'bizycore_admin' );
	}

	/** Give a WordPress user one Bizy role (replacing any other Bizy role), plus a manager's chosen modules. */
	public static function assign( $user_id, $role_key, array $manager_modules = array() ) {
		$u = get_userdata( (int) $user_id );
		if ( ! $u ) {
			return new \WP_Error( 'bizycore_user', 'That user does not exist.', array( 'status' => 404 ) );
		}
		if ( user_can( $u, 'manage_options' ) ) {
			return new \WP_Error( 'bizycore_admin_user', 'This person is a WordPress administrator and already has full access.', array( 'status' => 400 ) );
		}
		$roles = self::roles();
		if ( '' !== $role_key && ! isset( $roles[ $role_key ] ) ) {
			return new \WP_Error( 'bizycore_role', 'Unknown role.', array( 'status' => 400 ) );
		}
		foreach ( array_keys( $roles ) as $k ) {
			if ( in_array( $k, (array) $u->roles, true ) ) {
				$u->remove_role( $k );
			}
		}
		$grants = self::manager_modules();
		foreach ( $grants as $g ) {
			foreach ( (array) $g['caps'] as $c ) {
				$u->remove_cap( $c );
			}
		}
		delete_user_meta( $u->ID, 'bizycore_manager_modules' );
		if ( '' === $role_key ) {
			return true;
		}
		$u->add_role( $role_key );
		if ( 'bizy_team_manager' === $role_key ) {
			$chosen = array_values( array_intersect( $manager_modules, array_keys( $grants ) ) );
			foreach ( $chosen as $m ) {
				foreach ( (array) $grants[ $m ]['caps'] as $c ) {
					$u->add_cap( $c );
				}
			}
			update_user_meta( $u->ID, 'bizycore_manager_modules', $chosen );
		}
		return true;
	}

	/** Current Bizy role of a user: array( role key, manager modules ). */
	public static function role_of( $user_id ) {
		$u = get_userdata( (int) $user_id );
		if ( ! $u ) {
			return array( '', array() );
		}
		foreach ( array_keys( self::roles() ) as $k ) {
			if ( in_array( $k, (array) $u->roles, true ) ) {
				$mods = get_user_meta( $u->ID, 'bizycore_manager_modules', true );
				return array( $k, is_array( $mods ) ? $mods : array() );
			}
		}
		return array( '', array() );
	}

	/** Booleans the browser uses to show or hide parts of the app: { app, clients, team, messages, admin, …module caps }. */
	public static function ui_caps() {
		$out = array();
		foreach ( self::all_caps() as $c ) {
			$key         = 'manage_bizycore' === $c ? 'app' : preg_replace( '/^bizy[a-z]*?_/', '', $c );
			$out[ $key ] = current_user_can( $c ) || current_user_can( 'manage_options' );
		}
		return $out;
	}
}
