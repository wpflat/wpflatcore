<?php
namespace BizyCore\Services;

use BizyCore\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Groups of clients (classes, cohorts, houses… — the word is renamable). Modules use groups to decide who may see
 * something: BizyFiles gives a folder to a group, and every client in it can open it.
 */
class Groups {

	public static function t() {
		return Schema::table( 'bizy_group_members' );
	}

	/** Group ids a client belongs to. */
	public static function for_client( $client_id ) {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT group_id FROM ' . self::t() . ' WHERE client_id=%d', (int) $client_id ) ) ); // phpcs:ignore WordPress.DB
	}

	/** Group ids for several clients at once. */
	public static function for_clients( array $client_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $client_ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		return array_map( 'intval', (array) $wpdb->get_col( 'SELECT DISTINCT group_id FROM ' . self::t() . ' WHERE client_id IN (' . implode( ',', $ids ) . ')' ) ); // phpcs:ignore WordPress.DB
	}

	/** Client ids in a group. */
	public static function members( $group_id ) {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT client_id FROM ' . self::t() . ' WHERE group_id=%d', (int) $group_id ) ) ); // phpcs:ignore WordPress.DB
	}

	/** Make a client's groups exactly this list. */
	public static function sync_client( $client_id, array $group_ids ) {
		global $wpdb;
		$client_id = (int) $client_id;
		$want      = array_values( array_unique( array_filter( array_map( 'intval', $group_ids ) ) ) );
		$have      = self::for_client( $client_id );
		foreach ( array_diff( $have, $want ) as $gid ) {
			$wpdb->delete( self::t(), array( 'group_id' => $gid, 'client_id' => $client_id ) );
		}
		foreach ( array_diff( $want, $have ) as $gid ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'bizy_groups' ) . ' WHERE id=%d', $gid ) ) ) { // phpcs:ignore WordPress.DB
				$wpdb->insert( self::t(), array( 'group_id' => $gid, 'client_id' => $client_id, 'created_at' => current_time( 'mysql' ) ) );
			}
		}
		do_action( 'bizycore_groups_changed', 'client', $client_id );
	}

	/** Make a group's members exactly this list of client ids. */
	public static function sync_group( $group_id, array $client_ids ) {
		global $wpdb;
		$group_id = (int) $group_id;
		$want     = array_values( array_unique( array_filter( array_map( 'intval', $client_ids ) ) ) );
		$have     = self::members( $group_id );
		foreach ( array_diff( $have, $want ) as $cid ) {
			$wpdb->delete( self::t(), array( 'group_id' => $group_id, 'client_id' => $cid ) );
		}
		foreach ( array_diff( $want, $have ) as $cid ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'bizy_clients' ) . ' WHERE id=%d', $cid ) ) ) { // phpcs:ignore WordPress.DB
				$wpdb->insert( self::t(), array( 'group_id' => $group_id, 'client_id' => $cid, 'created_at' => current_time( 'mysql' ) ) );
			}
		}
		do_action( 'bizycore_groups_changed', 'group', $group_id );
	}

	public static function add_members( $group_id, array $client_ids ) {
		self::sync_group( $group_id, array_merge( self::members( $group_id ), $client_ids ) );
	}

	public static function remove_members( $group_id, array $client_ids ) {
		self::sync_group( $group_id, array_diff( self::members( $group_id ), array_map( 'intval', $client_ids ) ) );
	}

	/** Remove a client from every group (the client was deleted). */
	public static function forget_client( $client_id ) {
		global $wpdb;
		$wpdb->delete( self::t(), array( 'client_id' => (int) $client_id ) );
	}

	/* ───────────────────────── Roles (WordPress roles tied to a group) ───────────────────────── */

	public static function rt() {
		return Schema::table( 'bizy_group_roles' );
	}

	/** Role slugs linked to a group. */
	public static function roles( $group_id ) {
		global $wpdb;
		return array_values( array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT role_slug FROM ' . self::rt() . ' WHERE group_id=%d ORDER BY role_slug', (int) $group_id ) ) ) ); // phpcs:ignore WordPress.DB
	}

	/** A role as the owner knows it: slug, display name, and whether it still exists (a role-editor plugin may have removed it). */
	public static function role_info( $slug ) {
		$names = wp_roles()->get_names();
		return array( 'slug' => $slug, 'label' => isset( $names[ $slug ] ) ? translate_user_role( $names[ $slug ] ) : $slug, 'missing' => ! isset( $names[ $slug ] ) );
	}

	/** Make a group's linked roles exactly this list (administrator can't be linked: it would make the group everyone with full rights). */
	public static function set_roles( $group_id, array $slugs ) {
		global $wpdb;
		$group_id = (int) $group_id;
		$want     = array();
		foreach ( $slugs as $r ) {
			$r = sanitize_key( (string) $r );
			if ( '' !== $r && 'administrator' !== $r ) {
				$want[ $r ] = $r;
			}
		}
		$want = array_values( $want );
		$have = self::roles( $group_id );
		foreach ( array_diff( $have, $want ) as $r ) {
			$wpdb->delete( self::rt(), array( 'group_id' => $group_id, 'role_slug' => $r ) );
		}
		foreach ( array_diff( $want, $have ) as $r ) {
			$wpdb->insert( self::rt(), array( 'group_id' => $group_id, 'role_slug' => $r ) );
		}
		do_action( 'bizycore_groups_changed', 'roles', $group_id );
	}

	/** Group ids a WordPress user is in through the roles they hold right now. */
	public static function for_user( $user_id ) {
		global $wpdb;
		$u = get_userdata( (int) $user_id );
		if ( ! $u || ! $u->roles ) {
			return array();
		}
		$in = implode( ',', array_map( function ( $r ) {
			return "'" . esc_sql( $r ) . "'";
		}, array_values( (array) $u->roles ) ) );
		return array_map( 'intval', (array) $wpdb->get_col( 'SELECT DISTINCT group_id FROM ' . self::rt() . " WHERE role_slug IN ($in)" ) ); // phpcs:ignore WordPress.DB
	}

	/** User ids that currently hold a role linked to the group. */
	public static function member_users( $group_id ) {
		$ids = array();
		foreach ( self::roles( $group_id ) as $r ) {
			foreach ( get_users( array( 'role' => $r, 'fields' => 'ID' ) ) as $uid ) {
				$ids[ (int) $uid ] = (int) $uid;
			}
		}
		return array_values( $ids );
	}

	/** Number of users who hold each role (for the role picker). */
	public static function role_user_counts() {
		$c = count_users();
		return isset( $c['avail_roles'] ) ? $c['avail_roles'] : array();
	}

	/** Roles a group can be linked to. */
	public static function assignable_roles() {
		$counts = self::role_user_counts();
		$out    = array();
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			if ( 'administrator' === $slug ) {
				continue;
			}
			$out[] = array( 'slug' => $slug, 'label' => translate_user_role( $name ), 'users' => isset( $counts[ $slug ] ) ? (int) $counts[ $slug ] : 0 );
		}
		return $out;
	}

	/** Remove every role link of a group (it was deleted). */
	public static function forget_roles( $group_id ) {
		global $wpdb;
		$wpdb->delete( self::rt(), array( 'group_id' => (int) $group_id ) );
	}
}
