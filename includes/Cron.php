<?php
namespace BizyCore;

defined( 'ABSPATH' ) || exit;

/** One daily job. Core and every module hook `bizycore_daily` (reminders, digests, clean-up); modules never schedule their own. */
class Cron {

	/** WordPress's scheduled event; its handler announces the public `bizycore_daily` action that everyone listens to. */
	const HOOK = 'bizycore_daily_tick';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'daily', self::HOOK );
		}
	}

	public static function run() {
		do_action( 'bizycore_daily' );
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
