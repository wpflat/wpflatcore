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
}
