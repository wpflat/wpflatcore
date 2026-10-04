<?php
namespace BizyCore\Services;

use BizyCore\Database\Schema;
use BizyCore\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Data for the Home screen. Core supplies the basics (how many clients and team members, a short setup checklist);
 * modules add what they know with the `bizycore_home` filter:
 *
 *   add_filter( 'bizycore_home', function ( $home ) {
 *       $home['todo'][]      = array( 'kind' => 'task', 'title' => '…', 'sub' => '…', 'link' => '/tasks/3', 'days' => 0 );
 *       $home['coming_up'][] = array( 'kind' => 'invoice', 'title' => '…', 'sub' => '…', 'link' => '…', 'days' => 4 );
 *       $home['setup'][]     = array( 'key' => 'catalog', 'label' => 'Add what you sell', 'to' => '/catalog', 'done' => false );
 *       return $home;
 *   } );
 */
class Home {

	public static function data() {
		global $wpdb;
		$count = function ( $table, $where = '1=1' ) use ( $wpdb ) {
			return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $table ) . ' WHERE ' . $where ); // phpcs:ignore WordPress.DB
		};
		$home = array(
			'stats'      => array(
				'clients'     => $count( 'bizy_clients', "status='active'" ),
				'team'        => $count( 'bizy_team_members', "status='active'" ),
				'departments' => $count( 'bizy_departments', "status='active'" ),
			),
			'todo'       => array(),
			'coming_up'  => array(),
			'setup'      => array(
				array( 'key' => 'profile', 'label' => 'Add your business details and logo', 'to' => '/settings?tab=business', 'done' => '' !== trim( (string) Settings::get( 'company_name' ) ) || (int) Settings::get( 'business_logo_id' ) > 0 ),
				array( 'key' => 'client', 'label' => \BizyCore\Terminology::fmt( 'Add your first {client}' ), 'to' => '/clients', 'done' => $count( 'bizy_clients' ) > 0 ),
				array( 'key' => 'team', 'label' => 'Add the people who work with you', 'to' => '/team', 'done' => $count( 'bizy_team_members' ) > 0 ),
			),
		);
		$home = apply_filters( 'bizycore_home', $home );
		usort( $home['todo'], function ( $a, $b ) {
			return ( isset( $a['days'] ) ? $a['days'] : 0 ) <=> ( isset( $b['days'] ) ? $b['days'] : 0 );
		} );
		usort( $home['coming_up'], function ( $a, $b ) {
			return ( isset( $a['days'] ) ? $a['days'] : 9999 ) <=> ( isset( $b['days'] ) ? $b['days'] : 9999 );
		} );
		$home['todo_total'] = count( $home['todo'] );
		$home['todo']       = array_slice( $home['todo'], 0, 12 );
		$home['coming_up']  = array_slice( $home['coming_up'], 0, 20 );
		$home['setup_done'] = count( array_filter( wp_list_pluck( $home['setup'], 'done' ) ) ) === count( $home['setup'] );
		return $home;
	}
}
