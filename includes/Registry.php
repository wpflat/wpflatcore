<?php
namespace BizyCore;

defined( 'ABSPATH' ) || exit;

/**
 * The list of installed Bizy modules and what each contributes. Replaces the hard-coded module, resource, menu,
 * capability and portal arrays of the old single plugin: Core never names a module, it only reads this registry.
 *
 * A definition (all keys optional except id, label, version):
 *   id            slug, lowercase letters/digits/dashes — 'tasks', 'money', 'doc'
 *   label         'Tasks'
 *   version       the module plugin's version
 *   requires_core minimum BizyCore version, e.g. '0.1.0'
 *   desc, icon    shown in Settings → Modules and the sidebar
 *   route         first screen, e.g. '/tasks'
 *   routes        first path segments that belong to this module, e.g. array( 'tasks', 'projects' )
 *   menu          sub-menu: array( array( label, route, cap?, url? ) )
 *   cap           capability that opens the module (empty = every Bizy user)
 *   caps          capabilities the module adds, e.g. array( 'bizymoney_billing' )
 *   resources     REST resource names the module owns, e.g. array( 'projects', 'tasks' )
 *   tables        table names (without prefix) the module owns — used by backup
 *   options       WordPress options the module owns — used by backup
 *   optional_tables / excluded_tables   tables backup may skip (logs) / never saves (one-time codes)
 *   attachment_columns   table => array( column ) holding media ids, so backup carries the files
 *   user_columns  columns holding WordPress user ids (besides created_by, user_id, wp_user_id, sender_id)
 *   refs          child table => array( column => parent table ) to spot orphans after a restore
 *   settings_keys keys of Core's settings option that this module owns (restored with the module)
 *   file_kinds    private file folders the module uses (Paths::dir( kind )), saved by backup
 *   public_paths / public_params   first address segments / query args of the module's public pages
 *   roles         team roles this module adds: array( slug => array( label, desc, caps ) )
 *   client_manager_caps / team_manager_caps   capabilities the Client Manager / Team Manager roles get
 *   manager_grants   what a Team Manager can be given per person: array( id => array( label, caps ) )
 *   portal_tabs   array( array( id, label, order, cap ) ) shown in the client portal
 *   assets        array( 'script' => 'bizytasks.js', 'style' => 'bizytasks.css' ) file names loaded after the Core app
 *   assets_dir    absolute folder holding those files (Core serves them under a neutral /bd/a/… address)
 *   wp            array( dashicon, position ) for the WordPress admin menu
 */
class Registry {

	const OFF_OPTION = 'bizycore_modules_off';

	/** @var array id => definition (+ 'status' and 'reason') */
	private static $mods = array();

	/** @var array notices for modules that could not start */
	private static $problems = array();

	public static function register( array $def ) {
		$id = isset( $def['id'] ) ? (string) $def['id'] : '';
		if ( ! preg_match( '/^[a-z][a-z0-9-]{1,30}$/', $id ) || 'core' === $id ) {
			return new \WP_Error( 'bizycore_module_id', 'A module needs a lowercase id such as "tasks".' );
		}
		if ( empty( $def['label'] ) || empty( $def['version'] ) ) {
			return new \WP_Error( 'bizycore_module_def', 'A module needs a label and a version.' );
		}
		if ( isset( self::$mods[ $id ] ) ) {
			return new \WP_Error( 'bizycore_module_dup', 'A module called "' . $id . '" is already registered.' );
		}
		$def += array(
			'requires_core' => '0.0.0',
			'desc'          => '',
			'icon'          => $id,
			'route'         => '/' . $id,
			'routes'        => array( $id ),
			'menu'          => array(),
			'cap'           => '',
			'caps'          => array(),
			'resources'     => array(),
			'tables'        => array(),
			'options'       => array(),
			'optional_tables' => array(),
			'excluded_tables' => array(),
			'attachment_columns' => array(),
			'user_columns'  => array(),
			'refs'          => array(),
			'settings_keys' => array(),
			'file_kinds'    => array(),
			'public_paths'  => array(),
			'public_params' => array(),
			'portal_tabs'   => array(),
			'roles'         => array(),
			'client_manager_caps' => array(),
			'team_manager_caps'   => array(),
			'manager_grants'      => array(),
			'assets'        => array(),
			'assets_dir'    => '',
			'wp'            => array( 'dashicons-admin-generic', 30 ),
		);
		$def['id']     = $id;
		$def['status'] = 'active';
		$def['reason'] = '';
		self::$mods[ $id ] = $def;
		return true;
	}

	/** Called once every module has had its chance to register: mark the ones this Core cannot run. */
	public static function settle() {
		foreach ( self::$mods as $id => $m ) {
			if ( version_compare( BIZYCORE_VERSION, $m['requires_core'], '<' ) ) {
				self::$mods[ $id ]['status'] = 'blocked';
				self::$mods[ $id ]['reason'] = sprintf( '%s needs BizyCore %s or newer (this site has %s). Update BizyCore.', $m['label'], $m['requires_core'], BIZYCORE_VERSION );
				self::$problems[]            = self::$mods[ $id ]['reason'];
			}
		}
		/** Last chance for add-ons to adjust a module (e.g. extend its menu). */
		self::$mods = apply_filters( 'bizycore_modules', self::$mods );
	}

	public static function problems() {
		return self::$problems;
	}

	/** Every registered module, whatever its state. */
	public static function all() {
		return self::$mods;
	}

	public static function get( $id ) {
		return isset( self::$mods[ $id ] ) ? self::$mods[ $id ] : null;
	}

	/** Ids the owner has switched off in Settings → Modules (data is kept, screens and jobs stop). */
	public static function switched_off() {
		$off = get_option( self::OFF_OPTION, array() );
		return is_array( $off ) ? array_values( array_map( 'strval', $off ) ) : array();
	}

	/** Is the module installed, compatible and switched on? Core is always on. */
	public static function enabled( $id ) {
		if ( 'core' === $id ) {
			return true;
		}
		return isset( self::$mods[ $id ] ) && 'active' === self::$mods[ $id ]['status'] && ! in_array( $id, self::switched_off(), true );
	}

	/** Installed (plugin active), regardless of the owner's on/off switch. */
	public static function installed( $id ) {
		return isset( self::$mods[ $id ] );
	}

	/** Modules that are on, in registration order. */
	public static function active() {
		return array_filter(
			self::$mods,
			function ( $m ) {
				return self::enabled( $m['id'] );
			}
		);
	}

	/** Switch a module on or off from Settings. Never deletes data. */
	public static function set_enabled( $id, $on ) {
		if ( ! isset( self::$mods[ $id ] ) ) {
			return new \WP_Error( 'bizycore_module_unknown', 'That module is not installed.', array( 'status' => 404 ) );
		}
		$off = array_values( array_diff( self::switched_off(), array( $id ) ) );
		if ( ! $on ) {
			$off[] = $id;
		}
		update_option( self::OFF_OPTION, array_values( array_unique( $off ) ), false );
		do_action( $on ? 'bizycore_module_enabled' : 'bizycore_module_disabled', $id );
		do_action( 'litespeed_purge_all' ); // public pages appear or vanish with their module
		return true;
	}

	/** Which module owns a REST resource ('core' when nobody claims it). */
	public static function resource_module( $name ) {
		foreach ( self::$mods as $id => $m ) {
			if ( in_array( $name, $m['resources'], true ) ) {
				return $id;
			}
		}
		return 'core';
	}

	/** Which module a first route segment belongs to ('core' when nobody claims it). */
	public static function route_module( $segment ) {
		foreach ( self::$mods as $id => $m ) {
			if ( in_array( $segment, $m['routes'], true ) ) {
				return $id;
			}
		}
		return 'core';
	}

	public static function module_cap( $id ) {
		return isset( self::$mods[ $id ] ) ? (string) self::$mods[ $id ]['cap'] : '';
	}

	/** Every table a module owns, with the prefix (for backup). */
	public static function tables_of( $id ) {
		global $wpdb;
		$out = array();
		if ( isset( self::$mods[ $id ] ) ) {
			foreach ( self::$mods[ $id ]['tables'] as $t ) {
				$out[] = $wpdb->prefix . $t;
			}
		}
		return $out;
	}

	/** Portal tabs from every enabled module, ordered. */
	public static function portal_tabs() {
		$tabs = array();
		foreach ( self::active() as $m ) {
			foreach ( $m['portal_tabs'] as $t ) {
				if ( ! empty( $t['cap'] ) && ! current_user_can( $t['cap'] ) ) {
					continue;
				}
				$tabs[] = $t + array( 'order' => 50, 'module' => $m['id'] );
			}
		}
		usort(
			$tabs,
			function ( $a, $b ) {
				return $a['order'] <=> $b['order'];
			}
		);
		return $tabs;
	}

	/** Navigation for the signed-in user: core links plus each enabled module's own sub-menu. */
	public static function nav() {
		$mods = array();
		foreach ( self::active() as $id => $m ) {
			if ( $m['cap'] && ! current_user_can( $m['cap'] ) && ! current_user_can( 'manage_options' ) ) {
				continue;
			}
			$items = array();
			foreach ( $m['menu'] as $it ) {
				if ( ! empty( $it['cap'] ) && ! current_user_can( $it['cap'] ) && ! current_user_can( 'manage_options' ) ) {
					continue;
				}
				$items[] = array(
					'label' => $it['label'],
					'route' => isset( $it['route'] ) ? $it['route'] : '',
					'url'   => ! empty( $it['url'] ) ? home_url( '/' . $it['url'] ) : '',
				);
			}
			$mods[] = array( 'id' => $id, 'label' => $m['label'], 'icon' => $m['icon'], 'route' => $m['route'], 'items' => $items );
		}
		$core = array(
			array( 'label' => Terminology::label( 'home', true ), 'route' => '/', 'icon' => 'home' ),
			array( 'label' => Terminology::label( 'client', true ), 'route' => '/clients', 'icon' => 'clients' ),
			array( 'label' => Terminology::label( 'team', true ), 'route' => '/team', 'icon' => 'team' ),
			array( 'label' => Terminology::label( 'department', true ), 'route' => '/departments', 'icon' => 'departments' ),
			array( 'label' => Terminology::label( 'group', true ), 'route' => '/groups', 'icon' => 'groups' ),
		);
		return array(
			'core'    => apply_filters( 'bizycore_nav_core', $core ),
			'modules' => $mods,
			'footer'  => array(
				array( 'label' => 'Activity', 'route' => '/activity', 'icon' => 'activity' ),
				array( 'label' => 'Settings', 'route' => '/settings', 'icon' => 'settings' ),
			),
		);
	}

	/** For Settings → Modules and the dependency screens. */
	public static function summary() {
		$out = array();
		foreach ( self::$mods as $id => $m ) {
			$out[] = array(
				'id'      => $id,
				'label'   => $m['label'],
				'routes'  => $m['routes'],
				'icon'    => $m['icon'],
				'route'   => $m['route'],
				'desc'    => $m['desc'],
				'version' => $m['version'],
				'status'  => $m['status'],
				'reason'  => $m['reason'],
				'enabled' => self::enabled( $id ),
			);
		}
		return $out;
	}

	/** Scripts/styles the Core app should load for the enabled modules: id => array( script => url, style => url ). */
	public static function assets() {
		$out = array();
		foreach ( self::active() as $id => $m ) {
			if ( empty( $m['assets'] ) || empty( $m['assets_dir'] ) ) {
				continue;
			}
			$one = array();
			foreach ( array( 'script', 'style' ) as $k ) {
				if ( ! empty( $m['assets'][ $k ] ) && is_readable( rtrim( $m['assets_dir'], '/' ) . '/' . $m['assets'][ $k ] ) ) {
					$one[ $k ] = Assets::module_url( $id, $m['assets'][ $k ] );
				}
			}
			if ( $one ) {
				$out[ $id ] = $one;
			}
		}
		return $out;
	}
}
