<?php
namespace BizyCore\Services;

use BizyCore\Database\Schema;
use BizyCore\Settings;
use BizyCore\Urls;

defined( 'ABSPATH' ) || exit;

/**
 * Notifications for the team and for clients: an in-app list (the bell) plus e-mail, with each person choosing what
 * they want. Core owns the engine (delivery, preferences, digests); each module registers the kinds of notification
 * it can raise and listens to its own events:
 *
 *   add_action( 'bizycore_register_notifications', function () {
 *       \BizyCore\Services\Notifications::register( 'client_invoice_sent', array( ... ) );
 *   } );
 *
 * and uses Notifications::emit() when something happens. Daily reminders hook `bizycore_daily`.
 *
 * Email modes per person and event: instant, a daily digest, or off. Events that already send their own email
 * (an invoice, a contract invitation, a chat message) only create the in-app notification.
 */
class Notifications {

	/** @var array type => array( title, audience, group, description, default email mode, sent by another feature ) */
	private static $catalog = array();

	/**
	 * @param string $type  unique key, e.g. 'client_invoice_sent'
	 * @param array  $def   label (short title), audience ('client'|'team'), group, description, email ('instant'|'digest'|'off'), external (bool)
	 */
	public static function register( $type, array $def ) {
		$def += array( 'label' => $type, 'audience' => 'team', 'group' => 'Other', 'description' => '', 'email' => 'off', 'external' => false );
		self::$catalog[ $type ] = array( $def['label'], $def['audience'], $def['group'], $def['description'], $def['email'], (bool) $def['external'] );
	}

	/** key => label, audience, group, description, default email mode, and whether another feature already e-mails it. */
	public static function catalog() {
		return self::$catalog;
	}

	public static function enabled_type( $type ) {
		return isset( self::$catalog[ $type ] ) && ! in_array( $type, (array) Settings::get( 'notify_disabled' ), true );
	}

	/** Who may use the bell: the team and clients with portal access. */
	public static function can_use() {
		return is_user_logged_in() && ( current_user_can( 'manage_bizycore' ) || ( current_user_can( 'bizycore_portal' ) && \BizyCore\Portal::enabled() ) );
	}

	private static function t() {
		return Schema::table( 'bizy_notifications' );
	}

	/* ───────────────────────── Preferences ───────────────────────── */

	/** @return array{app:bool,email:string} for one person and event */
	public static function pref( $user_id, $type ) {
		$cat = self::catalog();
		$def = array( 'app' => true, 'email' => isset( $cat[ $type ] ) ? $cat[ $type ][4] : 'off' );
		$all = get_user_meta( (int) $user_id, 'bizycore_notify_prefs', true );
		if ( is_array( $all ) && isset( $all[ $type ] ) && is_array( $all[ $type ] ) ) {
			$p = $all[ $type ];
			if ( isset( $p['app'] ) ) {
				$def['app'] = (bool) $p['app'];
			}
			if ( isset( $p['email'] ) && in_array( $p['email'], array( 'instant', 'digest', 'off' ), true ) ) {
				$def['email'] = $p['email'];
			}
		}
		return $def;
	}

	public static function save_prefs( $user_id, array $in ) {
		$cat = self::catalog();
		$out = array();
		foreach ( $in as $type => $p ) {
			if ( isset( $cat[ $type ] ) && is_array( $p ) ) {
				$out[ $type ] = array( 'app' => ! empty( $p['app'] ), 'email' => in_array( isset( $p['email'] ) ? $p['email'] : '', array( 'instant', 'digest', 'off' ), true ) ? $p['email'] : 'off' );
			}
		}
		update_user_meta( (int) $user_id, 'bizycore_notify_prefs', $out );
	}

	/** The events a person can configure, with their current choice. Clients see client events, the team team events. */
	public static function prefs_for( $user_id ) {
		$team = user_can( (int) $user_id, 'manage_bizycore' );
		$out  = array();
		foreach ( self::catalog() as $k => $c ) {
			if ( ( $team ? 'team' : 'client' ) !== $c[1] || ! self::enabled_type( $k ) ) {
				continue;
			}
			$p     = self::pref( $user_id, $k );
			$out[] = array( 'type' => $k, 'group' => $c[2], 'label' => $c[3], 'app' => $p['app'], 'email' => $p['email'], 'external_email' => (bool) $c[5] );
		}
		return $out;
	}

	/* ───────────────────────── Creating notifications ───────────────────────── */

	/**
	 * Notify some people. $link is an app route ("/billing/invoices/3") for the team or a full portal address for clients.
	 *
	 * @param int[] $user_ids
	 * @return int how many were created
	 */
	public static function emit( $type, array $user_ids, $title, $body = '', $link = '', $client_id = 0, $entity_type = '', $entity_id = 0, $dedupe = '' ) {
		global $wpdb;
		if ( ! self::enabled_type( $type ) ) {
			return 0;
		}
		$cat   = self::catalog()[ $type ];
		$actor = get_current_user_id();
		$made  = 0;
		$now   = gmdate( 'Y-m-d H:i:s' );
		foreach ( array_unique( array_map( 'intval', $user_ids ) ) as $uid ) {
			if ( $uid <= 0 || ( $uid === $actor && 'client' !== $cat[1] ) ) {
				continue; // people are not notified about what they just did themselves
			}
			if ( ( 'team' === $cat[1] ) !== (bool) user_can( $uid, 'manage_bizycore' ) ) {
				continue; // a team event goes to the team, a client event to client logins
			}
			$p = self::pref( $uid, $type );
			if ( ! $p['app'] && 'off' === $p['email'] ) {
				continue;
			}
			if ( '' !== $dedupe && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t() . ' WHERE dedupe=%s AND user_id=%d LIMIT 1', $dedupe, $uid ) ) ) { // phpcs:ignore WordPress.DB
				continue;
			}
			$wpdb->insert( self::t(), array( 'user_id' => $uid, 'type' => $type, 'audience' => $cat[1], 'client_id' => (int) $client_id, 'title' => mb_substr( $title, 0, 190 ), 'body' => $body, 'link' => mb_substr( $link, 0, 255 ), 'entity_type' => $entity_type, 'entity_id' => (int) $entity_id, 'dedupe' => $dedupe, 'created_at' => $now, 'visible' => $p['app'] ? 1 : 0 ) ); // hidden in-app when turned off, but kept for a digest
			$nid = (int) $wpdb->insert_id;
			if ( ! $nid ) {
				continue;
			}
			$made++;
			if ( $cat[5] || 'off' === $p['email'] ) {
				$wpdb->update( self::t(), array( 'emailed_at' => $now ), array( 'id' => $nid ) ); // nothing to send
			} elseif ( 'instant' === $p['email'] ) {
				self::queue_email( $nid );
			}
		}
		return $made;
	}

	/* ───────────────────────── E-mail ───────────────────────── */

	private static $queue = array();

	private static function queue_email( $nid ) {
		if ( ! self::$queue ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_queue' ), 5 );
		}
		self::$queue[] = (int) $nid;
	}

	public static function flush_queue() {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		$ids         = self::$queue;
		self::$queue = array();
		foreach ( $ids as $id ) {
			self::send_ids( array( $id ) );
		}
	}

	/** Send one e-mail for a person's notifications (one item = instant; several = digest). */
	private static function send_ids( array $ids ) {
		global $wpdb;
		if ( ! $ids ) {
			return;
		}
		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . self::t() . ' WHERE emailed_at IS NULL AND id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ') ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			return;
		}
		$u = get_userdata( (int) $rows[0]['user_id'] );
		if ( ! $u || ! is_email( $u->user_email ) ) {
			return;
		}
		$biz   = (string) Settings::get( 'business_name' );
		$biz   = $biz ? $biz : wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$team  = user_can( $u, 'manage_bizycore' );
		$prefs = $team ? Urls::app( '/notifications' ) : Urls::portal( 'notifications' );
		$open  = function ( $r ) use ( $team ) {
			return self::absolute_link( $r['link'], $team );
		};
		$one  = 1 === count( $rows );
		$html = '<p>Hi ' . esc_html( $u->first_name ? $u->first_name : $u->display_name ) . ',</p>';
		foreach ( $rows as $r ) {
			$html .= '<p style="margin:0 0 4px"><strong>' . esc_html( $r['title'] ) . '</strong></p>' . ( $r['body'] ? '<p style="margin:0 0 6px;color:#475467">' . nl2br( esc_html( $r['body'] ) ) . '</p>' : '' ) . ( $r['link'] ? '<p style="margin:0 0 18px"><a href="' . esc_url( $open( $r ) ) . '">Open</a></p>' : '' );
		}
		$html .= '<p style="color:#667085;font-size:13px">' . esc_html( $biz ) . ' · <a href="' . esc_url( $prefs ) . '">Choose which notifications you get</a></p>';
		$text  = '';
		foreach ( $rows as $r ) {
			$text .= $r['title'] . ( $r['body'] ? "\n" . $r['body'] : '' ) . ( $r['link'] ? "\n" . $open( $r ) : '' ) . "\n\n";
		}
		$res = Mailer::send( array( 'to' => array( $u->user_email ), 'subject' => $one ? $rows[0]['title'] : sprintf( '%s: %d updates', $biz, count( $rows ) ), 'html' => $html, 'text' => $text ) );
		if ( ! empty( $res['ok'] ) ) {
			$wpdb->query( 'UPDATE ' . self::t() . ' SET emailed_at=UTC_TIMESTAMP() WHERE id IN (' . implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) ) . ')' ); // phpcs:ignore WordPress.DB
		}
	}

	public static function absolute_link( $link, $team ) {
		if ( '' === $link ) {
			return $team ? Urls::app() : Urls::portal();
		}
		if ( 0 === strpos( $link, 'http' ) ) {
			return $link;
		}
		return Urls::app( $link );
	}

	/** One e-mail per person with everything waiting for a digest. */
	public static function send_digests() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( 'SELECT id, user_id, type FROM ' . self::t() . ' WHERE emailed_at IS NULL ORDER BY id LIMIT 2000', ARRAY_A ); // phpcs:ignore WordPress.DB
		$by   = array();
		foreach ( $rows as $r ) {
			if ( 'digest' === self::pref( $r['user_id'], $r['type'] )['email'] ) {
				$by[ (int) $r['user_id'] ][] = (int) $r['id'];
			}
		}
		foreach ( $by as $ids ) {
			self::send_ids( $ids );
		}
	}

	/* ───────────────────────── Reading ───────────────────────── */

	public static function list_for( $user_id, $filter = 'all', $limit = 30, $before = 0 ) {
		global $wpdb;
		$where = $wpdb->prepare( 'n.user_id=%d AND n.visible=1', $user_id ); // phpcs:ignore WordPress.DB
		if ( 'unread' === $filter ) {
			$where .= ' AND n.read_at IS NULL';
		}
		if ( $before ) {
			$where .= $wpdb->prepare( ' AND n.id < %d', $before ); // phpcs:ignore WordPress.DB
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT n.*, c.name AS client_name FROM ' . self::t() . ' n LEFT JOIN ' . Schema::table( 'bizy_clients' ) . " c ON c.id=n.client_id WHERE $where ORDER BY n.id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$cat  = self::catalog();
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array( 'id' => (int) $r['id'], 'type' => $r['type'], 'group' => isset( $cat[ $r['type'] ] ) ? $cat[ $r['type'] ][2] : '', 'title' => $r['title'], 'body' => (string) $r['body'], 'link' => $r['link'], 'client_id' => (int) $r['client_id'], 'client_name' => (string) $r['client_name'], 'at' => $r['created_at'], 'read' => null !== $r['read_at'] );
		}
		return $out;
	}

	public static function unread_count( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t() . ' WHERE user_id=%d AND visible=1 AND read_at IS NULL', $user_id ) ); // phpcs:ignore WordPress.DB
	}

	public static function mark_read( $user_id, $ids = null ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( null === $ids ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET read_at=%s WHERE user_id=%d AND read_at IS NULL', $now, $user_id ) ); // phpcs:ignore WordPress.DB
		} elseif ( $ids ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET read_at=%s WHERE user_id=%d AND read_at IS NULL AND id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')', $now, $user_id ) ); // phpcs:ignore WordPress.DB
		}
		return self::unread_count( $user_id );
	}

	/** One notification of this person's, for "open it" redirects. */
	public static function get_own( $user_id, $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id=%d AND user_id=%d', $id, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** Old read notifications are cleared after 120 days. */
	public static function prune() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t() . ' WHERE created_at < %s AND (read_at IS NOT NULL OR emailed_at IS NOT NULL)', gmdate( 'Y-m-d H:i:s', strtotime( '-120 days' ) ) ) ); // phpcs:ignore WordPress.DB
	}

	/* ───────────────────────── Who to tell (helpers for modules) ───────────────────────── */

	/** WordPress user ids of a client's portal logins. */
	public static function client_users( $client_id ) {
		return array_map( 'intval', Portal_Users::users_for_client( (int) $client_id, false ) );
	}

	/** WordPress user ids that hold a capability (for "everyone who handles billing"). */
	public static function users_with( $cap ) {
		return array_map( 'intval', get_users( array( 'capability' => $cap, 'fields' => 'ID' ) ) );
	}

	/** The WordPress user behind a team member id, or 0. */
	public static function team_user( $team_member_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT wp_user_id FROM ' . Schema::table( 'bizy_team_members' ) . ' WHERE id=%d', $team_member_id ) ); // phpcs:ignore WordPress.DB
	}

	/* ───────────────────────── Wiring ───────────────────────── */

	public static function init() {
		add_action( 'bizycore_ready', array( __CLASS__, 'collect' ), 6 );
		add_action( 'bizycore_daily', array( __CLASS__, 'daily' ), 99 );
	}

	public static function collect() {
		self::register( 'client_message', array( 'label' => 'Message', 'audience' => 'client', 'group' => 'Messages', 'description' => 'You have a new message', 'email' => 'off', 'external' => true ) );
		self::register( 'team_message', array( 'label' => 'Message', 'audience' => 'team', 'group' => 'Messages', 'description' => 'A client sent a message', 'email' => 'off', 'external' => true ) );
		/** Modules register their own kinds here with Notifications::register(). */
		do_action( 'bizycore_register_notifications' );
	}

	/** Runs once a day after every module's own reminders (they hook `bizycore_daily` earlier): send digests, then tidy up. */
	public static function daily() {
		self::send_digests();
		self::prune();
	}
}
