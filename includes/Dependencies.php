<?php
namespace BizyCore;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-language warnings about the suite itself: a module that needs a newer Core, or two copies of one module.
 * (The opposite case — a module with no Core installed — is handled by the small guard inside each module plugin;
 * see docs/module-guard.php.)
 */
class Dependencies {

	public static function notices() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		foreach ( Registry::problems() as $msg ) {
			echo '<div class="notice notice-warning"><p><strong>Bizy:</strong> ' . esc_html( $msg ) . '</p></div>';
		}
	}
}
