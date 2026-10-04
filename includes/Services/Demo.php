<?php
namespace BizyCore\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Optional sample data, so a new install can be explored before real data goes in. Core adds departments, clients,
 * contacts and team members; each module adds its own through the `bizycore_demo_load` action, using Demo::add() so
 * every row is remembered. "Remove sample data" deletes exactly those rows and nothing the owner added.
 */
class Demo {

	const OPTION = 'bizycore_demo';

	/** @var array table (without prefix) => ids made by this load */
	private static $ids = array();

	/** @var array table => column names */
	private static $cols = array();

	public static function init() {
		add_filter( 'bizycore_boot', array( __CLASS__, 'boot_flags' ) );
	}

	public static function boot_flags( $d ) {
		$d['demoLoaded'] = \BizyCore\Roles::can_admin() && self::status()['loaded'];
		return $d;
	}

	public static function status() {
		$ids = get_option( self::OPTION, array() );
		$ids = is_array( $ids ) ? $ids : array();
		$login = get_option( 'bizycore_demo_login', array() );
		return array(
			'loaded' => ! empty( $ids ),
			'counts' => array_map( 'count', $ids ),
			'login'  => ( ! empty( $ids ) && is_array( $login ) && ! empty( $login['username'] ) ) ? array( 'username' => $login['username'], 'password' => $login['password'], 'url' => \BizyCore\Portal::url() ) : null,
			'role_login' => ( ! empty( $ids ) && is_array( $login ) && ! empty( $login['teacher']['username'] ) ) ? array( 'role' => $login['teacher']['role_label'], 'username' => $login['teacher']['username'], 'password' => $login['teacher']['password'], 'url' => home_url( '/files/' ) ) : null,
		);
	}

	/** Date `$offset` days from today (Y-m-d). */
	public static function day( $offset ) {
		return gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' ' . (int) $offset . ' days' ) );
	}

	/**
	 * Insert a sample row and remember it. $table is the table name without the WordPress prefix (bizy_clients…).
	 * $pk names the key column for tables that are keyed by something other than id.
	 * created_by / created_at / updated_at are filled in when the table has those columns.
	 */
	public static function add( $table, array $row, $pk = 'id' ) {
		global $wpdb;
		$full = $wpdb->prefix . $table;
		if ( ! isset( self::$cols[ $table ] ) ) {
			self::$cols[ $table ] = (array) $wpdb->get_col( "SHOW COLUMNS FROM $full", 0 ); // phpcs:ignore WordPress.DB
		}
		$now = current_time( 'mysql' );
		foreach ( array( 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) as $c => $v ) {
			if ( in_array( $c, self::$cols[ $table ], true ) && ! array_key_exists( $c, $row ) ) {
				$row[ $c ] = $v;
			}
		}
		$wpdb->insert( $full, $row );
		if ( 'id' !== $pk ) { // a table keyed by another column (client_id, team_member_id…)
			self::$ids[ $table . '@' . $pk ][] = (int) $row[ $pk ];
			return (int) $row[ $pk ];
		}
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			self::$ids[ $table ][] = $id;
		}
		return $id;
	}

	/** @return array|\WP_Error */
	public static function load( $force = false ) {
		global $wpdb;
		if ( self::status()['loaded'] ) {
			return new \WP_Error( 'bizycore_demo_loaded', 'Sample data is already loaded.', array( 'status' => 409 ) );
		}
		if ( ! $force && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'bizy_clients' ) > 0 ) { // phpcs:ignore WordPress.DB
			return new \WP_Error( 'bizycore_demo_notempty', 'Sample data can only be added to an empty workspace.', array( 'status' => 409 ) );
		}
		self::$ids = array();
		$web       = self::add( 'bizy_departments', array( 'name' => 'Web Studio (Sample)', 'type' => '', 'description' => 'Websites and hosting', 'status' => 'active' ) );
		$mkt       = self::add( 'bizy_departments', array( 'name' => 'Marketing (Sample)', 'type' => '', 'description' => 'Campaigns and content', 'status' => 'active' ) );
		$clients   = array();
		foreach ( array( array( 'Bright Foods (Sample)', 'Ada Obi', 'ada@bright.example', $web ), array( 'Northwind Logistics (Sample)', 'Sam Eze', 'sam@northwind.example', $mkt ), array( 'Lagos Dental (Sample)', 'Dr Tunde', 'tunde@dental.example', $web ) ) as $c ) {
			$id = self::add( 'bizy_clients', array( 'name' => $c[0], 'contact_person' => $c[1], 'email' => $c[2], 'status' => 'active' ) );
			self::add( 'bizy_client_departments', array( 'client_id' => $id, 'department_id' => $c[3], 'is_primary' => 1 ) );
			self::add( 'bizy_client_contacts', array( 'client_id' => $id, 'name' => $c[1], 'email' => $c[2], 'kind' => 'primary', 'sort_order' => 0 ) );
			$clients[] = $id;
		}
		$group_a = self::add( 'bizy_groups', array( 'name' => 'Class A (Sample)', 'description' => 'Morning class', 'color' => '#2563eb', 'status' => 'active', 'department_id' => $web ) );
		$group_b = self::add( 'bizy_groups', array( 'name' => 'Class B (Sample)', 'description' => 'Evening class', 'color' => '#16a34a', 'status' => 'active', 'department_id' => $web ) );
		self::add( 'bizy_group_members', array( 'group_id' => $group_a, 'client_id' => $clients[0] ) );
		self::add( 'bizy_group_members', array( 'group_id' => $group_a, 'client_id' => $clients[1] ) );
		self::add( 'bizy_group_members', array( 'group_id' => $group_b, 'client_id' => $clients[2] ) );
		// A sample portal login, so the front end can be tried as a user (the first client's contact, in Class A).
		$uid = Portal_Users::ensure_user( 'ada@bright.example', 'Ada Obi', $clients[0] );
		if ( $uid ) {
			$pass = wp_generate_password( 14, false );
			wp_set_password( $pass, $uid );
			$u = get_userdata( $uid );
			update_option( 'bizycore_demo_login', array( 'user_id' => $uid, 'username' => $u->user_login, 'password' => $pass ), false );
		}
		// A sample custom role (what a role-editor plugin makes), a group tied to it, and a user who has the role but is not a client.
		add_role( 'bizy_demo_teacher', 'Teacher (Sample)', array( 'read' => true ) );
		$group_t = self::add( 'bizy_groups', array( 'name' => 'Teachers (Sample)', 'description' => 'Everyone with the Teacher role', 'color' => '#7c3aed', 'status' => 'active', 'department_id' => $web ) );
		Groups::set_roles( $group_t, array( 'bizy_demo_teacher' ) );
		self::$ids['bizy_group_roles@group_id'][] = $group_t;
		$tlogin = 'sample.teacher';
		$temail = 'teacher@sample.example';
		for ( $i = 2; username_exists( $tlogin ) || email_exists( $temail ); $i++ ) { // never take over someone else's account
			$tlogin = 'sample.teacher' . $i;
			$temail = 'teacher' . $i . '@sample.example';
		}
		$tuser = wp_insert_user( array( 'user_login' => $tlogin, 'user_pass' => wp_generate_password( 14, false ), 'user_email' => $temail, 'display_name' => 'Tola Teacher (Sample)', 'role' => 'bizy_demo_teacher' ) );
		$teacher = null;
		if ( ! is_wp_error( $tuser ) ) {
			$tpass = wp_generate_password( 14, false );
			wp_set_password( $tpass, $tuser );
			update_user_meta( $tuser, 'show_admin_bar_front', 'false' );
			$teacher = array( 'user_id' => $tuser, 'username' => $tlogin, 'password' => $tpass, 'role_label' => 'Teacher (Sample)' );
		}
		$demo_login = get_option( 'bizycore_demo_login', array() );
		if ( $teacher ) {
			$demo_login            = is_array( $demo_login ) ? $demo_login : array();
			$demo_login['teacher'] = $teacher;
			update_option( 'bizycore_demo_login', $demo_login, false );
		}
		$team = array(
			self::add( 'bizy_team_members', array( 'name' => 'Zara Bello (Sample)', 'email' => 'zara@sample.example', 'role' => 'Designer', 'kind' => 'employee', 'status' => 'active', 'department_id' => $web ) ),
			self::add( 'bizy_team_members', array( 'name' => 'Tunde Okafor (Sample)', 'email' => 'tunde@sample.example', 'role' => 'Developer', 'kind' => 'employee', 'status' => 'active', 'department_id' => $web ) ),
		);
		/**
		 * Modules add their own sample rows with Demo::add().
		 *
		 * @param array $ctx departments (web, marketing), clients (3 ids), team (2 ids)
		 */
		do_action( 'bizycore_demo_load', array( 'web' => $web, 'marketing' => $mkt, 'clients' => $clients, 'team' => $team, 'groups' => array( $group_a, $group_b ), 'role_group' => $group_t ) );
		update_option( self::OPTION, self::$ids, false );
		delete_option( 'bizycore_fresh_install' );
		return self::status();
	}

	/** Delete exactly what load() created, newest first, and the activity that mentions it. */
	public static function clear() {
		global $wpdb;
		$ids = get_option( self::OPTION, array() );
		if ( ! is_array( $ids ) || ! $ids ) {
			return self::status();
		}
		foreach ( array_reverse( $ids, true ) as $table => $list ) {
			$in = implode( ',', array_map( 'intval', $list ) );
			list( $name, $col ) = array_pad( explode( '@', $table, 2 ), 2, 'id' );
			if ( '' !== $in && preg_match( '/^[a-z0-9_]+$/', $name ) && preg_match( '/^[a-z0-9_]+$/', $col ) ) {
				$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . $name . " WHERE $col IN ($in)" ); // phpcs:ignore WordPress.DB
			}
		}
		if ( ! empty( $ids['bizy_groups'] ) ) {
			$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'bizy_group_roles WHERE group_id IN (' . implode( ',', array_map( 'intval', $ids['bizy_groups'] ) ) . ')' ); // phpcs:ignore WordPress.DB
		}
		if ( ! empty( $ids['bizy_clients'] ) ) {
			$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'bizy_activity WHERE client_id IN (' . implode( ',', array_map( 'intval', $ids['bizy_clients'] ) ) . ')' ); // phpcs:ignore WordPress.DB
		}
		do_action( 'bizycore_demo_cleared', $ids );
		$login = get_option( 'bizycore_demo_login', array() );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( is_array( $login ) && ! empty( $login['user_id'] ) ) {
			wp_delete_user( (int) $login['user_id'] );
		}
		if ( is_array( $login ) && ! empty( $login['teacher']['user_id'] ) ) {
			wp_delete_user( (int) $login['teacher']['user_id'] );
		}
		remove_role( 'bizy_demo_teacher' );
		delete_option( 'bizycore_demo_login' );
		delete_option( self::OPTION );
		return self::status();
	}
}
