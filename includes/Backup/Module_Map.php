<?php
namespace BizyCore\Backup;

use BizyCore\Database\Schema;
use BizyCore\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * What belongs to which module, in parent-first order. Core is fixed; every installed module describes its own
 * tables, options, files and links in its registry entry, so export, restore and the "what's inside" report agree
 * and a backup always covers exactly what is installed.
 */
class Module_Map {

	const CORE_TABLES = array( 'bizy_departments', 'bizy_lookups', 'bizy_clients', 'bizy_client_departments', 'bizy_client_contacts', 'bizy_team_members', 'bizy_activity', 'bizy_threads', 'bizy_messages', 'bizy_message_files', 'bizy_thread_reads', 'bizy_links' );

	/** Tables per module (without the WordPress prefix). `optional` ones can be left out; `excluded` ones are never saved. */
	public static function modules() {
		$out = array(
			'core' => array(
				'label'    => 'Clients, departments & team',
				'desc'     => 'Departments, clients and their contacts, team members, client messages and files, links between records, lists & options, and your settings.',
				'tables'   => self::CORE_TABLES,
				'optional' => array( 'bizy_activity' ),
				'excluded' => array( 'bizy_notifications' ),
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

	/** Option name => owning module. The SMTP password in `bizycore_mail` is stripped on export. */
	public static function options() {
		$out = array( 'bizycore_settings' => 'core', 'bizycore_mail' => 'core', 'bizycore_modules_off' => 'core', 'bizycore_terms' => 'core' );
		foreach ( Registry::all() as $id => $m ) {
			foreach ( (array) $m['options'] as $o ) {
				$out[ $o ] = $id;
			}
		}
		return $out;
	}

	/** Keys of the `bizycore_settings` option that a module owns (Core owns the rest). */
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
		$out = array( 'bizy_departments' => array( 'logo_attachment_id' ) );
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
			'bizy_client_departments' => array( 'client_id' => 'bizy_clients', 'department_id' => 'bizy_departments' ),
			'bizy_client_contacts'    => array( 'client_id' => 'bizy_clients' ),
			'bizy_threads'            => array( 'client_id' => 'bizy_clients' ),
			'bizy_messages'           => array( 'thread_id' => 'bizy_threads' ),
			'bizy_message_files'      => array( 'message_id' => 'bizy_messages' ),
			'bizy_thread_reads'       => array( 'thread_id' => 'bizy_threads' ),
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
