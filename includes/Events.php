<?php
namespace BizyCore;

defined( 'ABSPATH' ) || exit;

/**
 * The stable names of the announcements modules make, so Notifications, Activity and the portal can listen without
 * ever importing a module's code. A module fires these with do_action(); Core and other modules subscribe.
 *
 *   bizycore_record_saved    ( $type, $row, $before )    any record created or changed ($before null on create)
 *   bizycore_record_deleted  ( $type, $id )              a record was removed (Links forgets it)
 *   bizycore_client_saved    ( $row, $before )
 *   bizytasks_task_done      ( $task )
 *   bizymoney_invoice_sent   ( $invoice )
 *   bizymoney_invoice_paid   ( $invoice )
 *   bizydocs_document_signed ( $document )
 *
 * Module-specific names start with the module plugin's slug so they never collide.
 */
class Events {

	const RECORD_SAVED   = 'bizycore_record_saved';
	const RECORD_DELETED = 'bizycore_record_deleted';
	const CLIENT_SAVED   = 'bizycore_client_saved';

	/** Convenience for modules: announce a saved record. */
	public static function saved( $type, array $row, $before = null ) {
		do_action( self::RECORD_SAVED, $type, $row, $before );
	}

	public static function deleted( $type, $id ) {
		do_action( self::RECORD_DELETED, $type, (int) $id );
	}
}
