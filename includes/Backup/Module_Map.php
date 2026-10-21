<?php
namespace WPFlatCore\Backup;

use WPFlatCore\Database\Schema;
use WPFlatCore\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * What belongs to which module, in parent-first order. Core is fixed; every installed module describes its own
 * tables, options, files and links in its registry entry, so export, restore and the "what's inside" report agree
 * and a backup always covers exactly what is installed.
 */
class Module_Map {

	const CORE_TABLES = array( 'wpflat_departments', 'wpflat_lookups', 'wpflat_clients', 'wpflat_groups', 'wpflat_group_members', 'wpflat_group_roles', 'wpflat_client_departments', 'wpflat_client_contacts', 'wpflat_team_members', 'wpflat_activity', 'wpflat_threads', 'wpflat_messages', 'wpflat_message_files', 'wpflat_thread_reads', 'wpflat_links' );

	/** Tables per module (without the WordPress prefix). `optional` ones can be left out; `excluded` ones are never saved. */
	public static function modules() {
		$out = array(
			'core' => array(
				'label'    => 'Clients, departments & team',
				'desc'     => 'Departments, clients and their contacts, team members, client messages and files, links between records, lists & options, and your settings.',
				'tables'   => self::CORE_TABLES,
				'optional' => array( 'wpflat_activity' ),
				'excluded' => array( 'wpflat_notifications' ),
			),
		);
		foreach ( Registry::all() as $id => $m ) {
			$out[ $id ] = array(
				'label'    => $m['label'],
				'desc'     => $m['desc'],
				'tables'   => (array) $m['tables'],
				'optional' => (array) $m['optional_tables'],
				'excluded' => (array) $m['excluded_tables'],
			);
		}
		return $out;
	}

	/** Every table the suite owns, backed up or not. */
	public static function all_tables() {
		$out = array();
		foreach ( self::modules() as $m ) {
			$out = array_merge( $out, $m['tables'], $m['excluded'] );
		}
		return $out;
	}

	public static function tables_of( $module ) {
		$m = self::modules();
		return isset( $m[ $module ] ) ? $m[ $module ]['tables'] : array();
	}

	public static function module_of( $table ) {
		foreach ( self::modules() as $id => $m ) {
			if ( in_array( $table, $m['tables'], true ) || in_array( $table, $m['excluded'], true ) ) {
				return $id;
			}
		}
		return '';
	}

	/** Option name => owning module. The SMTP password in `wpflatcore_mail` is stripped on export. */
	public static function options() {
		$out = array( 'wpflatcore_settings' => 'core', 'wpflatcore_mail' => 'core', 'wpflatcore_modules_off' => 'core', 'wpflatcore_terms' => 'core', 'wpflatcore_team_invites' => 'core' );
		foreach ( Registry::all() as $id => $m ) {
			foreach ( (array) $m['options'] as $o ) {
				$out[ $o ] = $id;
			}
		}
		return $out;
	}

	/** Keys of the `wpflatcore_settings` option that a module owns (Core owns the rest). */
	public static function settings_keys( $module ) {
		$m = Registry::get( $module );
		return $m ? (array) $m['settings_keys'] : array();
	}

	/** Columns that hold WordPress user ids, so a restore can map them to this site's users. */
	public static function user_columns() {
		$cols = array( 'created_by', 'user_id', 'wp_user_id', 'sender_id' );
		foreach ( Registry::all() as $m ) {
			$cols = array_merge( $cols, (array) $m['user_columns'] );
		}
		return array_values( array_unique( $cols ) );
	}

	/** Columns that hold media-library attachment ids: table => columns. */
	public static function attachment_columns() {
		$out = array( 'wpflat_departments' => array( 'logo_attachment_id' ) );
		foreach ( Registry::all() as $m ) {
			foreach ( (array) $m['attachment_columns'] as $t => $cols ) {
				$out[ $t ] = array_merge( isset( $out[ $t ] ) ? $out[ $t ] : array(), (array) $cols );
			}
		}
		return $out;
	}

	/** Child table => array( column => parent table ), used to spot orphaned rows after a partial restore. */
	public static function refs() {
		$out = array(
			'wpflat_client_departments' => array( 'client_id' => 'wpflat_clients', 'department_id' => 'wpflat_departments' ),
			'wpflat_group_members'      => array( 'group_id' => 'wpflat_groups', 'client_id' => 'wpflat_clients' ),
			'wpflat_group_roles'        => array( 'group_id' => 'wpflat_groups' ),
			'wpflat_client_contacts'    => array( 'client_id' => 'wpflat_clients' ),
			'wpflat_threads'            => array( 'client_id' => 'wpflat_clients' ),
			'wpflat_messages'           => array( 'thread_id' => 'wpflat_threads' ),
			'wpflat_message_files'      => array( 'message_id' => 'wpflat_messages' ),
			'wpflat_thread_reads'       => array( 'thread_id' => 'wpflat_threads' ),
		);
		foreach ( Registry::all() as $m ) {
			foreach ( (array) $m['refs'] as $child => $edges ) {
				$out[ $child ] = $edges;
			}
		}
		return $out;
	}

	/** Private file folders a backup carries: Core's messages plus each module's. */
	public static function file_kinds() {
		$out = array( 'messages' => 'core' );
		foreach ( Registry::all() as $id => $m ) {
			foreach ( (array) $m['file_kinds'] as $k ) {
				$out[ $k ] = $id;
			}
		}
		return $out;
	}

	public static function table( $name ) {
		return Schema::table( $name );
	}
}
