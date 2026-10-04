<?php
namespace BizyCore\Services;

use BizyCore\Database\Schema;
use BizyCore\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Client ↔ team conversations with file sharing. One conversation ("thread") belongs to one client and can be tied to
 * one record of a module (a project, an invoice…); the team and every login of that client see the same thread. Files live in a protected folder and are only
 * ever served after checking who is asking.
 *
 * Every public method takes an "actor": array( type => staff|client, user => id, clients => int[]|null ). Staff see all
 * clients; a client actor can only ever reach threads of the clients linked to their login.
 */
class Messages {

	const BODY_MAX   = 5000;
	const MAX_FILES  = 10;
	const DELETE_WIN = 900; // seconds a sender can still delete their own message
	const FILE_PARAM = 'bizycore_msg_file';

	/* ───────────────────────── Storage ───────────────────────── */

	private static function t( $n ) {
		return Schema::table( $n );
	}

	public static function dir() {
		return \BizyCore\Paths::dir( 'messages' );
	}

	/** Extensions clients and staff may share. Anything that can run on a server or in a browser is out. */
	const ALLOWED = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'csv', 'rtf', 'zip', 'mp3', 'wav', 'm4a', 'mp4', 'mov', 'webm', 'psd', 'ai', 'fig', 'sketch', 'eps', 'key', 'pages', 'numbers', 'md', 'json' );
	const IMAGES  = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	public static function max_bytes() {
		$mb = max( 1, (int) Settings::get( 'messages_max_mb' ) );
		return (int) min( $mb * MB_IN_BYTES, wp_max_upload_size() ?: $mb * MB_IN_BYTES );
	}

	/* ───────────────────────── Who is asking ───────────────────────── */

	/** The current user as an actor, or null when they may not use messages. */
	public static function actor() {
		if ( ! is_user_logged_in() ) {
			return null;
		}
		$uid = get_current_user_id();
		if ( current_user_can( 'manage_bizycore' ) && ( current_user_can( 'bizycore_messages' ) || current_user_can( 'manage_options' ) ) ) {
			return array( 'type' => 'staff', 'user' => $uid, 'clients' => null );
		}
		if ( current_user_can( 'bizycore_portal' ) && \BizyCore\Portal::enabled() && \BizyCore\Portal::messages_on() ) {
			$ids = Portal_Users::client_ids( $uid );
			if ( $ids ) {
				return array( 'type' => 'client', 'user' => $uid, 'clients' => $ids );
			}
		}
		return null;
	}

	public static function can_use() {
		return null !== self::actor();
	}

	private static function sees_client( array $a, $client_id ) {
		return null === $a['clients'] || in_array( (int) $client_id, $a['clients'], true );
	}

	/* ───────────────────────── Threads ───────────────────────── */

	private static function thread_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'bizy_threads' ) . ' WHERE id=%d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** A thread the actor may see, or null. */
	private static function visible_thread( array $a, $id ) {
		$th = self::thread_row( (int) $id );
		return ( $th && self::sees_client( $a, $th['client_id'] ) ) ? $th : null;
	}

	/** Unread messages in a thread for this actor: from the other side, newer than what they last read. */
	private static function unread_sql( array $a ) {
		global $wpdb;
		$other = 'staff' === $a['type'] ? 'client' : 'staff';
		return $wpdb->prepare( '(SELECT COUNT(*) FROM ' . self::t( 'bizy_messages' ) . ' m WHERE m.thread_id=th.id AND m.sender_type=%s AND m.deleted_at IS NULL AND m.id > COALESCE((SELECT r.last_read_id FROM ' . self::t( 'bizy_thread_reads' ) . ' r WHERE r.thread_id=th.id AND r.user_id=%d),0))', $other, $a['user'] ); // phpcs:ignore WordPress.DB
	}

	public static function list_threads( array $a, array $f = array() ) {
		global $wpdb;
		$th    = self::t( 'bizy_threads' );
		$where = array( '1=1' );
		$args  = array();
		if ( null !== $a['clients'] ) {
			$ids     = $a['clients'];
			$want    = ! empty( $f['client_id'] ) ? (int) $f['client_id'] : 0;
			$where[] = 'th.client_id IN (' . implode( ',', array_map( 'intval', $want && in_array( $want, $ids, true ) ? array( $want ) : $ids ) ) . ')';
		} elseif ( ! empty( $f['client_id'] ) ) {
			$where[] = 'th.client_id=%d';
			$args[]  = (int) $f['client_id'];
		}
		if ( ! empty( $f['context_type'] ) && ! empty( $f['context_id'] ) ) {
			$where[] = '(th.context_type=%s AND th.context_id=%d)';
			$args[]  = (string) $f['context_type'];
			$args[]  = (int) $f['context_id'];
		}
		$st = isset( $f['status'] ) ? $f['status'] : 'open';
		if ( in_array( $st, array( 'open', 'closed' ), true ) ) {
			$where[] = 'th.status=%s';
			$args[]  = $st;
		}
		if ( ! empty( $f['q'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $f['q'] ) . '%';
			$where[] = '(th.subject LIKE %s OR c.name LIKE %s OR EXISTS (SELECT 1 FROM ' . self::t( 'bizy_messages' ) . ' mm WHERE mm.thread_id=th.id AND mm.deleted_at IS NULL AND mm.body LIKE %s))';
			array_push( $args, $like, $like, $like );
		}
		$unread = self::unread_sql( $a );
		$sql    = "SELECT th.*, c.name AS client_name, $unread AS unread,
				(SELECT m.body FROM " . self::t( 'bizy_messages' ) . ' m WHERE m.id=th.last_message_id) AS last_body,
				(SELECT m.sender_type FROM ' . self::t( 'bizy_messages' ) . ' m WHERE m.id=th.last_message_id) AS last_sender,
				(SELECT COUNT(*) FROM ' . self::t( 'bizy_message_files' ) . ' f WHERE f.message_id=th.last_message_id) AS last_files
			FROM ' . $th . ' th LEFT JOIN ' . self::t( 'bizy_clients' ) . ' c ON c.id=th.client_id 
			WHERE ' . implode( ' AND ', $where );
		if ( ! empty( $f['unread'] ) ) {
			$sql .= " HAVING unread > 0";
		}
		$sql .= ' ORDER BY COALESCE(th.last_message_at, th.created_at) DESC, th.id DESC LIMIT 200';
		$rows = (array) $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( $rows as $r ) {
			$prev = trim( preg_replace( '/\s+/', ' ', (string) $r['last_body'] ) );
			if ( '' === $prev && (int) $r['last_files'] > 0 ) {
				$prev = '📎 ' . (int) $r['last_files'] . ' file' . ( 1 === (int) $r['last_files'] ? '' : 's' );
			}
			$out[] = array(
				'id' => (int) $r['id'], 'client_id' => (int) $r['client_id'], 'client_name' => (string) $r['client_name'], 'context_type' => (string) $r['context_type'], 'context_id' => (int) $r['context_id'], 'context_name' => self::context_name( $r['context_type'], $r['context_id'] ),
				'subject' => $r['subject'], 'status' => $r['status'], 'last_message_at' => $r['last_message_at'], 'preview' => mb_substr( $prev, 0, 140 ), 'last_sender' => (string) $r['last_sender'],
				'unread' => (int) $r['unread'], 'count' => (int) $r['message_count'],
			);
		}
		return $out;
	}

	public static function unread_total( array $a ) {
		global $wpdb;
		$th    = self::t( 'bizy_threads' );
		$where = null === $a['clients'] ? '1=1' : 'th.client_id IN (' . implode( ',', array_map( 'intval', $a['clients'] ?: array( 0 ) ) ) . ')';
		return (int) $wpdb->get_var( 'SELECT COALESCE(SUM(u),0) FROM (SELECT ' . self::unread_sql( $a ) . " AS u FROM $th th WHERE $where AND th.status='open') x" ); // phpcs:ignore WordPress.DB
	}

	private static function sender_name( $type, $user_id ) {
		static $cache = array();
		$k = $type . $user_id;
		if ( ! isset( $cache[ $k ] ) ) {
			$u            = get_userdata( (int) $user_id );
			$cache[ $k ] = $u ? $u->display_name : ( 'staff' === $type ? 'Team' : 'Client' );
		}
		return $cache[ $k ];
	}

	private static function files_of( array $ids ) {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$rows = (array) $wpdb->get_results( 'SELECT id, message_id, name, mime, size FROM ' . self::t( 'bizy_message_files' ) . ' WHERE message_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ') ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB
		$by   = array();
		foreach ( $rows as $r ) {
			$by[ (int) $r['message_id'] ][] = array( 'id' => (int) $r['id'], 'name' => $r['name'], 'mime' => $r['mime'], 'size' => (int) $r['size'], 'image' => in_array( $r['mime'], self::IMAGES, true ), 'url' => self::file_url( (int) $r['id'] ) );
		}
		return $by;
	}

	/** One thread with its messages (all, or only those after $after for polling). */
	public static function get_thread( array $a, $id, $after = 0 ) {
		global $wpdb;
		$th = self::visible_thread( $a, $id );
		if ( ! $th ) {
			return new \WP_Error( 'bizycore_thread', 'That conversation was not found.', array( 'status' => 404 ) );
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, sender_id, sender_type, kind, body, created_at, deleted_at FROM ' . self::t( 'bizy_messages' ) . ' WHERE thread_id=%d AND id > %d ORDER BY id ASC LIMIT 300', $th['id'], (int) $after ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$files = self::files_of( array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
		$msgs  = array();
		foreach ( $rows as $m ) {
			$gone   = null !== $m['deleted_at'];
			$msgs[] = array(
				'id' => (int) $m['id'], 'mine' => (int) $m['sender_id'] === (int) $a['user'] && $m['sender_type'] === $a['type'], 'side' => $m['sender_type'], 'kind' => $m['kind'], 'name' => 'system' === $m['kind'] ? '' : self::sender_name( $m['sender_type'], $m['sender_id'] ),
				'body' => $gone ? '' : (string) $m['body'], 'deleted' => $gone, 'at' => $m['created_at'], 'files' => $gone ? array() : ( $files[ (int) $m['id'] ] ?? array() ),
				'can_delete' => ! $gone && 'system' !== $m['kind'] && (int) $m['sender_id'] === (int) $a['user'] && $m['sender_type'] === $a['type'] && time() - strtotime( $m['created_at'] . ' UTC' ) < self::DELETE_WIN,
			);
		}
		// What the other side has read, and whether they are typing right now.
		$other      = 'staff' === $a['type'] ? 'client' : 'staff';
		$other_read = 0;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, last_read_id FROM ' . self::t( 'bizy_thread_reads' ) . ' WHERE thread_id=%d', $th['id'] ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$u = get_userdata( (int) $r['user_id'] );
			if ( $u && ( 'staff' === $other ) === ( user_can( $u, 'manage_bizycore' ) ) ) {
				$other_read = max( $other_read, (int) $r['last_read_id'] );
			}
		}
		$typing = get_transient( 'bizycore_typing_' . $th['id'] . '_' . $other );
		$proj   = self::context_name( $th['context_type'], $th['context_id'] );
		$cl     = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . self::t( 'bizy_clients' ) . ' WHERE id=%d', $th['client_id'] ) ); // phpcs:ignore WordPress.DB
		return array(
			'thread' => array( 'id' => (int) $th['id'], 'client_id' => (int) $th['client_id'], 'client_name' => $cl, 'context_type' => (string) $th['context_type'], 'context_id' => (int) $th['context_id'], 'context_name' => $proj, 'subject' => $th['subject'], 'status' => $th['status'], 'count' => (int) $th['message_count'] ),
			'messages' => $msgs, 'other_read' => $other_read, 'typing' => $typing ? (string) $typing : '', 'unread_total' => self::unread_total( $a ),
		);
	}

	/**
	 * What a conversation can be about, for the "About" picker: array( array( value => 'project:12', name, group ) ).
	 * Modules add their records with the `bizycore_message_contexts` filter (BizyTasks: projects, BizyMoney: invoices…).
	 */
	public static function contexts_for( array $a, $client_id ) {
		if ( ! self::sees_client( $a, $client_id ) ) {
			return array();
		}
		$list = apply_filters( 'bizycore_message_contexts', array(), (int) $client_id );
		$out  = array();
		foreach ( (array) $list as $c ) {
			if ( ! empty( $c['type'] ) && ! empty( $c['id'] ) && isset( $c['name'] ) ) {
				$out[] = array( 'value' => $c['type'] . ':' . (int) $c['id'], 'name' => (string) $c['name'], 'group' => isset( $c['group'] ) ? (string) $c['group'] : '' );
			}
		}
		return $out;
	}

	/** Display name of what a conversation is about ('' for a general conversation or an unavailable module). */
	public static function context_name( $type, $id ) {
		if ( '' === (string) $type || ! (int) $id ) {
			return '';
		}
		return (string) apply_filters( 'bizycore_message_context_name', '', (string) $type, (int) $id );
	}

	/** Address of a message file. Plain query address; the stealth layer serves it under a neutral path. */
	public static function file_url( $id ) {
		return apply_filters( 'bizycore_message_file_url', add_query_arg( self::FILE_PARAM, (int) $id, home_url( '/' ) ), (int) $id );
	}

	/* ───────────────────────── Writing ───────────────────────── */

	/** Normalise PHP's $_FILES shape for a multi-file field into a list of single files. */
	public static function normalize_files( $f ) {
		$out = array();
		if ( empty( $f ) || ! isset( $f['name'] ) ) {
			return $out;
		}
		if ( is_array( $f['name'] ) ) {
			foreach ( $f['name'] as $i => $n ) {
				$out[] = array( 'name' => $n, 'tmp_name' => $f['tmp_name'][ $i ], 'error' => $f['error'][ $i ], 'size' => $f['size'][ $i ] );
			}
		} else {
			$out[] = $f;
		}
		return array_values( array_filter( $out, function ( $x ) {
			return UPLOAD_ERR_NO_FILE !== (int) $x['error'];
		} ) );
	}

	/** Check and store uploads. @return array|\WP_Error list of stored file descriptors */
	private static function store_files( array $files ) {
		if ( count( $files ) > self::MAX_FILES ) {
			return new \WP_Error( 'bizycore_files', 'You can attach up to ' . self::MAX_FILES . ' files to one message.', array( 'status' => 400 ) );
		}
		$max = self::max_bytes();
		$out = array();
		foreach ( $files as $f ) {
			$name = sanitize_file_name( wp_unslash( (string) $f['name'] ) );
			if ( UPLOAD_ERR_OK !== (int) $f['error'] ) {
				self::discard( $out );
				return new \WP_Error( 'bizycore_files', '“' . $name . '” could not be uploaded' . ( in_array( (int) $f['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? ' (it is too large for this server).' : '.' ), array( 'status' => 400 ) );
			}
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, self::ALLOWED, true ) ) {
				self::discard( $out );
				return new \WP_Error( 'bizycore_files', '“' . $name . '” is not an allowed file type. Allowed: images, PDF, Office and text documents, spreadsheets, audio, video and ZIP.', array( 'status' => 400 ) );
			}
			if ( (int) $f['size'] > $max || (int) $f['size'] <= 0 ) {
				self::discard( $out );
				return new \WP_Error( 'bizycore_files', '“' . $name . '” is larger than the ' . size_format( $max ) . ' limit.', array( 'status' => 400 ) );
			}
			$mime = '';
			if ( function_exists( 'finfo_open' ) ) {
				$fi   = finfo_open( FILEINFO_MIME_TYPE );
				$mime = (string) finfo_file( $fi, $f['tmp_name'] );
				finfo_close( $fi );
			}
			if ( preg_match( '#(php|x-httpd|javascript|x-sh|x-msdownload|html|x-executable)#i', $mime ) ) {
				self::discard( $out );
				return new \WP_Error( 'bizycore_files', '“' . $name . '” looks like a program or web page, which cannot be shared here.', array( 'status' => 400 ) );
			}
			if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) && ! in_array( $mime, self::IMAGES, true ) ) {
				self::discard( $out );
				return new \WP_Error( 'bizycore_files', '“' . $name . '” is not a real image.', array( 'status' => 400 ) );
			}
			$stored = bin2hex( random_bytes( 16 ) );
			$dest   = self::dir() . '/' . $stored;
			if ( ! ( is_uploaded_file( $f['tmp_name'] ) ? move_uploaded_file( $f['tmp_name'], $dest ) : copy( $f['tmp_name'], $dest ) ) ) {
				self::discard( $out );
				return new \WP_Error( 'bizycore_files', 'Could not save “' . $name . '”.', array( 'status' => 500 ) );
			}
			chmod( $dest, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$out[] = array( 'file' => $stored, 'name' => mb_substr( $name, 0, 190 ), 'mime' => $mime ? $mime : 'application/octet-stream', 'size' => (int) $f['size'], 'sha256' => hash_file( 'sha256', $dest ) );
		}
		return $out;
	}

	private static function discard( array $stored ) {
		foreach ( $stored as $s ) {
			@unlink( self::dir() . '/' . $s['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
	}

	private static function clean_body( $b ) {
		$b = trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $b ) );
		$b = preg_replace( "/\n{4,}/", "\n\n\n", $b );
		return wp_check_invalid_utf8( $b );
	}

	/** Insert a message (and files) and update the thread. @return int|\WP_Error message id */
	private static function insert_message( array $a, array $th, $body, array $files, $kind = 'text' ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert( self::t( 'bizy_messages' ), array( 'thread_id' => $th['id'], 'client_id' => $th['client_id'], 'sender_id' => $a['user'], 'sender_type' => $a['type'], 'kind' => $kind, 'body' => $body, 'created_at' => $now ) );
		if ( false === $ok ) {
			self::discard( $files );
			return new \WP_Error( 'bizycore_db', 'The message could not be saved.', array( 'status' => 500 ) );
		}
		$mid = (int) $wpdb->insert_id;
		foreach ( $files as $f ) {
			$wpdb->insert( self::t( 'bizy_message_files' ), array( 'message_id' => $mid, 'thread_id' => $th['id'], 'client_id' => $th['client_id'], 'file' => $f['file'], 'name' => $f['name'], 'mime' => $f['mime'], 'size' => $f['size'], 'sha256' => $f['sha256'], 'created_at' => $now ) );
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'bizy_threads' ) . " SET last_message_at=%s, last_message_id=%d, message_count=message_count+1, updated_at=%s WHERE id=%d", $now, $mid, $now, $th['id'] ) ); // phpcs:ignore WordPress.DB
		return $mid;
	}

	public static function create_thread( array $a, $client_id, $context, $subject, $body, array $files ) {
		global $wpdb;
		$client_id  = (int) $client_id;
		$ctype      = '';
		$cid        = 0;
		if ( preg_match( '/^([a-z][a-z0-9_]{1,38}):(\d+)$/', (string) $context, $cm ) ) {
			$ctype = $cm[1];
			$cid   = (int) $cm[2];
		}
		if ( 'client' === $a['type'] && ! $client_id && count( $a['clients'] ) === 1 ) {
			$client_id = (int) $a['clients'][0];
		}
		if ( ! $client_id || ! self::sees_client( $a, $client_id ) ) {
			return new \WP_Error( 'bizycore_client', 'Choose who this conversation is with.', array( 'status' => 400 ) );
		}
		if ( $cid && ! in_array( $ctype . ':' . $cid, wp_list_pluck( self::contexts_for( $a, $client_id ), 'value' ), true ) ) {
			return new \WP_Error( 'bizycore_context', 'That does not belong to this client.', array( 'status' => 400 ) );
		}
		$body = self::clean_body( $body );
		if ( '' === $body && ! $files ) {
			return new \WP_Error( 'bizycore_empty', 'Write a message or attach a file.', array( 'status' => 400 ) );
		}
		if ( mb_strlen( $body ) > self::BODY_MAX ) {
			return new \WP_Error( 'bizycore_long', 'That message is too long (limit ' . self::BODY_MAX . ' characters).', array( 'status' => 400 ) );
		}
		$stored = self::store_files( $files );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$subject = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $subject ) ) );
		if ( '' === $subject ) {
			$subject = $cid ? self::context_name( $ctype, $cid ) : mb_substr( $body, 0, 60 );
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( self::t( 'bizy_threads' ), array( 'client_id' => $client_id, 'context_type' => $ctype, 'context_id' => $cid, 'subject' => mb_substr( '' !== $subject ? $subject : 'New conversation', 0, 190 ), 'status' => 'open', 'started_by_type' => $a['type'], 'created_by' => $a['user'], 'created_at' => $now, 'updated_at' => $now ) );
		$tid = (int) $wpdb->insert_id;
		$th  = self::thread_row( $tid );
		$mid = self::insert_message( $a, $th, $body, $stored );
		if ( is_wp_error( $mid ) ) {
			$wpdb->delete( self::t( 'bizy_threads' ), array( 'id' => $tid ) );
			return $mid;
		}
		self::mark_read( $a, $tid );
		Activity::log( 'messages', $tid, $client_id, 'thread', 'Conversation started: ' . $th['subject'] );
		self::notify_later( $tid, $mid, $a );
		return array( 'thread_id' => $tid, 'message_id' => $mid );
	}

	public static function post_message( array $a, $thread_id, $body, array $files ) {
		global $wpdb;
		$th = self::visible_thread( $a, $thread_id );
		if ( ! $th ) {
			return new \WP_Error( 'bizycore_thread', 'That conversation was not found.', array( 'status' => 404 ) );
		}
		$body = self::clean_body( $body );
		if ( '' === $body && ! $files ) {
			return new \WP_Error( 'bizycore_empty', 'Write a message or attach a file.', array( 'status' => 400 ) );
		}
		if ( mb_strlen( $body ) > self::BODY_MAX ) {
			return new \WP_Error( 'bizycore_long', 'That message is too long (limit ' . self::BODY_MAX . ' characters).', array( 'status' => 400 ) );
		}
		// Flood guard: a person cannot post more than 20 messages a minute.
		$k = 'bizycore_msgrate_' . $a['user'];
		$n = (int) get_transient( $k );
		if ( $n >= 20 ) {
			return new \WP_Error( 'bizycore_rate', 'You are sending messages very fast. Please wait a moment.', array( 'status' => 429 ) );
		}
		set_transient( $k, $n + 1, MINUTE_IN_SECONDS );
		$stored = self::store_files( $files );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		if ( 'closed' === $th['status'] ) {
			$wpdb->update( self::t( 'bizy_threads' ), array( 'status' => 'open', 'closed_at' => null ), array( 'id' => $th['id'] ) );
			self::insert_message( $a, $th, 'Conversation reopened', array(), 'system' );
		}
		$mid = self::insert_message( $a, $th, $body, $stored );
		if ( is_wp_error( $mid ) ) {
			return $mid;
		}
		self::mark_read( $a, $th['id'] );
		delete_transient( 'bizycore_typing_' . $th['id'] . '_' . $a['type'] );
		self::notify_later( (int) $th['id'], $mid, $a );
		return array( 'message_id' => $mid );
	}

	public static function mark_read( array $a, $thread_id ) {
		global $wpdb;
		$th = self::visible_thread( $a, $thread_id );
		if ( ! $th ) {
			return false;
		}
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::t( 'bizy_thread_reads' ) . ' (thread_id, user_id, last_read_id, read_at) VALUES (%d,%d,%d,%s) ON DUPLICATE KEY UPDATE last_read_id=GREATEST(last_read_id, VALUES(last_read_id)), read_at=VALUES(read_at)', $th['id'], $a['user'], (int) $th['last_message_id'], gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB
		return true;
	}

	public static function typing( array $a, $thread_id ) {
		$th = self::visible_thread( $a, $thread_id );
		if ( $th ) {
			set_transient( 'bizycore_typing_' . $th['id'] . '_' . $a['type'], self::sender_name( $a['type'], $a['user'] ), 6 );
		}
		return true;
	}

	public static function set_status( array $a, $thread_id, $status ) {
		global $wpdb;
		if ( 'staff' !== $a['type'] ) {
			return new \WP_Error( 'bizycore_forbidden', 'Only the team can close a conversation.', array( 'status' => 403 ) );
		}
		$th = self::visible_thread( $a, $thread_id );
		if ( ! $th ) {
			return new \WP_Error( 'bizycore_thread', 'That conversation was not found.', array( 'status' => 404 ) );
		}
		$status = 'closed' === $status ? 'closed' : 'open';
		if ( $th['status'] !== $status ) {
			$wpdb->update( self::t( 'bizy_threads' ), array( 'status' => $status, 'closed_at' => 'closed' === $status ? gmdate( 'Y-m-d H:i:s' ) : null ), array( 'id' => $th['id'] ) );
			self::insert_message( $a, $th, 'closed' === $status ? 'Conversation closed' : 'Conversation reopened', array(), 'system' );
		}
		return array( 'status' => $status );
	}

	public static function delete_message( array $a, $message_id ) {
		global $wpdb;
		$m = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'bizy_messages' ) . ' WHERE id=%d', $message_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $m || ! self::visible_thread( $a, $m['thread_id'] ) ) {
			return new \WP_Error( 'bizycore_missing', 'That message was not found.', array( 'status' => 404 ) );
		}
		$own = (int) $m['sender_id'] === (int) $a['user'] && $m['sender_type'] === $a['type'];
		if ( 'system' === $m['kind'] || ! $own || time() - strtotime( $m['created_at'] . ' UTC' ) >= self::DELETE_WIN ) {
			return new \WP_Error( 'bizycore_forbidden', 'You can only delete your own message for a few minutes after sending it.', array( 'status' => 403 ) );
		}
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT file FROM ' . self::t( 'bizy_message_files' ) . ' WHERE message_id=%d', $m['id'] ) ) as $f ) { // phpcs:ignore WordPress.DB
			@unlink( self::dir() . '/' . basename( $f ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
		$wpdb->delete( self::t( 'bizy_message_files' ), array( 'message_id' => $m['id'] ) );
		$wpdb->update( self::t( 'bizy_messages' ), array( 'body' => '', 'deleted_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $m['id'] ) );
		return array( 'deleted' => true );
	}

	/** Everything that belongs to a client's conversations goes with the client. */
	public static function purge_client( $client_id ) {
		global $wpdb;
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT file FROM ' . self::t( 'bizy_message_files' ) . ' WHERE client_id=%d', $client_id ) ) as $f ) { // phpcs:ignore WordPress.DB
			@unlink( self::dir() . '/' . basename( $f ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
		$ids = implode( ',', array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'bizy_threads' ) . ' WHERE client_id=%d', $client_id ) ) ) ); // phpcs:ignore WordPress.DB
		foreach ( array( 'message_files', 'messages' ) as $t ) {
			$wpdb->delete( self::t( 'bizy_' . $t ), array( 'client_id' => (int) $client_id ) );
		}
		if ( '' !== $ids ) {
			$wpdb->query( 'DELETE FROM ' . self::t( 'bizy_thread_reads' ) . " WHERE thread_id IN ($ids)" ); // phpcs:ignore WordPress.DB
		}
		$wpdb->delete( self::t( 'bizy_threads' ), array( 'client_id' => (int) $client_id ) );
	}

	/* ───────────────────────── Serving files ───────────────────────── */

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 0 );
		add_action( 'bizycore_message_notify', array( __CLASS__, 'send_notifications' ), 10, 3 );
		add_action( 'bizycore_client_deleted', array( __CLASS__, 'purge_client' ) );
	}

	public static function maybe_serve() {
		if ( empty( $_GET[ self::FILE_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		self::serve( absint( $_GET[ self::FILE_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/** Stream one message file after checking that this person may see that client's conversations. */
	public static function serve( $file_id ) {
		global $wpdb;
		$a = self::actor();
		$f = $a ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'bizy_message_files' ) . ' WHERE id=%d', (int) $file_id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB
		if ( ! $a || ! $f || ! self::sees_client( $a, $f['client_id'] ) ) {
			wp_die( 'You do not have access to this file.', 'Not allowed', array( 'response' => 403 ) );
		}
		$path = self::dir() . '/' . basename( $f['file'] );
		if ( ! is_readable( $path ) ) {
			wp_die( 'That file is no longer available.', 'Not found', array( 'response' => 404 ) );
		}
		$inline = ! empty( $_GET['inline'] ) && in_array( $f['mime'], self::IMAGES, true ); // phpcs:ignore WordPress.Security.NonceVerification
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Cache-Control: private, max-age=3600' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		header( 'Content-Type: ' . ( $inline ? $f['mime'] : 'application/octet-stream' ) );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . str_replace( array( '"', "\r", "\n" ), '', $f['name'] ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ───────────────────────── Notifications ───────────────────────── */

	/** Send e-mails after the response has gone out, so posting never waits for the mail server. */
	private static function notify_later( $thread_id, $message_id, array $a ) {
		$args = array( (int) $thread_id, (int) $message_id, $a['type'] );
		add_action( 'shutdown', function () use ( $args ) {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			}
			do_action( 'bizycore_message_notify', $args[0], $args[1], $args[2] );
		} );
	}

	public static function send_notifications( $thread_id, $message_id, $from_type ) {
		global $wpdb;
		$th = self::thread_row( $thread_id );
		$m  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'bizy_messages' ) . ' WHERE id=%d', $message_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $th || ! $m ) {
			return;
		}
		self::inapp( $th, $m, $from_type );
		$side = 'client' === $from_type ? 'staff' : 'client';
		$key  = 'bizycore_mn_' . $thread_id . '_' . $side;
		if ( get_transient( $key ) ) {
			return; // one e-mail per conversation per side every ten minutes
		}
		$biz   = (string) Settings::get( 'business_name' );
		$biz   = $biz ? $biz : wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$who   = self::sender_name( $m['sender_type'], $m['sender_id'] );
		$files = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'bizy_message_files' ) . ' WHERE message_id=%d', $message_id ) ); // phpcs:ignore WordPress.DB
		$text  = trim( (string) $m['body'] );
		$prev  = esc_html( mb_substr( $text, 0, 400 ) ) . ( mb_strlen( $text ) > 400 ? '…' : '' );
		if ( $files ) {
			$prev .= ( '' !== $prev ? '<br><br>' : '' ) . '📎 ' . $files . ' file' . ( 1 === $files ? '' : 's' ) . ' attached';
		}
		$to = array();
		if ( 'staff' === $side ) {
			$list = (string) Settings::get( 'messages_notify' );
			foreach ( array_filter( array_map( 'trim', explode( ',', $list ) ) ) as $e ) {
				$to[] = $e;
			}
			if ( ! $to ) {
				$to[] = get_option( 'admin_email' );
			}
			foreach ( self::context_staff( $th ) as $su ) {
				$ou = get_userdata( (int) $su );
				if ( $ou ) {
					$to[] = $ou->user_email;
				}
			}
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT sender_id FROM ' . self::t( 'bizy_messages' ) . " WHERE thread_id=%d AND sender_type='staff' AND kind='text'", $thread_id ) ) as $sid ) { // phpcs:ignore WordPress.DB
				$su = get_userdata( (int) $sid );
				if ( $su ) {
					$to[] = $su->user_email;
				}
			}
			$link = \BizyCore\Urls::app( '/messages/' . (int) $thread_id );
			$subj = 'New message from ' . ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . self::t( 'bizy_clients' ) . ' WHERE id=%d', $th['client_id'] ) ) ?: 'a client' ) . ': ' . $th['subject']; // phpcs:ignore WordPress.DB
			$to   = array_values( array_unique( array_filter( $to, 'is_email' ) ) );
			$to   = array_values( array_filter( $to, function ( $e ) use ( $m ) {
				$u = get_user_by( 'email', $e );
				return ! $u || (int) $u->ID !== (int) $m['sender_id'];
			} ) );
			self::mail( $to, $subj, $who, $prev, $link, $biz );
		} else {
			foreach ( Portal_Users::users_for_client( (int) $th['client_id'] ) as $u ) {
				if ( (int) $u['id'] === (int) $m['sender_id'] || '0' === (string) get_user_meta( $u['id'], 'bizycore_msg_notify', true ) ) {
					continue;
				}
				self::mail( array( $u['email'] ), $biz . ': new message — ' . $th['subject'], $who, $prev, \BizyCore\Portal::url( 'messages/' . (int) $thread_id ), $biz );
			}
		}
		set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
	}

	/** Staff who own the thing a conversation is about (the project owner, say): WordPress user ids from the module. */
	private static function context_staff( array $th ) {
		if ( '' === (string) $th['context_type'] || ! (int) $th['context_id'] ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', (array) apply_filters( 'bizycore_message_context_staff', array(), (string) $th['context_type'], (int) $th['context_id'], $th ) ) ) );
	}

	/** The bell: one in-app notification per message for the people on the other side. */
	private static function inapp( array $th, array $m, $from_type ) {
		global $wpdb;
		$who  = self::sender_name( $m['sender_type'], $m['sender_id'] );
		$text = trim( preg_replace( '/\s+/', ' ', (string) $m['body'] ) );
		$body = '' !== $text ? mb_substr( $text, 0, 140 ) : '📎 Sent a file';
		if ( 'client' === $from_type ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT sender_id FROM ' . self::t( 'bizy_messages' ) . " WHERE thread_id=%d AND sender_type='staff' AND kind='text'", $th['id'] ) ) ); // phpcs:ignore WordPress.DB
			$ids = array_merge( $ids, self::context_staff( $th ) );
			if ( ! $ids ) {
				$ids = array_map( 'intval', get_users( array( 'capability' => 'manage_options', 'fields' => 'ID' ) ) );
			}
			Notifications::emit( 'team_message', $ids, 'New message from ' . $who, $th['subject'] . ': ' . $body, '/messages/' . (int) $th['id'], $th['client_id'], 'thread', $th['id'], 'msg:' . $m['id'] );
		} else {
			$ids = array_map( 'intval', Portal_Users::users_for_client( (int) $th['client_id'], false ) );
			Notifications::emit( 'client_message', $ids, 'New message from ' . $who, $th['subject'] . ': ' . $body, \BizyCore\Portal::url( 'messages/' . (int) $th['id'] ), $th['client_id'], 'thread', $th['id'], 'msg:' . $m['id'] );
		}
	}

	private static function mail( array $to, $subject, $who, $preview_html, $link, $biz ) {
		if ( ! $to ) {
			return;
		}
		$html = '<p><strong>' . esc_html( $who ) . '</strong> wrote:</p><blockquote style="margin:0 0 16px;padding:10px 14px;border-left:3px solid #d0d5dd;color:#344054">' . $preview_html . '</blockquote><p><a href="' . esc_url( $link ) . '" style="display:inline-block;padding:10px 18px;background:#14161d;color:#fff;border-radius:8px;text-decoration:none">Open the conversation</a></p><p style="color:#667085;font-size:13px">' . esc_html( $biz ) . ' · You will not get another email about this conversation for ten minutes, however many messages arrive.</p>';
		Mailer::send( array( 'to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $who . " wrote a message. Open it: {$link}" ) );
	}
}
