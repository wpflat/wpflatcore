<?php
namespace WPFlatCore;

defined( 'ABSPATH' ) || exit;

/**
 * The suite used to be called Bizy. A site that ran it keeps everything: tables, settings, saved preferences, roles, permissions, scheduled
 * jobs and the private files folder move to the WPFlat names once, the first time WPFlat Core loads. It runs before anything reads the data, and
 * does nothing on later requests (or on a fresh install).
 */
class Migrate_Bizy {

	const DONE = 'wpflatcore_from_bizy';

	/** Old prefix => new prefix, for tables, options, user meta, post meta and permissions. */
	const PREFIXES = array(
		'bizycore_'    => 'wpflatcore_',
		'bizytasks_'   => 'wpflattasks_',
		'bizyfinance_' => 'wpflatfinance_',
		'bizysign_'    => 'wpflatsign_',
		'bizyfiles_'   => 'wpflatfiles_',
		'bizyform_'    => 'wpflatforms_',
		'bizy_'        => 'wpflat_',
	);

	/** Roles whose new name is not just the old one with the prefix swapped. */
	const ROLES = array( 'bizy_form_team' => 'wpflat_forms_team' );

	/** Stages: nothing yet → 'data' (tables, options, files moved) → '1' (roles and permissions moved). */
	public static function init() {
		$st = get_option( self::DONE );
		if ( '1' === (string) $st ) {
			return;
		}
		if ( ! $st ) {
			if ( ! self::has_old_data() ) {
				update_option( self::DONE, '1', false );
				return;
			}
			self::data();
			update_option( self::DONE, 'data', false );
		}
		add_action( 'init', array( __CLASS__, 'access' ), 0 ); // roles are ready from "init" on (a later request if this one is already past it)
	}

	private static function has_old_data() {
		global $wpdb;
		foreach ( self::PREFIXES as $old => $new ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $old ) . '%' ) ) ) { // phpcs:ignore WordPress.DB
				return true;
			}
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM $wpdb->options WHERE option_name LIKE %s LIMIT 1", $wpdb->esc_like( $old ) . '%' ) ) ) { // phpcs:ignore WordPress.DB
				return true;
			}
		}
		return false;
	}

	/** Swap the old prefix for the new one on a name, or return '' when it does not start with an old prefix. */
	public static function renamed( $name ) {
		foreach ( self::PREFIXES as $old => $new ) {
			if ( 0 === strpos( $name, $old ) ) {
				return $new . substr( $name, strlen( $old ) );
			}
		}
		return '';
	}

	/** Tables, options, user and post meta, scheduled jobs and the files folder. */
	private static function data() {
		global $wpdb;
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		// Tables: wp_bizy_clients → wp_wpflat_clients, wp_bizytasks_tasks → wp_wpflattasks_tasks …
		foreach ( self::PREFIXES as $old => $new ) {
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $old ) . '%' ) ) as $t ) { // phpcs:ignore WordPress.DB
				$to = $wpdb->prefix . $new . substr( $t, strlen( $wpdb->prefix . $old ) );
				if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $to ) ) ) { // phpcs:ignore WordPress.DB
					$wpdb->query( 'RENAME TABLE `' . esc_sql( $t ) . '` TO `' . esc_sql( $to ) . '`' ); // phpcs:ignore WordPress.DB
				}
			}
		}

		// Options, user meta and post meta keep their values; only the key changes. If the new key already exists the old one is dropped.
		$targets = array(
			array( $wpdb->options, 'option_name', '' ),
			array( $wpdb->usermeta, 'meta_key', '' ),
			array( $wpdb->postmeta, 'meta_key', '_' ),
		);
		foreach ( $targets as $tg ) {
			list( $table, $col, $lead ) = $tg;
			foreach ( self::PREFIXES as $old => $new ) {
				$like = $wpdb->esc_like( $lead . $old ) . '%';
				$keys = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT $col FROM $table WHERE $col LIKE %s", $like ) ); // phpcs:ignore WordPress.DB
				foreach ( $keys as $k ) {
					$to = $lead . self::renamed( substr( $k, strlen( $lead ) ) );
					if ( '' === $to || $to === $lead ) {
						continue;
					}
					if ( $table === $wpdb->options ) {
						if ( $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM $table WHERE option_name=%s", $to ) ) ) { // phpcs:ignore WordPress.DB
							$wpdb->delete( $table, array( 'option_name' => $k ) );
						} else {
							$wpdb->update( $table, array( 'option_name' => $to ), array( 'option_name' => $k ) );
						}
					} else {
						$wpdb->query( $wpdb->prepare( "UPDATE IGNORE $table SET $col=%s WHERE $col=%s", $to, $k ) ); // phpcs:ignore WordPress.DB
					}
				}
			}
		}
		wp_cache_flush();

		// Scheduled jobs are registered again under the new names.
		$cron = _get_cron_array();
		if ( is_array( $cron ) ) {
			foreach ( $cron as $ts => $hooks ) {
				foreach ( array_keys( (array) $hooks ) as $hook ) {
					if ( preg_match( '/^bizy(core|tasks|finance|sign|files|form)?_/', $hook ) ) {
						wp_clear_scheduled_hook( $hook );
					}
				}
			}
		}

		// The private files folder (uploads, backups, signed PDFs …).
		$roots = array( rtrim( wp_normalize_path( WP_CONTENT_DIR ), '/' ), dirname( rtrim( wp_normalize_path( ABSPATH ), '/' ) ) );
		foreach ( $roots as $r ) {
			$old = $r . '/bizy-files';
			$new = $r . '/wpflat-files';
			if ( is_dir( $old ) && ! file_exists( $new ) ) {
				@rename( $old, $new ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
			}
			foreach ( (array) glob( $new . '/private/backups/*.bizy' ) as $f ) {
				@rename( $f, substr( $f, 0, -5 ) . '.wpflat' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
			}
		}
	}

	/** Roles, the people who hold them, and every permission name. Runs once WordPress has its roles. */
	public static function access() {
		global $wpdb;
		$map_cap = function ( $cap ) {
			if ( 'manage_bizycore' === $cap ) {
				return 'manage_wpflatcore';
			}
			$n = self::renamed( $cap );
			return '' !== $n ? $n : $cap;
		};
		$role_to = function ( $key ) {
			if ( isset( self::ROLES[ $key ] ) ) {
				return self::ROLES[ $key ];
			}
			return 0 === strpos( $key, 'bizy_' ) ? 'wpflat_' . substr( $key, 5 ) : '';
		};
		$wp = wp_roles();
		foreach ( array_keys( $wp->roles ) as $key ) {
			$role = $wp->get_role( $key );
			if ( ! $role ) {
				continue;
			}
			foreach ( (array) $role->capabilities as $cap => $grant ) { // permissions inside any role
				$new = $map_cap( $cap );
				if ( $new !== $cap ) {
					$role->add_cap( $new, (bool) $grant );
					$role->remove_cap( $cap );
				}
			}
			$new_key = $role_to( $key );
			if ( '' === $new_key ) {
				continue;
			}
			$label = isset( $wp->roles[ $key ]['name'] ) ? preg_replace( '/^Bizy /', '', $wp->roles[ $key ]['name'] ) : $new_key;
			if ( ! get_role( $new_key ) ) {
				add_role( $new_key, $label, (array) get_role( $key )->capabilities );
			}
			foreach ( get_users( array( 'role' => $key, 'fields' => 'ID' ) ) as $uid ) {
				$u = new \WP_User( (int) $uid );
				$u->add_role( $new_key );
				$u->remove_role( $key );
			}
			remove_role( $key );
		}
		// Permissions given to single people.
		$meta = $wpdb->prefix . 'capabilities';
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key=%s AND meta_value LIKE %s", $meta, '%bizy%' ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$u = new \WP_User( (int) $r['user_id'] );
			foreach ( (array) $u->caps as $cap => $grant ) {
				$new = $map_cap( $cap );
				if ( $new !== $cap && ! isset( self::PREFIXES[ $cap ] ) ) {
					$u->add_cap( $new, (bool) $grant );
					$u->remove_cap( $cap );
				}
			}
		}
		update_option( self::DONE, '1', false );
	}
}
