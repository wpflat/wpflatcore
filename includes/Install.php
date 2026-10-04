<?php
namespace BizyCore;

defined( 'ABSPATH' ) || exit;

class Install {

	/** Bump when the SQL changes. */
	const DB_VERSION = '5';
	const OPTION     = 'bizycore_db_version';

	public static function activate() {
		if ( false === get_option( self::OPTION ) ) {
			update_option( 'bizycore_fresh_install', 1, false ); // a brand-new install: Home offers to restore a backup
		}
		self::install_tables();
	}

	/** Cheap check on every request so an updated plugin upgrades itself even without re-activation. */
	public static function maybe_upgrade() {
		if ( get_option( self::OPTION ) !== self::DB_VERSION ) {
			self::install_tables();
		}
	}

	public static function install_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		Database\Schema::install();
		$charset = $wpdb->get_charset_collate();
		$links   = Links::table();

		dbDelta(
			"CREATE TABLE {$links} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  from_type varchar(40) NOT NULL DEFAULT '',
  from_id bigint(20) unsigned NOT NULL DEFAULT 0,
  to_type varchar(40) NOT NULL DEFAULT '',
  to_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(40) NOT NULL DEFAULT 'related',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY pair (from_type,from_id,to_type,to_id,kind),
  KEY to_ref (to_type,to_id)
) {$charset};"
		);
		update_option( self::OPTION, self::DB_VERSION, false );
	}
}
