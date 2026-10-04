<?php
namespace BizyCore;

use BizyCore\Database\Schema;
use BizyCore\Services\Activity;

defined( 'ABSPATH' ) || exit;

/** Core's own resources: departments, clients, contacts, team, lookups, activity. */
class Core_Resources {

	public static function register() {
		$cj = 'LEFT JOIN ' . Schema::table( 'bizy_clients' ) . ' c ON c.id=t.client_id';
		$all = array(
			'departments' => array(
				'route'    => 'departments',
				'table'    => 'bizy_departments',
				'label'    => Terminology::label( 'department' ),
				'cols'     => array( 'name' => 's', 'type' => 's', 'description' => 't', 'logo_attachment_id' => 'i', 'status' => 's' ),
				'filters'  => array( 'type', 'status' ),
				'search'   => array( 'name' ),
				'order'    => 't.name ASC',
				'required' => array( 'name' ),
				'write_cap' => 'bizycore_clients',
				'title'    => function ( $r ) {
					return $r['name'];
				},
				'before_save' => function ( $d, $existing ) {
					return $existing ? $d : $d + array( 'status' => 'active' );
				},
				'decorate' => function ( $rows ) {
					foreach ( $rows as &$r ) {
						$r['logo_url'] = $r['logo_attachment_id'] ? wp_get_attachment_image_url( $r['logo_attachment_id'], 'thumbnail' ) : '';
					}
					return $rows;
				},
				'after_delete' => function ( $row ) {
					global $wpdb;
					$wpdb->delete( Schema::table( 'bizy_client_departments' ), array( 'department_id' => $row['id'] ) );
				},
			),

			'groups'      => array(
				'route'    => 'groups',
				'table'    => 'bizy_groups',
				'label'    => Terminology::label( 'group' ),
				'select'   => ', (SELECT COUNT(*) FROM ' . Schema::table( 'bizy_group_members' ) . ' gm WHERE gm.group_id=t.id) AS member_count',
				'cols'     => array( 'name' => 's', 'description' => 't', 'color' => 's', 'status' => 's', 'department_id' => 'i' ),
				'filters'  => array( 'status', 'department_id' ),
				'search'   => array( 'name', 'description' ),
				'order'    => 't.name ASC',
				'required' => array( 'name' ),
				'write_cap' => 'bizycore_clients',
				'title'    => function ( $r ) {
					return $r['name'];
				},
				'before_save' => function ( $d, $existing ) {
					return $existing ? $d : $d + array( 'status' => 'active' );
				},
				'after_save' => function ( $row, $existing, $params ) {
					if ( isset( $params['member_ids'] ) && is_array( $params['member_ids'] ) ) {
						Services\Groups::sync_group( $row['id'], $params['member_ids'] );
					}
				},
				'decorate' => function ( $rows, $single ) {
					global $wpdb;
					foreach ( $rows as &$r ) {
						$r['member_count'] = (int) $r['member_count'];
						if ( $single ) {
							$r['members'] = array_map( function ( $m ) {
								return array( 'id' => (int) $m['id'], 'name' => $m['name'], 'email' => $m['email'] );
							}, (array) $wpdb->get_results( $wpdb->prepare( 'SELECT c.id, c.name, c.email FROM ' . Schema::table( 'bizy_group_members' ) . ' gm JOIN ' . Schema::table( 'bizy_clients' ) . ' c ON c.id=gm.client_id WHERE gm.group_id=%d ORDER BY c.name', $r['id'] ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
							$r['member_ids'] = wp_list_pluck( $r['members'], 'id' );
						}
					}
					return $rows;
				},
				'after_delete' => function ( $row ) {
					global $wpdb;
					$wpdb->delete( Schema::table( 'bizy_group_members' ), array( 'group_id' => $row['id'] ) );
					do_action( 'bizycore_group_deleted', (int) $row['id'] );
				},
			),

			'clients'     => array(
				'route'    => 'clients',
				'table'    => 'bizy_clients',
				'label'    => Terminology::label( 'client' ),
				'cols'     => array( 'name' => 's', 'contact_person' => 's', 'email' => 'e', 'phone' => 's', 'address' => 't', 'notes' => 't', 'type' => 's', 'status' => 's' ),
				'filters'  => array( 'type', 'status' ),
				'search'   => array( 'name', 'contact_person', 'email' ),
				'order'    => 't.name ASC',
				'required' => array( 'name' ),
				'write_cap' => 'bizycore_clients',
				'title'    => function ( $r ) {
					return $r['name'];
				},
				'extra'    => function ( $a ) {
					$sql = array();
					$p   = array();
					$cd  = Schema::table( 'bizy_client_departments' );
					if ( ! empty( $a['department_id'] ) ) {
						$sql[] = "EXISTS (SELECT 1 FROM $cd x WHERE x.client_id=t.id AND x.department_id=%d)";
						$p[]   = (int) $a['department_id'];
					}
					if ( ! empty( $a['group_id'] ) ) {
						$sql[] = 'EXISTS (SELECT 1 FROM ' . Schema::table( 'bizy_group_members' ) . ' gx WHERE gx.client_id=t.id AND gx.group_id=%d)';
						$p[]   = (int) $a['group_id'];
					}
					if ( ! empty( $a['department_type'] ) ) {
						$sql[] = "EXISTS (SELECT 1 FROM $cd x JOIN " . Schema::table( 'bizy_departments' ) . ' b ON b.id=x.department_id WHERE x.client_id=t.id AND b.type=%s)';
						$p[]   = $a['department_type'];
					}
					return array( implode( ' AND ', $sql ), $p );
				},
				'before_save' => function ( $d, $existing ) {
					return $existing ? $d : $d + array( 'status' => 'active', 'type' => 'adhoc' );
				},
				'after_save' => function ( $row, $existing, $params ) {
					global $wpdb;
					// A new client with a contact name/email on the form gets its first primary contact.
					if ( ! $existing && ( '' !== (string) $row['email'] || '' !== (string) $row['contact_person'] ) ) {
						$ct = Schema::table( 'bizy_client_contacts' );
						if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $ct WHERE client_id=%d LIMIT 1", $row['id'] ) ) ) { // phpcs:ignore WordPress.DB
							$now = current_time( 'mysql' );
							$wpdb->insert( $ct, array( 'client_id' => $row['id'], 'name' => $row['contact_person'] ? $row['contact_person'] : $row['name'], 'email' => $row['email'], 'phone' => $row['phone'], 'kind' => 'primary', 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
						}
					}
					do_action( 'bizycore_client_contacts_changed', (int) $row['id'] );
					if ( isset( $params['group_ids'] ) && is_array( $params['group_ids'] ) ) {
						Services\Groups::sync_client( $row['id'], $params['group_ids'] );
					}
					if ( ! isset( $params['department_ids'] ) || ! is_array( $params['department_ids'] ) ) {
						return;
					}
					$tbl = Schema::table( 'bizy_client_departments' );
					$wpdb->delete( $tbl, array( 'client_id' => $row['id'] ) );
					$primary = isset( $params['primary_department_id'] ) ? (int) $params['primary_department_id'] : 0;
					foreach ( array_unique( array_map( 'intval', $params['department_ids'] ) ) as $i => $did ) {
						if ( $did ) {
							$wpdb->insert( $tbl, array( 'client_id' => $row['id'], 'department_id' => $did, 'is_primary' => ( $primary ? (int) ( $primary === $did ) : (int) ( 0 === $i ) ) ) );
						}
					}
				},
				'decorate' => function ( $rows ) {
					global $wpdb;
					if ( ! $rows ) {
						return $rows;
					}
					$ids = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
					$res = $wpdb->get_results( 'SELECT cd.client_id, cd.is_primary, b.id, b.name, b.type FROM ' . Schema::table( 'bizy_client_departments' ) . ' cd JOIN ' . Schema::table( 'bizy_departments' ) . " b ON b.id=cd.department_id WHERE cd.client_id IN ($ids) ORDER BY cd.is_primary DESC, b.name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
					$map = array();
					foreach ( (array) $res as $b ) {
						$map[ $b['client_id'] ][] = array( 'id' => (int) $b['id'], 'name' => $b['name'], 'type' => $b['type'], 'is_primary' => (bool) $b['is_primary'] );
					}
					$cmap = array();
					$cres = $wpdb->get_results( 'SELECT id, client_id, name, email, phone, job_title, kind FROM ' . Schema::table( 'bizy_client_contacts' ) . " WHERE client_id IN ($ids) ORDER BY kind='primary' DESC, sort_order ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
					foreach ( (array) $cres as $c ) {
						$cmap[ $c['client_id'] ][] = array( 'id' => (int) $c['id'], 'name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone'], 'job_title' => $c['job_title'], 'kind' => $c['kind'] );
					}
					$gmap = array();
					foreach ( (array) $wpdb->get_results( 'SELECT gm.client_id, g.id, g.name, g.color FROM ' . Schema::table( 'bizy_group_members' ) . ' gm JOIN ' . Schema::table( 'bizy_groups' ) . " g ON g.id=gm.group_id WHERE gm.client_id IN ($ids) ORDER BY g.name", ARRAY_A ) as $g ) { // phpcs:ignore WordPress.DB
						$gmap[ $g['client_id'] ][] = array( 'id' => (int) $g['id'], 'name' => $g['name'], 'color' => $g['color'] );
					}
					foreach ( $rows as &$r ) {
						$r['groups']         = isset( $gmap[ $r['id'] ] ) ? $gmap[ $r['id'] ] : array();
						$r['group_ids']      = wp_list_pluck( $r['groups'], 'id' );
						$r['departments']    = isset( $map[ $r['id'] ] ) ? $map[ $r['id'] ] : array();
						$r['department_ids'] = wp_list_pluck( $r['departments'], 'id' );
						$r['contacts']       = isset( $cmap[ $r['id'] ] ) ? $cmap[ $r['id'] ] : array();
					}
					return $rows;
				},
				'after_delete' => function ( $row ) {
					global $wpdb;
					$wpdb->delete( Schema::table( 'bizy_client_departments' ), array( 'client_id' => $row['id'] ) );
					$wpdb->delete( Schema::table( 'bizy_client_contacts' ), array( 'client_id' => $row['id'] ) );
					Services\Groups::forget_client( $row['id'] );
					do_action( 'bizycore_client_deleted', (int) $row['id'] ); // portal logins, messages and every module's client data
				},
			),

			'contacts'    => array(
				'route'    => 'contacts',
				'table'    => 'bizy_client_contacts',
				'label'    => 'Contact',
				'cols'     => array( 'client_id' => 'i', 'name' => 's', 'email' => 'e', 'phone' => 's', 'job_title' => 's', 'kind' => 's', 'sort_order' => 'i' ),
				'filters'  => array( 'client_id', 'kind' ),
				'search'   => array( 'name', 'email', 'job_title' ),
				'order'    => "t.kind='primary' DESC, t.sort_order ASC, t.id ASC",
				'required' => array( 'client_id', 'name' ),
				'write_cap' => 'bizycore_clients',
				'title'    => function ( $r ) {
					return $r['name'] . ( $r['email'] ? ' <' . $r['email'] . '>' : '' );
				},
				'before_save' => function ( $d, $existing, $params ) {
					global $wpdb;
					if ( isset( $params['email'] ) && '' !== trim( (string) $params['email'] ) && empty( $d['email'] ) ) {
						return new \WP_Error( 'bizycore_invalid', 'Enter a valid email address.', array( 'status' => 400 ) );
					}
					$cid = isset( $d['client_id'] ) ? (int) $d['client_id'] : ( $existing ? (int) $existing['client_id'] : 0 );
					if ( ! isset( $d['kind'] ) || ! in_array( $d['kind'], array( 'primary', 'secondary' ), true ) ) {
						$has       = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'bizy_client_contacts' ) . ' WHERE client_id=%d', $cid ) ); // phpcs:ignore WordPress.DB
						$d['kind'] = $existing ? $existing['kind'] : ( $has ? 'secondary' : 'primary' );
					}
					$email = isset( $d['email'] ) ? $d['email'] : ( $existing ? $existing['email'] : '' );
					if ( 'primary' === $d['kind'] && '' === $email ) {
						return new \WP_Error( 'bizycore_invalid', 'Primary contacts need an email address so documents can reach them.', array( 'status' => 400 ) );
					}
					return $d;
				},
				'after_save'   => function ( $row ) {
					Services\Contacts::sync_client( $row['client_id'] );
				},
				'after_delete' => function ( $row ) {
					Services\Contacts::sync_client( $row['client_id'] );
				},
			),

			'team'        => array(
				'route'    => 'team',
				'table'    => 'bizy_team_members',
				'label'    => Terminology::label( 'team' ),
				'log'      => false,
				'select'   => ', dp.name AS department_name',
				'join'     => 'LEFT JOIN ' . Schema::table( 'bizy_departments' ) . ' dp ON dp.id=t.department_id',
				'cols'     => array( 'name' => 's', 'email' => 'e', 'role' => 's', 'cc_documents' => 'b', 'wp_user_id' => 'i', 'kind' => 's', 'department_id' => 'i', 'start_date' => 'date', 'end_date' => 'date', 'notes' => 't', 'status' => 's' ),
				'filters'  => array( 'status', 'kind', 'department_id' ),
				'search'   => array( 'name', 'email', 'role' ),
				'order'    => 't.name ASC',
				'required' => array( 'name', 'email' ),
				'write_cap' => 'bizycore_team',
				'before_save' => function ( $d, $existing, $params ) {
					if ( isset( $params['email'] ) && empty( $d['email'] ) ) {
						return new \WP_Error( 'bizycore_invalid', 'Enter a valid email address.', array( 'status' => 400 ) );
					}
					if ( ! $existing ) {
						$d += array( 'status' => 'active', 'cc_documents' => 1 );
					}
					// Link to the WordPress login with the same email so "My tasks" and similar views work.
					$email = isset( $d['email'] ) ? $d['email'] : ( $existing ? $existing['email'] : '' );
					if ( $email && ! isset( $d['wp_user_id'] ) && ( ! $existing || empty( $existing['wp_user_id'] ) ) ) {
						$u = get_user_by( 'email', $email );
						if ( $u ) {
							$d['wp_user_id'] = (int) $u->ID;
						}
					}
					return $d;
				},
				'decorate' => function ( $rows ) {
					foreach ( $rows as &$r ) {
						$r['is_me'] = (int) $r['wp_user_id'] > 0 && (int) $r['wp_user_id'] === get_current_user_id();
					}
					return $rows;
				},
			),

			'activity'    => array(
				'route'      => 'activity',
				'table'      => 'bizy_activity',
				'label'      => 'Activity',
				'readonly'   => true,
				'log'        => false,
				'timestamps' => false,
				'select'     => ', c.name AS client_name',
				'join'       => $cj,
				'cols'       => array( 'entity_type' => 's', 'entity_id' => 'i', 'client_id' => 'i', 'action' => 's', 'summary' => 's', 'user_id' => 'i' ),
				'filters'    => array( 'entity_type', 'entity_id', 'client_id', 'action' ),
				'search'     => array( 't.summary', 'c.name' ),
				'order'      => 't.id DESC',
				'decorate'   => function ( $rows ) {
					foreach ( $rows as &$r ) {
						$u              = $r['user_id'] ? get_userdata( $r['user_id'] ) : null;
						$r['user_name'] = $u ? $u->display_name : '';
					}
					return $rows;
				},
			),

			'lookups'     => array(
				'route'       => 'lookups',
				'table'       => 'bizy_lookups',
				'label'       => 'Option',
				'log'         => false,
				'timestamps'  => false,
				'cols'        => array( 'type' => 's', 'item_key' => 's', 'label' => 's', 'color' => 's', 'months' => 'i', 'sort_order' => 'i', 'is_active' => 'b' ),
				'filters'     => array( 'type', 'is_active' ),
				'search'      => array( 'label' ),
				'order'       => 't.type ASC, t.sort_order ASC, t.id ASC',
				'required'    => array( 'type', 'label' ),
				'write_cap'   => 'bizycore_admin',
				'before_save' => function ( $d, $existing ) {
					global $wpdb;
					if ( ! $existing ) {
						$base = ! empty( $d['item_key'] ) ? sanitize_key( $d['item_key'] ) : sanitize_key( str_replace( ' ', '_', strtolower( $d['label'] ) ) );
						$key  = $base ? $base : 'option';
						$n    = 2;
						$tbl  = Schema::table( 'bizy_lookups' );
						while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tbl WHERE type=%s AND item_key=%s", $d['type'], $key ) ) ) { // phpcs:ignore WordPress.DB
							$key = $base . '_' . $n++;
						}
						$d['item_key'] = $key;
					} else {
						unset( $d['item_key'], $d['type'] ); // keys are immutable once records may reference them
					}
					return $d;
				},
				'before_delete' => function ( $row ) {
					if ( ! empty( $row['is_system'] ) ) {
						return new \WP_Error( 'bizycore_protected', 'Built-in options can be renamed or deactivated, not deleted.', array( 'status' => 400 ) );
					}
					return true;
				},
			),
		);
		foreach ( $all as $name => $cfg ) {
			Resources::register( $name, $cfg, 'core' );
		}

		Links::register_type( 'client', array( 'module' => 'core', 'label' => Terminology::label( 'client' ), 'plural' => Terminology::label( 'client', true ), 'resolve' => array( __CLASS__, 'resolve_client' ), 'search' => array( __CLASS__, 'search_clients' ) ) );
		Links::register_type( 'department', array( 'module' => 'core', 'label' => Terminology::label( 'department' ), 'plural' => Terminology::label( 'department', true ), 'resolve' => array( __CLASS__, 'resolve_department' ), 'search' => array( __CLASS__, 'search_departments' ) ) );
		Links::register_type( 'team_member', array( 'module' => 'core', 'label' => Terminology::label( 'team' ), 'plural' => Terminology::label( 'team', true ), 'resolve' => array( __CLASS__, 'resolve_member' ), 'search' => array( __CLASS__, 'search_members' ) ) );
	}

	/* ---- link-type helpers ---- */

	private static function one( $table, $col, $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( $table ) . " WHERE id=%d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	private static function find( $table, $col, $q, $n ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, ' . $col . ' AS title FROM ' . Schema::table( $table ) . ' WHERE ' . $col . ' LIKE %s ORDER BY ' . $col . ' ASC LIMIT %d', $like, $n ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( function ( $r ) {
			return array( 'id' => (int) $r['id'], 'title' => $r['title'] );
		}, (array) $rows );
	}

	public static function resolve_client( $id ) {
		$r = self::one( 'bizy_clients', 'name', $id );
		return $r ? array( 'title' => $r['name'], 'url' => '#/clients/' . (int) $id, 'sub' => $r['email'] ) : null;
	}

	public static function search_clients( $q, $n ) {
		return self::find( 'bizy_clients', 'name', $q, $n );
	}

	public static function resolve_department( $id ) {
		$r = self::one( 'bizy_departments', 'name', $id );
		return $r ? array( 'title' => $r['name'], 'url' => '#/departments/' . (int) $id, 'sub' => $r['type'] ) : null;
	}

	public static function search_departments( $q, $n ) {
		return self::find( 'bizy_departments', 'name', $q, $n );
	}

	public static function resolve_member( $id ) {
		$r = self::one( 'bizy_team_members', 'name', $id );
		return $r ? array( 'title' => $r['name'], 'url' => '#/team/' . (int) $id, 'sub' => $r['role'] ) : null;
	}

	public static function search_members( $q, $n ) {
		return self::find( 'bizy_team_members', 'name', $q, $n );
	}
}
