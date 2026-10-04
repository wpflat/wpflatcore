<?php
namespace BizyCore\Services;

defined( 'ABSPATH' ) || exit;

/** Date helpers for charts: a range is cut into days, weeks or months so a trend line has a sensible number of points. */
class Series {

	public static function date_or( $d, $fallback ) {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d ) ? $d : $fallback;
	}

	public static function granularity( $from, $to ) {
		$days = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
		return $days <= 45 ? 'day' : ( $days <= 200 ? 'week' : 'month' );
	}

	public static function bucket( $date, $gran ) {
		if ( 'day' === $gran ) {
			return $date;
		}
		if ( 'week' === $gran ) {
			return gmdate( 'Y-m-d', strtotime( 'monday this week', strtotime( $date ) ) );
		}
		return substr( $date, 0, 7 );
	}

	public static function buckets( $from, $to, $gran ) {
		$out = array();
		$t   = strtotime( self::bucket( $from, $gran ) . ( 'month' === $gran ? '-01' : '' ) );
		$end = strtotime( $to );
		while ( $t <= $end ) {
			$out[] = array( 'key' => 'month' === $gran ? gmdate( 'Y-m', $t ) : gmdate( 'Y-m-d', $t ), 'label' => 'month' === $gran ? wp_date( 'M Y', $t ) : wp_date( 'M j', $t ) );
			$t     = strtotime( 'month' === $gran ? '+1 month' : ( 'week' === $gran ? '+7 days' : '+1 day' ), $t );
		}
		return $out;
	}
}
