<?php
namespace WPFlatCore\Migration;

use WPFlatCore\Backup\Crypto as Backup_Crypto;
use WPFlatCore\Database\Schema;
use WPFlatCore\Media;
use WPFlatCore\Paths;
use WPFlatCore\Registry;
use WPFlatCore\Services\Crypto;
use WPFlatCore\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Moving from the single BizyDash plugin to the WPFlat suite, from a BizyDash backup (.bizydash).
 *
 * The backup is adopted into a private folder and kept there. Core imports its own tables (clients, contacts,
 * departments, team, conversations, activity, lists) keeping every id, so what the modules import later lines up.
 * Each module then imports its part from the same archive when it is installed:
 *
 *   add_filter( 'wpflatcore_legacy_import_finance', function ( $report, $reader, $legacy ) {
 *       foreach ( $reader->rows( 'invoices' ) as $row ) { … }
 *       return array( 'invoices' => 197 );
 *   }, 10, 3 );
 *
 * Nothing in the old tables or old plugin is touched, so the old site keeps working until the owner switches over.
 */
class Legacy {

	const OPTION = 'wpflatcore_legacy';

	/** Which new plugin takes each old table. */
	const OWNER = array(
		'brands' => 'core', 'lookups' => 'core', 'clients' => 'core', 'client_brands' => 'core', 'client_contacts' => 'core', 'team_members' => 'core', 'activity' => 'core',
		'threads' => 'core', 'messages' => 'core', 'message_files' => 'core', 'thread_reads' => 'core',
		'projects' => 'tasks', 'project_phases' => 'tasks', 'tasks' => 'tasks',
		'services' => 'finance', 'client_services' => 'finance', 'renewals' => 'finance', 'charges' => 'finance', 'invoices' => 'finance', 'invoice_items' => 'finance', 'invoice_installments' => 'finance', 'payments' => 'finance', 'expenses' => 'finance', 'recurring_expenses' => 'finance', 'pay_allocations' => 'finance', 'pay_items' => 'finance', 'payouts' => 'finance', 'email_log' => 'finance',
		'contract_templates' => 'sign', 'contracts' => 'sign', 'contract_signers' => 'sign', 'contract_events' => 'sign',
	);

	/** Core's tables in the order they are imported (parents first). */
	const CORE_ORDER = array( 'brands', 'lookups', 'clients', 'client_brands', 'client_contacts', 'team_members', 'activity', 'threads', 'messages', 'message_files', 'thread_reads' );

	/** Old lookup lists that Core keeps (renamed where needed); the rest belong to modules. */
	const CORE_LOOKUPS = array( 'client_type' => 'client_type', 'client_status' => 'client_status', 'brand_type' => 'department_type' );

	/** Old role => new role. Old roles stay (the old plugin may still be running); the new ones are added. */
	const ROLES = array(
		'bizydash_client' => 'wpflat_user', 'bizydash_client_manager' => 'wpflat_client_manager', 'bizydash_team_manager' => 'wpflat_team_manager', 'bizydash_admin' => 'wpflat_admin',
		'bizydash_work_team' => 'wpflat_tasks_team', 'bizydash_finance_team' => 'wpflat_finance_team', 'bizydash_contract_team' => 'wpflat_sign_team', 'bizydash_staff' => 'wpflat_client_manager',
	);

	/** Old module id => new module id. */
	const MODULES = array( 'work' => 'tasks', 'finance' => 'finance', 'contracts' => 'sign' );

	const BATCH = 200;

	/* ───────────────────────── Finding and adopting a backup ───────────────────────── */

	private static function dir() {
		return Paths::dir( 'migration' );
	}

	public static function archive_path() {
		return self::dir() . '/legacy.bizydash';
	}

	/** Backups of the old plugin lying around on this site. */
	public static function candidates() {
		$dirs = array_unique( array(
			rtrim( wp_normalize_path( WP_CONTENT_DIR ), '/' ) . '/bd-files/private/backups',
			rtrim( wp_normalize_path( dirname( rtrim( ABSPATH, '/' ) ) ), '/' ) . '/bd-files/private/backups',
			trailingslashit( wp_normalize_path( wp_upload_dir()['basedir'] ) ) . 'bizydash-backups',
		) );
		$out = array();
		foreach ( $dirs as $d ) {
			foreach ( (array) glob( $d . '/*.bizydash' ) as $f ) {
				$enc = Backup_Crypto::is_encrypted( $f );
				$row = array( 'file' => $f, 'name' => basename( $f ), 'size' => (int) filesize( $f ), 'modified' => gmdate( 'Y-m-d H:i:s', (int) filemtime( $f ) ), 'encrypted' => $enc, 'created_at' => '', 'plugin_version' => '', 'rows' => 0 );
				if ( ! $enc && ! is_wp_error( $r = Reader::open( $f ) ) ) {
					$row['created_at']     = isset( $r->manifest['created_at'] ) ? $r->manifest['created_at'] : '';
					$row['plugin_version'] = isset( $r->manifest['plugin_version'] ) ? $r->manifest['plugin_version'] : '';
					$row['rows']           = array_sum( $r->tables() );
					$r->close();
				}
				$out[] = $row;
			}
		}
		usort( $out, function ( $a, $b ) {
			return strcmp( $b['modified'], $a['modified'] );
		} );
		return $out;
	}

	/** Only files in a known backup folder may be adopted by path (never an arbitrary path from the browser). */
	private static function allowed_source( $file ) {
		foreach ( self::candidates() as $c ) {
			if ( wp_normalize_path( $c['file'] ) === wp_normalize_path( $file ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Make a backup the one this migration reads: copy (and unlock) it into the private migration folder.
	 *
	 * @param string $source   A candidate's file path, or an uploaded temporary file when $uploaded is true.
	 * @return array|\WP_Error the analysis
	 */
	public static function adopt( $source, $password = '', $uploaded = false ) {
		if ( ! $uploaded && ! self::allowed_source( $source ) ) {
			return new \WP_Error( 'wpflatcore_source', 'That backup was not found.', array( 'status' => 404 ) );
		}
		if ( ! is_readable( $source ) ) {
			return new \WP_Error( 'wpflatcore_source', 'That backup could not be read.', array( 'status' => 404 ) );
		}
		$dest = self::archive_path();
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( Backup_Crypto::is_encrypted( $source ) ) {
			if ( '' === (string) $password ) {
				return new \WP_Error( 'wpflatcore_password_required', 'This backup is password protected. Enter its password.', array( 'status' => 400 ) );
			}
			$r = Backup_Crypto::decrypt_file( $source, $dest, $password );
			if ( is_wp_error( $r ) ) {
				@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				$r->add_data( array( 'status' => 400 ) );
				return $r;
			}
		} elseif ( ! copy( $source, $dest ) ) {
			return new \WP_Error( 'wpflatcore_io', 'The backup could not be copied into the private folder.', array( 'status' => 500 ) );
		}
		$reader = Reader::open( $dest );
		if ( is_wp_error( $reader ) ) {
			@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			$reader->add_data( array( 'status' => 400 ) );
			return $reader;
		}
		$reader->close();
		self::save( array( 'adopted_at' => gmdate( 'Y-m-d H:i:s' ), 'phase' => 'idle', 'core_done' => false, 'modules' => array(), 'attachments' => array(), 'rest_settings' => array(), 'rest_options' => array(), 'rest_lookups' => array(), 'report' => array(), 'cursor' => array(), 'replace' => false ) );
		return self::analysis();
	}

	/* ───────────────────────── State ───────────────────────── */

	public static function state() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	private static function save( array $s ) {
		update_option( self::OPTION, $s, false );
	}

	public static function adopted() {
		return is_readable( self::archive_path() ) && ! empty( self::state()['adopted_at'] );
	}

	/** @var Reader|null */
	private static $reader = null;

	public static function reader() {
		if ( self::$reader ) {
			return self::$reader;
		}
		if ( ! self::adopted() ) {
			return null;
		}
		$r = Reader::open( self::archive_path() );
		self::$reader = is_wp_error( $r ) ? null : $r;
		return self::$reader;
	}

	public static function forget_reader() {
		if ( self::$reader ) {
			self::$reader->close();
			self::$reader = null;
		}
	}

	/** Remove the adopted copy and everything remembered about it. */
	public static function discard() {
		self::forget_reader();
		@unlink( self::archive_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		delete_option( self::OPTION );
		return true;
	}

	/* ───────────────────────── What is in the backup ───────────────────────── */

	/** @return array|\WP_Error */
	public static function analysis() {
		$r = self::reader();
		if ( ! $r ) {
			return new \WP_Error( 'wpflatcore_none', 'No backup has been chosen yet.', array( 'status' => 400 ) );
		}
		$st      = self::state();
		$tables  = array();
		$modules = array();
		foreach ( $r->tables() as $t => $n ) {
			$owner = isset( self::OWNER[ $t ] ) ? self::OWNER[ $t ] : 'ignored';
			if ( 'ignored' === $owner && 0 === $n ) {
				continue;
			}
			$tables[] = array( 'table' => $t, 'rows' => $n, 'owner' => $owner );
			if ( $n > 0 ) {
				$modules[ $owner ] = ( isset( $modules[ $owner ] ) ? $modules[ $owner ] : 0 ) + $n;
			}
		}
		$owners = array();
		foreach ( $modules as $m => $rows ) {
			if ( 'core' === $m || 'ignored' === $m ) {
				continue;
			}
			$owners[] = array(
				'module'    => $m,
				'rows'      => $rows,
				'installed' => Registry::installed( $m ),
				'ready'     => Registry::enabled( $m ) && has_filter( 'wpflatcore_legacy_import_' . $m ),
				'done'      => ! empty( $st['modules'][ $m ]['done'] ),
				'report'    => isset( $st['modules'][ $m ]['report'] ) ? $st['modules'][ $m ]['report'] : array(),
			);
		}
		return array(
			'created_at'     => isset( $r->manifest['created_at'] ) ? $r->manifest['created_at'] : '',
			'site_url'       => isset( $r->manifest['site_url'] ) ? $r->manifest['site_url'] : '',
			'plugin_version' => isset( $r->manifest['plugin_version'] ) ? $r->manifest['plugin_version'] : '',
			'same_site'      => isset( $r->manifest['site_url'] ) && untrailingslashit( $r->manifest['site_url'] ) === untrailingslashit( home_url() ),
			'tables'         => $tables,
			'core_rows'      => isset( $modules['core'] ) ? $modules['core'] : 0,
			'modules'        => $owners,
			'core_done'      => ! empty( $st['core_done'] ),
			'phase'          => isset( $st['phase'] ) ? $st['phase'] : 'idle',
			'report'         => isset( $st['report'] ) ? $st['report'] : array(),
			'target_empty'   => self::core_empty(),
		);
	}

	private static function core_tables() {
		return array( 'wpflat_departments', 'wpflat_clients', 'wpflat_client_departments', 'wpflat_client_contacts', 'wpflat_team_members', 'wpflat_activity', 'wpflat_threads', 'wpflat_messages', 'wpflat_message_files', 'wpflat_thread_reads' );
	}

	/** Has anything been entered in the new suite yet? */
	public static function core_empty() {
		global $wpdb;
		foreach ( array( 'wpflat_clients', 'wpflat_team_members', 'wpflat_departments', 'wpflat_threads' ) as $t ) {
			if ( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $t ) ) > 0 ) { // phpcs:ignore WordPress.DB
				return false;
			}
		}
		return true;
	}

	/* ───────────────────────── Importing Core's part ───────────────────────── */

	/**
	 * Do some of the work and return the progress. Call again until `done`. $replace empties Core's tables first
	 * (needed if anything was already entered in the new suite).
	 *
	 * @return array|\WP_Error
	 */
	public static function step( $replace = false, $budget = 8 ) {
		$r = self::reader();
		if ( ! $r ) {
			return new \WP_Error( 'wpflatcore_none', 'No backup has been chosen yet.', array( 'status' => 400 ) );
		}
		$st = self::state();
		if ( ! empty( $st['core_done'] ) ) {
			return array( 'done' => true, 'phase' => 'done', 'percent' => 100, 'report' => $st['report'] );
		}
		$deadline = microtime( true ) + max( 2, (int) $budget );
		if ( 'idle' === $st['phase'] ) {
			if ( ! self::core_empty() && ! $replace && empty( $st['replace'] ) ) {
				return new \WP_Error( 'wpflatcore_not_empty', 'The new suite already has data. Importing replaces it. Confirm to continue.', array( 'status' => 409 ) );
			}
			$st['replace'] = (bool) $replace || ! empty( $st['replace'] );
			$st['phase']   = 'files';
			$st['cursor']  = array();
			$st['report']  = array();
			\WPFlatCore\Lookups::sync();
		}
		while ( microtime( true ) < $deadline && 'done' !== $st['phase'] ) {
			$method = 'p_' . $st['phase'];
			$st     = self::$method( $r, $st, $deadline );
			if ( is_wp_error( $st ) ) {
				return $st;
			}
		}
		self::save( $st );
		self::$att = null;
		return array( 'done' => 'done' === $st['phase'], 'phase' => $st['phase'], 'percent' => 'done' === $st['phase'] ? 100 : self::percent( $st ), 'report' => $st['report'] );
	}

	private static function percent( array $st ) {
		$order = array( 'idle' => 0, 'files' => 10, 'attachments' => 25, 'tables' => 40, 'options' => 90, 'finish' => 95, 'done' => 100 );
		$base  = isset( $order[ $st['phase'] ] ) ? $order[ $st['phase'] ] : 0;
		if ( 'tables' === $st['phase'] && ! empty( $st['cursor']['i'] ) ) {
			$base += (int) ( 50 * $st['cursor']['i'] / count( self::CORE_ORDER ) );
		}
		return min( 99, $base );
	}

	/** Copy message files and public images out of the archive into the private/public folders. */
	private static function p_files( Reader $r, array $st ) {
		$n   = 0;
		$bad = 0;
		foreach ( $r->files_in( 'files/message-files/' ) as $e ) {
			$name = basename( $e );
			$dest = Paths::dir( 'messages' ) . '/' . $name;
			$r->extract_file( $e, $dest ) ? $n++ : $bad++;
			@chmod( $dest, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
		foreach ( $r->files_in( 'files/public/' ) as $e ) {
			$rel  = substr( $e, strlen( 'files/public/' ) );
			$dest = Paths::dir( 'public' ) . '/' . $rel;
			wp_mkdir_p( dirname( $dest ) );
			$r->extract_file( $e, $dest ) ? $n++ : $bad++;
		}
		$st['report']['files'] = array( 'copied' => $n, 'failed' => $bad );
		$st['phase']           = 'attachments';
		return $st;
	}

	/** Turn the old media references into attachments of the new suite and remember old id → new id. */
	private static function p_attachments( Reader $r, array $st ) {
		$map  = array();
		$made = 0;
		$lost = 0;
		foreach ( (array) ( isset( $r->manifest['attachments'] ) ? $r->manifest['attachments'] : array() ) as $old => $ref ) {
			$ref = (string) $ref;
			if ( 0 === strpos( $ref, 'bd:' ) ) {
				$stored = substr( $ref, 3 );
				if ( ! is_file( Paths::dir( 'public' ) . '/' . $stored ) ) {
					$lost++;
					continue;
				}
			} else {
				$entry = 'files/uploads/' . $ref;
				$stored = bin2hex( random_bytes( 6 ) ) . '-' . sanitize_file_name( basename( $ref ) );
				if ( ! $r->has_file( $entry ) || ! $r->extract_file( $entry, Paths::dir( 'public' ) . '/' . $stored ) ) {
					$lost++;
					continue;
				}
			}
			$ft  = wp_check_filetype( $stored );
			$id  = Media::register( $stored, preg_replace( '/^[0-9a-f]{12}-/', '', $stored ), $ft['type'] ? $ft['type'] : 'application/octet-stream' );
			if ( is_wp_error( $id ) ) {
				$lost++;
				continue;
			}
			$map[ (string) $old ] = (int) $id;
			$made++;
		}
		self::$att                   = $map;
		$st['attachments']           = $map;
		$st['report']['attachments'] = array( 'registered' => $made, 'missing' => $lost );
		$st['phase']                 = 'tables';
		$st['cursor']                = array( 'i' => 0, 'offset' => 0, 'cleared' => false );
		return $st;
	}

	/** One table at a time, a batch at a time. */
	private static function p_tables( Reader $r, array $st, $deadline ) {
		global $wpdb;
		$cur = $st['cursor'];
		if ( empty( $cur['cleared'] ) ) {
			if ( ! empty( $st['replace'] ) ) {
				foreach ( self::core_tables() as $t ) {
					$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( $t ) ); // phpcs:ignore WordPress.DB
				}
			}
			$cur['cleared'] = true;
		}
		while ( $cur['i'] < count( self::CORE_ORDER ) ) {
			$old   = self::CORE_ORDER[ $cur['i'] ];
			$total = $r->count( $old );
			$seen  = 0;
			$put   = 0;
			$batch = 0;
			foreach ( $r->rows( $old ) as $row ) {
				if ( $seen++ < $cur['offset'] ) {
					continue;
				}
				if ( false === self::put( $old, $row, $st ) ) {
					$st['report']['failed'][ $old ] = ( isset( $st['report']['failed'][ $old ] ) ? $st['report']['failed'][ $old ] : 0 ) + 1;
				}
				$put++;
				$cur['offset']++;
				if ( ++$batch >= self::BATCH && microtime( true ) > $deadline ) {
					break;
				}
			}
			$st['report']['tables'][ $old ] = array( 'rows' => $total, 'imported' => min( $total, (int) $cur['offset'] ) );
			if ( $cur['offset'] >= $total ) {
				$cur['i']++;
				$cur['offset'] = 0;
			}
			if ( microtime( true ) > $deadline ) {
				break;
			}
		}
		$st['cursor'] = $cur;
		if ( $cur['i'] >= count( self::CORE_ORDER ) ) {
			$st['phase'] = 'options';
		}
		return $st;
	}

	/* ---- row mapping ---- */

	public static function map_user( $id ) {
		$id = (int) $id;
		if ( $id < 1 ) {
			return 0;
		}
		return get_userdata( $id ) ? $id : 0;
	}

	/** The new attachment id for an old one (0 when it could not be brought over). */
	public static function attachment_id( $old ) {
		$map = null !== self::$att ? self::$att : ( isset( self::state()['attachments'] ) ? self::state()['attachments'] : array() );
		return isset( $map[ (string) $old ] ) ? (int) $map[ (string) $old ] : 0;
	}

	/** The old → new attachment map while a step is running (the saved copy is only written at the end of a step). */
	private static $att = null;

	private static function pick( array $row, array $cols ) {
		$out = array();
		foreach ( $cols as $c ) {
			if ( array_key_exists( $c, $row ) ) {
				$out[ $c ] = $row[ $c ];
			}
		}
		return $out;
	}

	private static function users( array $row ) {
		foreach ( array( 'created_by', 'user_id', 'wp_user_id', 'sender_id' ) as $c ) {
			if ( isset( $row[ $c ] ) ) {
				$row[ $c ] = self::map_user( $row[ $c ] );
			}
		}
		return $row;
	}

	private static function put( $old, array $row, array &$st ) {
		global $wpdb;
		$row = self::users( $row );
		switch ( $old ) {
			case 'brands':
				$new = self::pick( $row, array( 'id', 'name', 'type', 'description', 'status', 'created_by', 'created_at', 'updated_at' ) );
				$new['logo_attachment_id'] = isset( $row['logo_attachment_id'] ) ? self::attachment_id( $row['logo_attachment_id'] ) : 0;
				return $wpdb->insert( Schema::table( 'wpflat_departments' ), $new );
			case 'clients':
				$new         = self::pick( $row, array( 'id', 'contact_person', 'email', 'phone', 'address', 'notes', 'status', 'created_by', 'created_at', 'updated_at' ) );
				$new['name'] = $row['company_name'];
				$new['type'] = isset( $row['client_type'] ) ? $row['client_type'] : '';
				return $wpdb->insert( Schema::table( 'wpflat_clients' ), $new );
			case 'client_brands':
				$new = self::pick( $row, array( 'id', 'client_id', 'is_primary' ) );
				$new['department_id'] = $row['brand_id'];
				return $wpdb->insert( Schema::table( 'wpflat_client_departments' ), $new );
			case 'client_contacts':
				return $wpdb->insert( Schema::table( 'wpflat_client_contacts' ), self::pick( $row, array( 'id', 'client_id', 'name', 'email', 'phone', 'job_title', 'kind', 'sort_order', 'created_by', 'created_at', 'updated_at' ) ) );
			case 'team_members':
				$new = self::pick( $row, array( 'id', 'name', 'email', 'role', 'wp_user_id', 'kind', 'department_id', 'start_date', 'end_date', 'notes', 'status', 'created_by', 'created_at', 'updated_at' ) );
				$new['cc_documents'] = isset( $row['cc_invoices'] ) ? $row['cc_invoices'] : 1;
				return $wpdb->insert( Schema::table( 'wpflat_team_members' ), $new );
			case 'activity':
				$new = self::pick( $row, array( 'id', 'entity_id', 'client_id', 'action', 'summary', 'meta', 'user_id', 'created_at' ) );
				$map = array( 'brands' => 'departments', 'services' => 'catalog', 'client-services' => 'client-services' );
				$new['entity_type'] = isset( $map[ $row['entity_type'] ] ) ? $map[ $row['entity_type'] ] : $row['entity_type'];
				return $wpdb->insert( Schema::table( 'wpflat_activity' ), $new );
			case 'lookups':
				return self::put_lookup( $row, $st );
			case 'threads':
				$new = self::pick( $row, array( 'id', 'client_id', 'subject', 'status', 'started_by_type', 'created_by', 'last_message_at', 'last_message_id', 'message_count', 'closed_at', 'created_at', 'updated_at' ) );
				$new['context_type'] = ! empty( $row['project_id'] ) ? 'project' : '';
				$new['context_id']   = ! empty( $row['project_id'] ) ? (int) $row['project_id'] : 0;
				return $wpdb->insert( Schema::table( 'wpflat_threads' ), $new );
			case 'messages':
				return $wpdb->insert( Schema::table( 'wpflat_messages' ), self::pick( $row, array( 'id', 'thread_id', 'client_id', 'sender_id', 'sender_type', 'kind', 'body', 'created_at', 'deleted_at' ) ) );
			case 'message_files':
				return $wpdb->insert( Schema::table( 'wpflat_message_files' ), self::pick( $row, array( 'id', 'message_id', 'thread_id', 'client_id', 'file', 'name', 'mime', 'size', 'sha256', 'created_at' ) ) );
			case 'thread_reads':
				return $wpdb->insert( Schema::table( 'wpflat_thread_reads' ), self::pick( $row, array( 'id', 'thread_id', 'user_id', 'last_read_id', 'read_at' ) ) );
		}
		return false;
	}

	/** Core's lists go into Core; the rest are kept for the module that owns them. */
	private static function put_lookup( array $row, array &$st ) {
		global $wpdb;
		$type = $row['type'];
		if ( ! isset( self::CORE_LOOKUPS[ $type ] ) ) {
			$st['rest_lookups'][] = $row;
			return true;
		}
		$t    = Schema::table( 'wpflat_lookups' );
		$data = array( 'label' => $row['label'], 'color' => $row['color'], 'months' => $row['months'], 'sort_order' => $row['sort_order'], 'is_active' => $row['is_active'], 'is_system' => $row['is_system'] );
		$new  = self::CORE_LOOKUPS[ $type ];
		$id   = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE type=%s AND item_key=%s", $new, $row['item_key'] ) ); // phpcs:ignore WordPress.DB
		if ( $id ) {
			return $wpdb->update( $t, $data, array( 'id' => (int) $id ) );
		}
		return $wpdb->insert( $t, $data + array( 'type' => $new, 'item_key' => $row['item_key'] ) );
	}

	/* ---- settings ---- */

	/** Settings, email setup and which modules were on. Money and contract settings are kept for their modules. */
	private static function p_options( Reader $r, array $st ) {
		$opts = $r->options;
		$old  = isset( $opts['bizydash_settings'] ) && is_array( $opts['bizydash_settings'] ) ? $opts['bizydash_settings'] : array();
		$core = array_keys( Settings::defaults() );
		$new  = array();
		foreach ( $old as $k => $v ) {
			if ( in_array( $k, $core, true ) ) {
				$new[ $k ] = $v;
			} else {
				$st['rest_settings'][ $k ] = $v;
			}
		}
		foreach ( array( 'business_logo_id', 'business_logo_email_id' ) as $k ) {
			if ( isset( $new[ $k ] ) ) {
				$new[ $k ] = self::attachment_id( $new[ $k ] );
			}
		}
		$new['app_slug'] = isset( $new['app_slug'] ) ? $new['app_slug'] : 'app';
		Settings::update( $new );

		if ( isset( $opts['bizydash_mail'] ) && is_array( $opts['bizydash_mail'] ) ) {
			$st = self::p_mail( $opts['bizydash_mail'], $st );
		}
		if ( isset( $opts['bizydash_modules'] ) && is_array( $opts['bizydash_modules'] ) ) {
			$off = array();
			foreach ( $opts['bizydash_modules'] as $id => $on ) {
				if ( ! $on && isset( self::MODULES[ $id ] ) ) {
					$off[] = self::MODULES[ $id ];
				}
			}
			update_option( \WPFlatCore\Registry::OFF_OPTION, $off, false );
		}
		foreach ( $opts as $k => $v ) {
			if ( ! in_array( $k, array( 'bizydash_settings', 'bizydash_mail', 'bizydash_modules' ), true ) ) {
				$st['rest_options'][ $k ] = $v;
			}
		}
		$st['report']['settings'] = array( 'kept' => count( $new ), 'for_modules' => count( $st['rest_settings'] ) );
		$st['phase']              = 'finish';
		return $st;
	}

	/** The old SMTP password was locked with the old plugin's key; unlock it here and lock it with the new one. */
	private static function p_mail( array $m, array $st ) {
		$keep = array( 'method', 'from_name', 'from_email', 'reply_to', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_auth', 'smtp_user', 'bcc_self' );
		$new  = array();
		foreach ( $keep as $k ) {
			if ( isset( $m[ $k ] ) ) {
				$new[ $k ] = $m[ $k ];
			}
		}
		$st['rest_options']['bizydash_mail_invoice'] = array( 'invoice_subject' => isset( $m['invoice_subject'] ) ? $m['invoice_subject'] : '', 'invoice_message' => isset( $m['invoice_message'] ) ? $m['invoice_message'] : '' );
		$plain = '';
		if ( ! empty( $m['smtp_pass_enc'] ) ) {
			$plain = self::old_decrypt( (string) $m['smtp_pass_enc'] );
			$st['report']['mail'] = '' !== $plain ? 'The email password was carried over.' : 'The email password could not be carried over; enter it again in Settings → Email.';
		}
		$new['smtp_pass_enc'] = '' !== $plain ? Crypto::encrypt( $plain ) : '';
		update_option( \WPFlatCore\Mail_Settings::OPTION, array_merge( \WPFlatCore\Mail_Settings::get_full(), $new ), false );
		return $st;
	}

	/** The old plugin's reversible encryption (same site salts, key 'bizydash'). */
	private static function old_decrypt( $stored ) {
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			return (string) base64_decode( substr( $stored, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		if ( 0 !== strpos( $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}
		$key   = hash( 'sha256', wp_salt( 'auth' ) . 'bizydash', true );
		$plain = openssl_decrypt( substr( $raw, 16 ), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return false === $plain ? '' : $plain;
	}

	private static function p_finish( Reader $r, array $st ) {
		global $wpdb;
		foreach ( self::CORE_ORDER as $old ) {
			if ( 'lookups' === $old ) {
				continue;
			}
			$new = array( 'brands' => 'wpflat_departments', 'clients' => 'wpflat_clients', 'client_brands' => 'wpflat_client_departments', 'client_contacts' => 'wpflat_client_contacts', 'team_members' => 'wpflat_team_members', 'activity' => 'wpflat_activity', 'threads' => 'wpflat_threads', 'messages' => 'wpflat_messages', 'message_files' => 'wpflat_message_files', 'thread_reads' => 'wpflat_thread_reads' )[ $old ];
			$st['report']['tables'][ $old ]['now'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $new ) ); // phpcs:ignore WordPress.DB
		}
		$st['core_done'] = true;
		$st['phase']     = 'done';
		$st['done_at']   = gmdate( 'Y-m-d H:i:s' );
		do_action( 'wpflatcore_legacy_core_imported', $st );
		return $st;
	}

	/* ───────────────────────── Modules pick up their part ───────────────────────── */

	/** Everything a module was asked to import and what it said. */
	public static function module_state( $module ) {
		$st = self::state();
		return isset( $st['modules'][ $module ] ) ? $st['modules'][ $module ] : array( 'done' => false );
	}

	/** Old settings, options and lists a module may want (it reads only what is its own). */
	public static function rest( $what ) {
		$st = self::state();
		return isset( $st[ 'rest_' . $what ] ) ? $st[ 'rest_' . $what ] : array();
	}

	/**
	 * Ask an installed module to import its tables. The module's callback gets the Reader and returns a report
	 * (or a WP_Error). Safe to call again; a module that already finished is skipped unless $again.
	 */
	public static function run_module( $module, $again = false ) {
		$st = self::state();
		if ( empty( $st['core_done'] ) ) {
			return new \WP_Error( 'wpflatcore_core_first', 'Import the core data first.', array( 'status' => 400 ) );
		}
		if ( ! Registry::enabled( $module ) || ! has_filter( 'wpflatcore_legacy_import_' . $module ) ) {
			return new \WP_Error( 'wpflatcore_module_missing', 'That module is not installed (or cannot import yet). Install it, then try again.', array( 'status' => 400 ) );
		}
		if ( ! $again && ! empty( $st['modules'][ $module ]['done'] ) ) {
			return $st['modules'][ $module ]['report'];
		}
		$reader = self::reader();
		if ( ! $reader ) {
			return new \WP_Error( 'wpflatcore_none', 'The backup is no longer available.', array( 'status' => 400 ) );
		}
		$report = apply_filters( 'wpflatcore_legacy_import_' . $module, null, $reader, __CLASS__ );
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		if ( ! is_array( $report ) ) {
			return new \WP_Error( 'wpflatcore_module_silent', 'The module did not report anything; nothing was marked as imported.', array( 'status' => 500 ) );
		}
		$st                       = self::state();
		$st['modules'][ $module ] = array( 'done' => true, 'report' => $report, 'at' => gmdate( 'Y-m-d H:i:s' ) );
		self::save( $st );
		return $report;
	}

	/* ───────────────────────── People ───────────────────────── */

	/**
	 * Give the people who had BizyDash roles the matching WPFlat roles and carry over their client links and
	 * preferences. The old roles stay, so the old plugin keeps working until it is switched off. $only limits it to
	 * some user ids.
	 */
	public static function migrate_users( array $only = array() ) {
		global $wpdb;
		$done = 0;
		$meta = array( 'bizydash_client_id' => 'wpflatcore_client_id', 'bizydash_portal_user' => 'wpflatcore_portal_user', 'bizydash_portal_org' => 'wpflatcore_portal_org', 'bizydash_last_login' => 'wpflatcore_last_login', 'bizydash_portal_invited' => 'wpflatcore_portal_invited', 'bizydash_theme' => 'wpflatcore_theme', 'bizydash_notify_prefs' => 'wpflatcore_notify_prefs', 'bizydash_msg_notify' => 'wpflatcore_msg_notify' );
		\WPFlatCore\Roles::sync();
		foreach ( self::ROLES as $old => $new ) {
			foreach ( get_users( array( 'role' => $old ) ) as $u ) {
				if ( $only && ! in_array( (int) $u->ID, $only, true ) ) {
					continue;
				}
				if ( get_role( $new ) && ! in_array( $new, (array) $u->roles, true ) ) {
					$u->add_role( $new );
				}
				foreach ( $meta as $from => $to ) {
					$vals = get_user_meta( $u->ID, $from );
					if ( $vals && ! get_user_meta( $u->ID, $to ) ) {
						foreach ( $vals as $v ) {
							add_user_meta( $u->ID, $to, $v );
						}
					}
				}
				$grants = get_user_meta( $u->ID, 'bizydash_manager_modules', true );
				if ( is_array( $grants ) && ! get_user_meta( $u->ID, 'wpflatcore_manager_modules', true ) ) {
					$map = array( 'clients' => 'clients', 'messages' => 'messages', 'work' => 'tasks', 'billing' => 'finance', 'finance' => 'finance', 'contracts' => 'sign' );
					update_user_meta( $u->ID, 'wpflatcore_manager_modules', array_values( array_unique( array_filter( array_map( function ( $g ) use ( $map ) {
						return isset( $map[ $g ] ) ? $map[ $g ] : '';
					}, $grants ) ) ) ) );
				}
				$done++;
			}
		}
		return array( 'people' => $done );
	}
}
