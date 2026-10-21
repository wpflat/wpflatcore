<?php
namespace WPFlatCore\Services;

use WPFlatCore\Database\Schema;
use WPFlatCore\Terminology;

defined( 'ABSPATH' ) || exit;

/**
 * The search box (Ctrl/⌘ K). Core searches clients, team members and departments; each module adds its own records:
 *
 *   add_filter( 'wpflatcore_search', function ( $results, $q ) {
 *       $results[] = array( 'type' => 'invoice', 'kind' => 'Invoice', 'id' => 3, 'label' => 'INV-0003', 'sub' => '…', 'route' => '/billing/invoices/3' );
 *       return $results;
 *   }, 10, 2 );
 */
class Search {

	public static function run( $q ) {
		global $wpdb;
		$q = trim( (string) $q );
		if ( mb_strlen( $q ) < 2 ) {
			return array();
		}
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$out  = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, email FROM ' . Schema::table( 'wpflat_clients' ) . ' WHERE name LIKE %s OR contact_person LIKE %s OR email LIKE %s ORDER BY name LIMIT 8', $like, $like, $like ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[] = array( 'type' => 'client', 'kind' => Terminology::label( 'client' ), 'id' => (int) $r['id'], 'label' => $r['name'], 'sub' => $r['email'], 'route' => '/clients/' . (int) $r['id'] );
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, role FROM ' . Schema::table( 'wpflat_team_members' ) . ' WHERE name LIKE %s OR email LIKE %s ORDER BY name LIMIT 5', $like, $like ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[] = array( 'type' => 'team_member', 'kind' => Terminology::label( 'team' ), 'id' => (int) $r['id'], 'label' => $r['name'], 'sub' => $r['role'], 'route' => '/team/' . (int) $r['id'] );
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, type FROM ' . Schema::table( 'wpflat_departments' ) . ' WHERE name LIKE %s ORDER BY name LIMIT 5', $like ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[] = array( 'type' => 'department', 'kind' => Terminology::label( 'department' ), 'id' => (int) $r['id'], 'label' => $r['name'], 'sub' => $r['type'], 'route' => '/departments/' . (int) $r['id'] );
		}
		return array_values( (array) apply_filters( 'wpflatcore_search', $out, $q ) );
	}
}
