<?php
namespace WPFlatCore;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of REST resources. Each entry drives a Repository + Rest\Resource_Controller. Core registers its own
 * (clients, contacts, departments, team, lookups, activity); a module registers its resources from the
 * `wpflatcore_register_resources` action, passing its module id so the registry can switch them off with the module.
 *
 * Resource config keys: route, table (full name without prefix, e.g. 'wpflat_clients'), label, cols, filters, search,
 * order, required, title, before_save, after_save, decorate, before_delete, after_delete, extra, select, join,
 * readonly, log, timestamps, cap (read), write_cap (write).
 */
class Resources {

	/** @var array name => array( module, cfg ) */
	private static $defs = array();

	/** @var Repository[] */
	private static $repos = array();

	public static function register( $name, array $cfg, $module = 'core' ) {
		if ( isset( self::$defs[ $name ] ) ) {
			return new \WP_Error( 'wpflatcore_resource_dup', 'The resource "' . $name . '" is already registered.' );
		}
		if ( empty( $cfg['route'] ) || empty( $cfg['table'] ) ) {
			return new \WP_Error( 'wpflatcore_resource_def', 'A resource needs a route and a table.' );
		}
		self::$defs[ $name ] = array( 'module' => $module, 'cfg' => $cfg );
		unset( self::$repos[ $name ] );
		return true;
	}

	public static function has( $name ) {
		return isset( self::$defs[ $name ] );
	}

	public static function module_of( $name ) {
		return isset( self::$defs[ $name ] ) ? self::$defs[ $name ]['module'] : 'core';
	}

	public static function cfg( $name ) {
		return isset( self::$defs[ $name ] ) ? self::$defs[ $name ]['cfg'] : null;
	}

	public static function repo( $name ) {
		if ( ! isset( self::$defs[ $name ] ) ) {
			return null;
		}
		if ( ! isset( self::$repos[ $name ] ) ) {
			self::$repos[ $name ] = new Repository( $name, self::$defs[ $name ]['cfg'] );
		}
		return self::$repos[ $name ];
	}

	/** Names whose module is switched on. */
	public static function names() {
		$out = array();
		foreach ( self::$defs as $n => $d ) {
			if ( Registry::enabled( $d['module'] ) ) {
				$out[] = $n;
			}
		}
		return $out;
	}
}
