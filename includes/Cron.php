<?php
namespace WPFlatCore;

defined( 'ABSPATH' ) || exit;

/** One daily job. Core and every module hook `wpflatcore_daily` (reminders, digests, clean-up); modules never schedule their own. */
class Cron {

	/** WordPress's scheduled event; its handler announces the public `wpflatcore_daily` action that everyone listens to. */
	const HOOK = 'wpflatcore_daily_tick';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'daily', self::HOOK );
		}
	}

	public static function run() {
		do_action( 'wpflatcore_daily' );
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
