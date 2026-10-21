<?php
namespace WPFlatCore;

defined( 'ABSPATH' ) || exit;

/**
 * Connections between records of different modules — an invoice attached to a task or project, a contract to an
 * invoice, a catalog item to a task — without any module holding a column that points at another module's table.
 *
 * Each module registers the record types it owns ("linkable types"); links are stored once in wpflat_links and are
 * readable from both ends. A type whose module is off or missing simply stops appearing in pickers; the links stay
 * in the table and come back if the module returns.
 */
class Links {

	/** @var array type => definition */
	private static $types = array();

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpflat_links';
	}

	public static function init() {
		add_action( 'wpflatcore_ready', array( __CLASS__, 'collect_types' ), 5 );
		add_action( 'wpflatcore_record_deleted', array( __CLASS__, 'forget' ), 10, 2 );
	}

	public static function collect_types() {
		/**
		 * Modules hook here and call Links::register_type().
		 */
		do_action( 'wpflatcore_register_link_types' );
	}

	/**
	 * @param string $type  e.g. 'invoice'
	 * @param array  $def   module (owner id), label, plural, resolve( id ) => array( title, url?, sub? ) | null,
	 *                      search( query, limit ) => array( array( id, title, sub? ) )
	 */
	public static function register_type( $type, array $def ) {
		if ( ! preg_match( '/^[a-z][a-z0-9_]{1,38}$/', $type ) || empty( $def['module'] ) ) {
			return false;
		}
		self::$types[ $type ] = $def + array( 'label' => ucfirst( $type ), 'plural' => ucfirst( $type ) . 's', 'resolve' => null, 'search' => null );
		return true;
	}

	/** Types that can be picked right now: registered and their module is enabled. */
	public static function available_types() {
		$out = array();
		foreach ( self::$types as $type => $d ) {
			if ( 'core' === $d['module'] || Registry::enabled( $d['module'] ) ) {
				$out[ $type ] = array( 'label' => $d['label'], 'plural' => $d['plural'], 'module' => $d['module'] );
			}
		}
		return $out;
	}

	public static function is_available( $type ) {
		$a = self::available_types();
		return isset( $a[ $type ] );
	}

	/** Look up a record for display; null when unknown or its module is off. */
	public static function describe( $type, $id ) {
		if ( ! self::is_available( $type ) || ! is_callable( self::$types[ $type ]['resolve'] ) ) {
			return null;
		}
		$r = call_user_func( self::$types[ $type ]['resolve'], (int) $id );
		if ( ! is_array( $r ) ) {
			return null;
		}
		return array( 'type' => $type, 'id' => (int) $id, 'label' => self::$types[ $type ]['label'] ) + $r + array( 'url' => '', 'sub' => '' );
	}

	/** Search one type for the "Link to…" picker. */
	public static function search( $type, $q, $limit = 10 ) {
		if ( ! self::is_available( $type ) || ! is_callable( self::$types[ $type ]['search'] ) ) {
			return array();
		}
		$rows = call_user_func( self::$types[ $type ]['search'], (string) $q, max( 1, min( 50, (int) $limit ) ) );
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/** Attach two records. Safe to call twice. */
	public static function add( $from_type, $from_id, $to_type, $to_id, $kind = 'related' ) {
		global $wpdb;
		$from_id = (int) $from_id;
		$to_id   = (int) $to_id;
		if ( $from_id < 1 || $to_id < 1 || ( $from_type === $to_type && $from_id === $to_id ) ) {
			return new \WP_Error( 'wpflatcore_link_bad', 'Pick two different records to link.', array( 'status' => 400 ) );
		}
		foreach ( array( $from_type, $to_type ) as $t ) {
			if ( ! isset( self::$types[ $t ] ) ) {
				return new \WP_Error( 'wpflatcore_link_type', 'Unknown record type: ' . $t, array( 'status' => 400 ) );
			}
		}
		$kind = sanitize_key( $kind ) ? sanitize_key( $kind ) : 'related';
		list( $ft, $fi, $tt, $ti ) = self::order( $from_type, $from_id, $to_type, $to_id );
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (from_type, from_id, to_type, to_id, kind, created_by, created_at) VALUES (%s,%d,%s,%d,%s,%d,%s)', $ft, $fi, $tt, $ti, $kind, get_current_user_id(), current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB
		do_action( 'wpflatcore_linked', $from_type, $from_id, $to_type, $to_id, $kind );
		return true;
	}

	public static function remove( $from_type, $from_id, $to_type, $to_id, $kind = 'related' ) {
		global $wpdb;
		list( $ft, $fi, $tt, $ti ) = self::order( $from_type, (int) $from_id, $to_type, (int) $to_id );
		$n = $wpdb->delete( self::table(), array( 'from_type' => $ft, 'from_id' => $fi, 'to_type' => $tt, 'to_id' => $ti, 'kind' => $kind ) );
		do_action( 'wpflatcore_unlinked', $from_type, $from_id, $to_type, $to_id, $kind );
		return (int) $n;
	}

	/** Everything linked to a record, from either end, only types that are available. Optionally one type. */
	public static function for_record( $type, $id, $only_type = '' ) {
		global $wpdb;
		$id   = (int) $id;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE (from_type=%s AND from_id=%d) OR (to_type=%s AND to_id=%d) ORDER BY id DESC', $type, $id, $type, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$mine   = ( $r['from_type'] === $type && (int) $r['from_id'] === $id );
			$ot     = $mine ? $r['to_type'] : $r['from_type'];
			$oi     = (int) ( $mine ? $r['to_id'] : $r['from_id'] );
			if ( ( '' !== $only_type && $ot !== $only_type ) || ! self::is_available( $ot ) ) {
				continue;
			}
			$d = self::describe( $ot, $oi );
			if ( $d ) {
				$out[] = $d + array( 'link_id' => (int) $r['id'], 'kind' => $r['kind'] );
			}
		}
		return $out;
	}

	/** Ids of one type linked to a record — handy for filters ("tasks of this invoice"). */
	public static function ids( $type, $id, $other_type ) {
		$ids = array();
		foreach ( self::for_record( $type, $id, $other_type ) as $d ) {
			$ids[] = $d['id'];
		}
		return $ids;
	}

	/** A record was deleted by its module: drop its links (the module fires wpflatcore_record_deleted). */
	public static function forget( $type, $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE (from_type=%s AND from_id=%d) OR (to_type=%s AND to_id=%d)', $type, (int) $id, $type, (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/** One stored direction per pair so a link is never duplicated (alphabetical by type, then id). */
	private static function order( $ft, $fi, $tt, $ti ) {
		if ( strcmp( $ft, $tt ) > 0 || ( $ft === $tt && $fi > $ti ) ) {
			return array( $tt, $ti, $ft, $fi );
		}
		return array( $ft, $fi, $tt, $ti );
	}
}
