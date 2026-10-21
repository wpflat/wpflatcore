<?php
/**
 * Removes WPFlat Core data on uninstall, only when "Delete all WPFlat data when the plugin is deleted" is on in Settings.
 * The module plugins (WPFlat Tasks, WPFlat Finance, WPFlat Sign) clean up their own tables the same way. Delete the modules first:
 * once WPFlat Core's settings are gone they keep their data (the safe default).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$settings = get_option( 'wpflatcore_settings', array() );
if ( empty( $settings['delete_on_uninstall'] ) ) {
	return;
}

// Tables: wpflat_* only (the module plugins' wpflattasks_*, wpflatfinance_* (and the older bizymoney_*), wpflatsign_* (and the older bizydoc_*) and the old bizydash_* tables are not ours).
foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wpflat_' ) . '%' ) ) as $table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB
}

foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $wpdb->esc_like( 'wpflatcore_' ) . '%' ) ) as $opt ) { // phpcs:ignore WordPress.DB
	if ( 'wpflatcore_settings' !== $opt ) {
		delete_option( $opt );
	}
}
$wpdb->query( "DELETE FROM $wpdb->usermeta WHERE meta_key LIKE 'wpflatcore\\_%'" ); // phpcs:ignore WordPress.DB
wp_clear_scheduled_hook( 'wpflatcore_daily_tick' );
wp_clear_scheduled_hook( 'wpflatcore_backup_auto' );

// Roles and capabilities the suite added (the old bizydash_* roles belong to the old plugin).
foreach ( (array) wp_roles()->roles as $slug => $def ) {
	if ( 0 === strpos( $slug, 'wpflat_' ) ) {
		remove_role( $slug );
	}
}
foreach ( wp_roles()->role_objects as $role ) {
	foreach ( array_keys( (array) $role->capabilities ) as $cap ) {
		if ( 0 === strpos( $cap, 'wpflatcore_' ) || 'manage_wpflatcore' === $cap || 0 === strpos( $cap, 'wpflattasks_' ) || 0 === strpos( $cap, 'bizymoney_' ) || 0 === strpos( $cap, 'wpflatfinance_' ) || 0 === strpos( $cap, 'bizydoc_' ) || 0 === strpos( $cap, 'wpflatsign_' ) ) {
			$role->remove_cap( $cap );
		}
	}
}

// Files: everything in the WPFlat files folder, but keep saved backups unless asked.
$root = defined( 'WPFLATCORE_FILES_DIR' ) && WPFLATCORE_FILES_DIR ? rtrim( WPFLATCORE_FILES_DIR, '/\\' ) : rtrim( wp_normalize_path( WP_CONTENT_DIR ), '/' ) . '/wpflat-files';
$files = get_option( 'wpflatcore_files_state', array() );
if ( is_array( $files ) && ! empty( $files['root'] ) ) {
	$root = rtrim( wp_normalize_path( $files['root'] ), '/' );
}
$keep_backups = empty( $settings['delete_backups_on_uninstall'] );
if ( is_dir( $root ) && basename( $root ) === 'wpflat-files' ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		if ( $keep_backups && false !== strpos( wp_normalize_path( $f->getPathname() ), '/private/backups' ) ) {
			continue;
		}
		$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
	}
	@rmdir( $root ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
}

delete_option( 'wpflatcore_settings' );
