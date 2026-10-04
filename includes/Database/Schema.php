<?php
namespace BizyCore\Database;

defined( 'ABSPATH' ) || exit;

/** Core's tables. Table names are written in full without the WordPress prefix: bizy_clients, bizy_departments… */
class Schema {

	/** Bump when the SQL below changes. */
	const VERSION = '3';

	/** Full table name for a name that already starts with its plugin prefix (bizy_, bizytasks_, bizymoney_…). */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t       = array();
		foreach ( array( 'departments', 'groups', 'group_members', 'group_roles', 'clients', 'client_departments', 'client_contacts', 'team_members', 'activity', 'lookups', 'threads', 'messages', 'message_files', 'thread_reads', 'notifications' ) as $n ) {
			$t[ $n ] = self::table( 'bizy_' . $n );
		}

		$sql   = array();
		$sql[] = "CREATE TABLE {$t['departments']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  type varchar(40) NOT NULL DEFAULT '',
  description text,
  logo_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $charset;";

		$sql[] = "CREATE TABLE {$t['clients']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  contact_person varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(60) NOT NULL DEFAULT '',
  address text,
  notes text,
  type varchar(40) NOT NULL DEFAULT '',
  status varchar(40) NOT NULL DEFAULT 'active',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY type (type)
) $charset;";

		$sql[] = "CREATE TABLE {$t['groups']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  description text,
  color varchar(9) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'active',
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $charset;";

		$sql[] = "CREATE TABLE {$t['group_members']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  group_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY group_client (group_id,client_id),
  KEY client_id (client_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['group_roles']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  group_id bigint(20) unsigned NOT NULL DEFAULT 0,
  role_slug varchar(60) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY group_role (group_id,role_slug),
  KEY role_slug (role_slug)
) $charset;";

		$sql[] = "CREATE TABLE {$t['client_departments']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  is_primary tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY client_department (client_id,department_id),
  KEY department_id (department_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['client_contacts']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(60) NOT NULL DEFAULT '',
  job_title varchar(190) NOT NULL DEFAULT '',
  kind varchar(20) NOT NULL DEFAULT 'primary',
  sort_order int(11) NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY email (email)
) $charset;";

		// Pay, salary and bank details are not here: BizyMoney keeps them in its own table, linked by team_member_id.
		$sql[] = "CREATE TABLE {$t['team_members']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  role varchar(190) NOT NULL DEFAULT '',
  cc_documents tinyint(1) NOT NULL DEFAULT 1,
  wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(12) NOT NULL DEFAULT 'employee',
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  start_date date DEFAULT NULL,
  end_date date DEFAULT NULL,
  notes text,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY wp_user_id (wp_user_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['activity']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entity_type varchar(40) NOT NULL DEFAULT '',
  entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(40) NOT NULL DEFAULT '',
  summary varchar(500) NOT NULL DEFAULT '',
  meta longtext,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY entity (entity_type,entity_id),
  KEY client_created (client_id,created_at)
) $charset;";

		$sql[] = "CREATE TABLE {$t['lookups']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(40) NOT NULL DEFAULT '',
  item_key varchar(60) NOT NULL DEFAULT '',
  label varchar(190) NOT NULL DEFAULT '',
  color varchar(20) NOT NULL DEFAULT '',
  months int(11) NOT NULL DEFAULT 0,
  sort_order int(11) NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  is_system tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY type_key (type,item_key)
) $charset;";

		$sql[] = "CREATE TABLE {$t['threads']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  context_type varchar(40) NOT NULL DEFAULT '',
  context_id bigint(20) unsigned NOT NULL DEFAULT 0,
  subject varchar(190) NOT NULL DEFAULT '',
  status varchar(12) NOT NULL DEFAULT 'open',
  started_by_type varchar(8) NOT NULL DEFAULT 'client',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  last_message_at datetime DEFAULT NULL,
  last_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
  message_count int(11) NOT NULL DEFAULT 0,
  closed_at datetime DEFAULT NULL,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY client_id (client_id,last_message_at),
  KEY context (context_type,context_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['messages']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  thread_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  sender_id bigint(20) unsigned NOT NULL DEFAULT 0,
  sender_type varchar(8) NOT NULL DEFAULT 'client',
  kind varchar(10) NOT NULL DEFAULT 'text',
  body longtext,
  created_at datetime DEFAULT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY thread_id (thread_id,id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['message_files']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  message_id bigint(20) unsigned NOT NULL DEFAULT 0,
  thread_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  file varchar(80) NOT NULL DEFAULT '',
  name varchar(190) NOT NULL DEFAULT '',
  mime varchar(100) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  sha256 char(64) NOT NULL DEFAULT '',
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY message_id (message_id),
  KEY thread_id (thread_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['thread_reads']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  thread_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  last_read_id bigint(20) unsigned NOT NULL DEFAULT 0,
  read_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY thread_user (thread_id,user_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['notifications']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(40) NOT NULL DEFAULT '',
  audience varchar(8) NOT NULL DEFAULT 'team',
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(190) NOT NULL DEFAULT '',
  body text,
  link varchar(255) NOT NULL DEFAULT '',
  entity_type varchar(30) NOT NULL DEFAULT '',
  entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
  dedupe varchar(80) NOT NULL DEFAULT '',
  visible tinyint(1) NOT NULL DEFAULT 1,
  emailed_at datetime DEFAULT NULL,
  read_at datetime DEFAULT NULL,
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY user_read (user_id,read_at,id),
  KEY dedupe (dedupe,user_id)
) $charset;";

		foreach ( $sql as $q ) {
			dbDelta( $q );
		}
	}
}
