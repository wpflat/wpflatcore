<?php
/**
 * Plugin Name: BizyCore
 * Description: The foundation of the Bizy suite — clients, team, departments, portal and backup, plus the connection points that BizyTasks, BizyFinance and BizySign plug into.
 * Version: 0.3.3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Digitalzooks
 * License: GPL-2.0-or-later
 * Text Domain: bizycore
 */

defined( 'ABSPATH' ) || exit;

define( 'BIZYCORE_VERSION', '0.3.3' );
define( 'BIZYCORE_FILE', __FILE__ );
define( 'BIZYCORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'BIZYCORE_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	function ( $class ) {
		$prefix = 'BizyCore\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$path = BIZYCORE_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Public API for module plugins. Call it from inside the `bizycore_register` action (see Registry for the keys).
 *
 * @param array $def Module definition.
 * @return true|WP_Error
 */
function bizycore_register_module( array $def ) {
	return BizyCore\Registry::register( $def );
}

/** A customer-facing word, honouring the owner's choice (Clients → Customers, Students…). */
function bizycore_t( $key, $plural = false ) {
	return BizyCore\Terminology::label( $key, $plural );
}

/** Replace {client}, {Client}, {clients}, {Clients} … in a sentence with the chosen words. */
function bizycore_fmt( $text ) {
	return BizyCore\Terminology::fmt( $text );
}

register_activation_hook( __FILE__, array( 'BizyCore\\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BizyCore\\Cron', 'unschedule' ) );

add_action( 'plugins_loaded', array( 'BizyCore\\Plugin', 'boot' ), 5 );
