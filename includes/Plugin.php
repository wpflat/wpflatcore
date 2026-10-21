<?php
namespace WPFlatCore;

defined( 'ABSPATH' ) || exit;

/**
 * Start-up order, so module plugins can load in any order:
 *   plugins_loaded 5   Core starts (this class)
 *   plugins_loaded 20  `wpflatcore_register` fires — each module calls wpflatcore_register_module() here
 *   plugins_loaded 25  the registry settles (versions checked) and `wpflatcore_ready` fires
 */
class Plugin {

	public static function boot() {
		Install::maybe_upgrade();
		Terminology::init();
		Links::init();
		Rest\Routes::init();
		Rest\Collab_Routes::init();
		Rest\System_Routes::init();
		Services\Demo::init();
		Rest\Migration_Routes::init();
		Rest\Backup_Routes::init();
		Backup\Backup_Manager::init();
		Backup\Backup_Download::init();
		Backup\CLI::register();
		Stealth::init();
		Assets::init();
		Shell::init();
		add_action( 'wp_login', function ( $login, $user ) {
			update_user_meta( $user->ID, 'wpflatcore_last_login', time() );
		}, 10, 2 );
		Services\Team_Invite::init();
		Home_Module::init();
		App_Page::init();
		Media::init();
		Cron::init();
		Portal::init();
		Services\Messages::init();
		Services\Notifications::init();
		Services\Portal_Users::init();
		add_action( 'rest_api_init', array( __CLASS__, 'register_resources_rest' ) );

		add_action( 'plugins_loaded', array( __CLASS__, 'collect_modules' ), 20 );
		add_action( 'plugins_loaded', array( __CLASS__, 'settle' ), 25 );
		add_action( 'admin_notices', array( Dependencies::class, 'notices' ) );
	}

	public static function collect_modules() {
		/**
		 * Module plugins hook here and call wpflatcore_register_module( array( … ) ).
		 */
		do_action( 'wpflatcore_register' );
	}

	public static function settle() {
		Registry::settle();
		Core_Resources::register();
		/** Modules hook here and call Resources::register( name, cfg, module_id ). */
		do_action( 'wpflatcore_register_resources' );
		Roles::sync();
		/** Modules add their portal pages here with Portal::register_page(). */
		do_action( 'wpflatcore_register_portal' );
		Lookups::sync();
		do_action( 'wpflatcore_ready', Registry::summary() );
	}

	/** One REST controller per resource whose module is switched on. */
	public static function register_resources_rest() {
		foreach ( Resources::names() as $name ) {
			$c = new Rest\Resource_Controller( $name );
			$c->register();
		}
	}
}
