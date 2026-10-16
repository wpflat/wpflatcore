<?php
/**
 * Removes BizyCore data on uninstall, only when "Delete all Bizy data when the plugin is deleted" is on in Settings.
 * The module plugins (BizyTasks, BizyFinance, BizySign) clean up their own tables the same way. Delete the modules first:
 * once BizyCore's settings are gone they keep their data (the safe default).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$settings = get_option( 'bizycore_settings', array() );
if ( empty( $settings['delete_on_uninstall'] ) ) {
	return;
}

// Tables: bizy_* only (the module plugins' bizytasks_*, bizyfinance_* (and the older bizymoney_*), bizysign_* (and the older bizydoc_*) and the old bizydash_* tables are not ours).
foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'bizy_' ) . '%' ) ) as $table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB
}

foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $wpdb->esc_like( 'bizycore_' ) . '%' ) ) as $opt ) { // phpcs:ignore WordPress.DB
	if ( 'bizycore_settings' !== $opt ) {
		delete_option( $opt );
	}
}
$wpdb->query( "DELETE FROM $wpdb->usermeta WHERE meta_key LIKE 'bizycore\\_%'" ); // phpcs:ignore WordPress.DB
wp_clear_scheduled_hook( 'bizycore_daily_tick' );
wp_clear_scheduled_hook( 'bizycore_backup_auto' );

// Roles and capabilities the suite added (the old bizydash_* roles belong to the old plugin).
foreach ( (array) wp_roles()->roles as $slug => $def ) {
	if ( 0 === strpos( $slug, 'bizy_' ) ) {
		remove_role( $slug );
	}
}
foreach ( wp_roles()->role_objects as $role ) {
	foreach ( array_keys( (array) $role->capabilities ) as $cap ) {
		if ( 0 === strpos( $cap, 'bizycore_' ) || 'manage_bizycore' === $cap || 0 === strpos( $cap, 'bizytasks_' ) || 0 === strpos( $cap, 'bizymoney_' ) || 0 === strpos( $cap, 'bizyfinance_' ) || 0 === strpos( $cap, 'bizydoc_' ) || 0 === strpos( $cap, 'bizysign_' ) ) {
			$role->remove_cap( $cap );
		}
	}
}

// Files: everything in the Bizy files folder, but keep saved backups unless asked.
$root = defined( 'BIZYCORE_FILES_DIR' ) && BIZYCORE_FILES_DIR ? rtrim( BIZYCORE_FILES_DIR, '/\\' ) : rtrim( wp_normalize_path( WP_CONTENT_DIR ), '/' ) . '/bizy-files';
$files = get_option( 'bizycore_files_state', array() );
if ( is_array( $files ) && ! empty( $files['root'] ) ) {
	$root = rtrim( wp_normalize_path( $files['root'] ), '/' );
}
$keep_backups = empty( $settings['delete_backups_on_uninstall'] );
if ( is_dir( $root ) && basename( $root ) === 'bizy-files' ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		if ( $keep_backups && false !== strpos( wp_normalize_path( $f->getPathname() ), '/private/backups' ) ) {
			continue;
		}
		$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
	}
	@rmdir( $root ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
}

delete_option( 'bizycore_settings' );
