<?php
namespace BizyCore;

defined( 'ABSPATH' ) || exit;

/**
 * The owner's words for the things Bizy talks about: Clients can be Customers, Students, Learners, Employees,
 * Contractors…; Departments and the Team can be renamed too. Modules add their own keys (project, invoice…).
 * Everything the person reads — the app, portal, emails, PDFs, notifications — goes through here.
 */
class Terminology {

	const OPTION = 'bizycore_terms';

	/** @var array key => array( singular, plural ) defaults, filled by init() and by modules */
	private static $defaults = array();

	/** @var array|null saved overrides, key => array( s, p ) */
	private static $saved = null;

	/** Ready-made sets for the keys that make sense to rename. */
	const PRESETS = array(
		'client'     => array(
			'Clients'     => array( 'Client', 'Clients' ),
			'Customers'   => array( 'Customer', 'Customers' ),
			'Students'    => array( 'Student', 'Students' ),
			'Learners'    => array( 'Learner', 'Learners' ),
			'Employees'   => array( 'Employee', 'Employees' ),
			'Contractors' => array( 'Contractor', 'Contractors' ),
			'Members'     => array( 'Member', 'Members' ),
			'Patients'    => array( 'Patient', 'Patients' ),
		),
		'team'       => array(
			'Team'        => array( 'Team member', 'Team' ),
			'Staff'       => array( 'Staff member', 'Staff' ),
			'Instructors' => array( 'Instructor', 'Instructors' ),
			'Crew'        => array( 'Crew member', 'Crew' ),
		),
		'group'      => array(
			'Groups'  => array( 'Group', 'Groups' ),
			'Classes' => array( 'Class', 'Classes' ),
			'Cohorts' => array( 'Cohort', 'Cohorts' ),
			'Batches' => array( 'Batch', 'Batches' ),
			'Houses'  => array( 'House', 'Houses' ),
			'Wards'   => array( 'Ward', 'Wards' ),
		),
		'department' => array(
			'Departments' => array( 'Department', 'Departments' ),
			'Brands'      => array( 'Brand', 'Brands' ),
			'Classes'     => array( 'Class', 'Classes' ),
			'Programs'    => array( 'Program', 'Programs' ),
			'Divisions'   => array( 'Division', 'Divisions' ),
		),
	);

	public static function init() {
		self::$defaults = array(
			'client'     => array( 'Client', 'Clients' ),
			'team'       => array( 'Team member', 'Team' ),
			'department' => array( 'Department', 'Departments' ),
			'group'      => array( 'Group', 'Groups' ),
			'home'       => array( 'Home', 'Home' ),
		);
		add_action( 'bizycore_ready', array( __CLASS__, 'collect' ), 4 );
	}

	public static function collect() {
		/** Modules hook here and call Terminology::register( 'project', 'Project', 'Projects' ). */
		do_action( 'bizycore_register_terms' );
	}

	/** A module declares a word it uses. Owners can rename it only if $renamable. */
	public static function register( $key, $singular, $plural, $renamable = true ) {
		if ( ! isset( self::$defaults[ $key ] ) ) {
			self::$defaults[ $key ] = array( $singular, $plural );
		}
		unset( $renamable ); // every registered key can be renamed; reserved for future locked words
	}

	private static function saved() {
		if ( null === self::$saved ) {
			$s           = get_option( self::OPTION, array() );
			self::$saved = is_array( $s ) ? $s : array();
		}
		return self::$saved;
	}

	/** Forget the cached words (after a restore replaced the option). */
	public static function reset_cache() {
		self::$saved = null;
	}

	public static function label( $key, $plural = false ) {
		$saved = self::saved();
		$pair  = isset( $saved[ $key ] ) ? $saved[ $key ] : ( isset( self::$defaults[ $key ] ) ? self::$defaults[ $key ] : array( ucfirst( $key ), ucfirst( $key ) . 's' ) );
		return $plural ? $pair[1] : $pair[0];
	}

	/** {client} → customer, {Client} → Customer, {clients} → customers, {Clients} → Customers. */
	public static function fmt( $text ) {
		$keys = array_unique( array_merge( array_keys( self::$defaults ), array_keys( self::saved() ) ) );
		$map  = array();
		foreach ( $keys as $k ) {
			$s = self::label( $k );
			$p = self::label( $k, true );
			$map[ '{' . $k . '}' ]             = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
			$map[ '{' . ucfirst( $k ) . '}' ]  = $s;
			$map[ '{' . $k . 's}' ]            = function_exists( 'mb_strtolower' ) ? mb_strtolower( $p ) : strtolower( $p );
			$map[ '{' . ucfirst( $k ) . 's}' ] = $p;
		}
		return strtr( (string) $text, $map );
	}

	/** Everything the browser needs: current words, and the presets to offer. */
	public static function export() {
		$words = array();
		foreach ( self::$defaults as $k => $d ) {
			$words[ $k ] = array( 's' => self::label( $k ), 'p' => self::label( $k, true ) );
		}
		foreach ( self::saved() as $k => $pair ) {
			if ( ! isset( $words[ $k ] ) ) {
				$words[ $k ] = array( 's' => $pair[0], 'p' => $pair[1] );
			}
		}
		$presets = array();
		foreach ( self::PRESETS as $k => $set ) {
			foreach ( $set as $name => $pair ) {
				$presets[ $k ][] = array( 'name' => $name, 's' => $pair[0], 'p' => $pair[1] );
			}
		}
		return array( 'words' => $words, 'presets' => $presets );
	}

	/** Save the owner's choice: array( key => array( s => …, p => … ) ). An empty/default pair clears the override. */
	public static function save( array $in ) {
		$out = array();
		foreach ( $in as $key => $pair ) {
			$key = sanitize_key( $key );
			if ( ! isset( self::$defaults[ $key ] ) || ! is_array( $pair ) ) {
				continue;
			}
			$s = trim( wp_strip_all_tags( isset( $pair['s'] ) ? (string) $pair['s'] : '' ) );
			$p = trim( wp_strip_all_tags( isset( $pair['p'] ) ? (string) $pair['p'] : '' ) );
			if ( '' === $s || '' === $p ) {
				continue;
			}
			$s = function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 40 ) : substr( $s, 0, 40 );
			$p = function_exists( 'mb_substr' ) ? mb_substr( $p, 0, 40 ) : substr( $p, 0, 40 );
			if ( $s !== self::$defaults[ $key ][0] || $p !== self::$defaults[ $key ][1] ) {
				$out[ $key ] = array( $s, $p );
			}
		}
		update_option( self::OPTION, $out, false );
		self::$saved = $out;
		return self::export();
	}
}
