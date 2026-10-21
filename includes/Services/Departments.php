<?php
namespace WPFlatCore\Services;

use WPFlatCore\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The department pages: what belongs to each department. Core knows clients and people; every module adds its own
 * figures with filters, so Core never reads another module's tables:
 *
 *   add_filter( 'wpflatcore_department_card',   fn( $card, $dept_id, $from, $to ) => … );   // one entry per department in the list
 *   add_filter( 'wpflatcore_department_detail', fn( $data, $dept_id, $from, $to ) => … );   // the department's own page
 *
 * A module puts its numbers under its own key, e.g. $card['modules']['money'] = array( 'income' => … ).
 * Department id 0 means "no department".
 */
class Departments {

	private static function range( $from, $to ) {
		$ok = function ( $d ) {
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d ) ? $d : '';
		};
		return array( $ok( $from ), $ok( $to ) );
	}

	private static function clients_of( $dept_id, $limit = 0 ) {
		global $wpdb;
		$c  = Schema::table( 'wpflat_clients' );
		$cd = Schema::table( 'wpflat_client_departments' );
		$lim = $limit ? ' LIMIT ' . (int) $limit : '';
		if ( $dept_id > 0 ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.id, c.name, c.type, c.status FROM $c c JOIN $cd d ON d.client_id=c.id WHERE d.department_id=%d ORDER BY c.name$lim", $dept_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		return (array) $wpdb->get_results( "SELECT c.id, c.name, c.type, c.status FROM $c c WHERE NOT EXISTS (SELECT 1 FROM $cd d WHERE d.client_id=c.id) ORDER BY c.name$lim", ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	private static function people_of( $dept_id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, role, kind FROM ' . Schema::table( 'wpflat_team_members' ) . " WHERE status='active' AND department_id=%d ORDER BY name", $dept_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** The client's primary department (falls back to its first one), or 0. */
	public static function client_primary( $client_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT department_id FROM ' . $wpdb->prefix . 'wpflat_client_departments WHERE client_id=%d ORDER BY is_primary DESC, id ASC LIMIT 1', (int) $client_id ) ); // phpcs:ignore WordPress.DB
	}

	/** Every active department as a card, plus a "no department" card when something has none. */
	public static function all( $from = '', $to = '' ) {
		global $wpdb;
		list( $from, $to ) = self::range( $from, $to );
		$rows  = (array) $wpdb->get_results( 'SELECT id, name, description, type FROM ' . Schema::table( 'wpflat_departments' ) . " WHERE status='active' ORDER BY name", ARRAY_A ); // phpcs:ignore WordPress.DB
		$cards = array();
		foreach ( $rows as $r ) {
			$id      = (int) $r['id'];
			$cards[] = apply_filters(
				'wpflatcore_department_card',
				array( 'id' => $id, 'name' => $r['name'], 'description' => $r['description'], 'type' => $r['type'], 'clients' => count( self::clients_of( $id ) ), 'people' => count( self::people_of( $id ) ), 'modules' => array() ),
				$id,
				$from,
				$to
			);
		}
		$none_clients = count( self::clients_of( 0 ) );
		$none_people  = count( self::people_of( 0 ) );
		if ( $none_clients || $none_people ) {
			$cards[] = apply_filters( 'wpflatcore_department_card', array( 'id' => 0, 'name' => \WPFlatCore\Terminology::fmt( 'No {department}' ), 'description' => '', 'type' => '', 'clients' => $none_clients, 'people' => $none_people, 'modules' => array() ), 0, $from, $to );
		}
		return array( 'departments' => $cards, 'from' => $from, 'to' => $to );
	}

	/** One department's page, or null. */
	public static function one( $id, $from = '', $to = '' ) {
		global $wpdb;
		$id = (int) $id;
		list( $from, $to ) = self::range( $from, $to );
		if ( $id > 0 ) {
			$d = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, description, type FROM ' . Schema::table( 'wpflat_departments' ) . ' WHERE id=%d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( ! $d ) {
				return null;
			}
		} else {
			$d = array( 'id' => 0, 'name' => \WPFlatCore\Terminology::fmt( 'No {department}' ), 'description' => 'Everything that is not in a department yet.', 'type' => '' );
		}
		$data = array( 'id' => (int) $d['id'], 'name' => $d['name'], 'description' => $d['description'], 'type' => $d['type'], 'clients' => self::clients_of( $id ), 'people' => self::people_of( $id ), 'from' => $from, 'to' => $to, 'modules' => array() );
		return apply_filters( 'wpflatcore_department_detail', $data, $id, $from, $to );
	}
}
