<?php
/**
 * Suggests the MapRun event name for a fixture.
 *
 * The point is not to save typing. It is that the name has to match exactly
 * between MapRun and this plugin, and today the only thing keeping them in step
 * is somebody typing the same string twice. Suggesting one makes the plugin the
 * place the name is decided, so whoever creates the MapRun event copies it from
 * here — which is what actually produces a consistent convention.
 *
 * The format is taken from the club's own MapRun events, and holds for all
 * eight of the 2025/26 season:
 *
 *     Worcester Park Apr26 PXAS ScoreQ60
 *     <venue>       <Mon><YY> PXAS ScoreQ<minutes>
 *
 * It still only ever suggests. The venue is free text at MapRun's end — one of
 * those eight was set up as "Dork v2", an abbreviation with a version suffix —
 * so a generated name can be right about the shape and wrong about the words.
 * Every field stays editable, and a wrong guess costs a failed fetch, which is
 * visible, rather than silently pulling a different event.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC\StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a suggested MapRun event name.
 */
class MapRun_Name {

	/**
	 * The fixed part of the name, between the date and the course.
	 *
	 * PXAS is a MapRun event-options code; ScoreQ<minutes> is its scoring
	 * scheme, where the number is the time limit. Both come from MapRun's own
	 * conventions rather than the club's.
	 */
	public const OPTIONS = 'PXAS';

	/**
	 * Suggest a name for one course of one fixture.
	 *
	 * @param string $venue   Event venue or title.
	 * @param string $date    Event date, as Y-m-d.
	 * @param string $minutes Course length, e.g. '60'.
	 */
	public static function suggest( string $venue, string $date, string $minutes ): string {
		$venue   = trim( preg_replace( '/\s+/', ' ', $venue ) ?? '' );
		$minutes = preg_replace( '/[^0-9]/', '', $minutes );

		if ( '' === $venue || '' === $minutes ) {
			return '';
		}

		$stamp = self::month_stamp( $date );

		return trim( implode( ' ', array_filter( array( $venue, $stamp, self::OPTIONS, 'ScoreQ' . $minutes ) ) ) );
	}

	/**
	 * The date as MapRun writes it: three-letter month and two-digit year.
	 *
	 * @param string $date Event date, as Y-m-d.
	 */
	private static function month_stamp( string $date ): string {
		$date = trim( $date );

		if ( ! preg_match( '/^(\d{4})-(\d{2})-\d{2}$/', $date, $m ) ) {
			return '';
		}

		$month = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );

		return $month ? $month->format( 'M' ) . substr( $m[1], -2 ) : '';
	}
}
