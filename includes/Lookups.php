<?php
namespace BizyCore;

use BizyCore\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Configurable option lists (client types, billing periods, billing treatments, ...).
 */
class Lookups {

	/** Core's own lists; modules add theirs through the `bizycore_lookup_defaults` filter (BizyMoney: billing periods, expense categories…). */
	public static function defaults() {
		$core = array(
			'client_type'     => array(
				array( 'retainer', 'Retainer', 0, '#2563eb' ),
				array( 'adhoc', 'Ad-hoc', 0, '#7c3aed' ),
			),
			'client_status'   => array(
				array( 'active', 'Active', 0, '#16a34a' ),
				array( 'paused', 'Paused', 0, '#d97706' ),
				array( 'inactive', 'Inactive', 0, '#6b7280' ),
			),
			'department_type' => array(
				array( 'product', 'Product', 0, '#0891b2' ),
				array( 'service', 'Service', 0, '#db2777' ),
			),
		);
		return apply_filters( 'bizycore_lookup_defaults', $core );
	}

	/** Seed any new defaults once the module list is known (a module may add its own lists); skipped when nothing changed. */
	public static function sync() {
		$hash = md5( wp_json_encode( self::defaults() ) );
		if ( get_option( 'bizycore_lookups_hash' ) === $hash ) {
			return;
		}
		self::seed();
		update_option( 'bizycore_lookups_hash', $hash, false );
	}

	/** Insert any missing system defaults (never overwrites edits). */
	public static function seed() {
		global $wpdb;
		$table = Schema::table( 'bizy_lookups' );
		foreach ( self::defaults() as $type => $items ) {
			foreach ( $items as $i => $it ) {
				$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE type=%s AND item_key=%s", $type, $it[0] ) ); // phpcs:ignore WordPress.DB
				if ( $exists ) {
					continue;
				}
				$wpdb->insert(
					$table,
					array(
						'type'       => $type,
						'item_key'   => $it[0],
						'label'      => $it[1],
						'months'     => $it[2],
						'color'      => $it[3],
						'sort_order' => $i,
						'is_active'  => 1,
						'is_system'  => 1,
					)
				);
			}
		}
	}

	/** All lookups grouped by type. */
	public static function grouped() {
		global $wpdb;
		$table = Schema::table( 'bizy_lookups' );
		$rows  = $wpdb->get_results( "SELECT * FROM $table ORDER BY type ASC, sort_order ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['type'] ][] = array(
				'id'         => (int) $r['id'],
				'key'        => $r['item_key'],
				'label'      => $r['label'],
				'color'      => $r['color'],
				'months'     => (int) $r['months'],
				'sort_order' => (int) $r['sort_order'],
				'is_active'  => (bool) $r['is_active'],
				'is_system'  => (bool) $r['is_system'],
			);
		}
		return $out;
	}

	public static function months_for( $period, $type = 'billing_period' ) {
		global $wpdb;
		$table = Schema::table( 'bizy_lookups' );
		$m     = $wpdb->get_var( $wpdb->prepare( "SELECT months FROM $table WHERE type=%s AND item_key=%s", $type, $period ) ); // phpcs:ignore WordPress.DB
		return (int) $m;
	}
}
