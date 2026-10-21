<?php
/**
 * Plugin Name: WPFlat Core
 * Description: The foundation of the WPFlat suite — clients, team, departments, portal and backup, plus the connection points that WPFlat Tasks, WPFlat Finance and WPFlat Sign plug into.
 * Version: 0.8.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Digitalzooks
 * License: GPL-2.0-or-later
 * Text Domain: wpflatcore
 */

defined( 'ABSPATH' ) || exit;

define( 'WPFLATCORE_VERSION', '0.8.0' );
define( 'WPFLATCORE_FILE', __FILE__ );
define( 'WPFLATCORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPFLATCORE_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	function ( $class ) {
		$prefix = 'WPFlatCore\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$path = WPFLATCORE_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

// Sites that ran the suite under its old name (Bizy) are moved to the new names before anything reads the data.
WPFlatCore\Migrate_Bizy::init();

/**
 * Public API for module plugins. Call it from inside the `wpflatcore_register` action (see Registry for the keys).
 *
 * @param array $def Module definition.
 * @return true|WP_Error
 */
function wpflatcore_register_module( array $def ) {
	return WPFlatCore\Registry::register( $def );
}

/** A customer-facing word, honouring the owner's choice (Clients → Customers, Students…). */
function wpflatcore_t( $key, $plural = false ) {
	return WPFlatCore\Terminology::label( $key, $plural );
}

/** Replace {client}, {Client}, {clients}, {Clients} … in a sentence with the chosen words. */
function wpflatcore_fmt( $text ) {
	return WPFlatCore\Terminology::fmt( $text );
}

register_activation_hook( __FILE__, array( 'WPFlatCore\\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPFlatCore\\Cron', 'unschedule' ) );

add_action( 'plugins_loaded', array( 'WPFlatCore\\Plugin', 'boot' ), 5 );
