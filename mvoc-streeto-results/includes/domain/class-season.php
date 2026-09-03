<?php
/**
 * Derives a whole season from its starting year.
 *
 * Everything about a Winter StreetO season follows from one number. The name
 * and the shortcode slug are formulaic, the age year for the Over-55 categories
 * is the year the league starts, and the eight fixtures fall on the third
 * Tuesday of each month from September to April.
 *
 * That last one is an inference, not a rule the club has stated — but it holds
 * for all eight published 2026/27 fixtures, and a test asserts as much. Dates
 * stay editable regardless, because a clash or a half-term will eventually
 * move one.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A season's name, slug and fixture list.
 */
class Season {

	/**
	 * The months a season runs through, and whether each falls in the new year.
	 *
	 * @var array<int,array{0:string,1:bool}>
	 */
	private const MONTHS = array(
		array( 'September', false ),
		array( 'October', false ),
		array( 'November', false ),
		array( 'December', false ),
		array( 'January', true ),
		array( 'February', true ),
		array( 'March', true ),
		array( 'April', true ),
	);

	/**
	 * Venues for seasons the club has already published.
	 *
	 * Only 2026/27 is known. A future season gets month names as placeholders,
	 * which read acceptably as league column headings until the venues are set.
	 *
	 * @var array<int,string[]>
	 */
	private const KNOWN_VENUES = array(
		2026 => array(
			'Burpham & Merrow',
			'Tattenham Corner',
			'Cheam',
			'Ashtead',
			'Chessington',
			'Ewell',
			'Epsom',
			'Fetcham',
		),
	);

	/**
	 * The series name for a season, e.g. "Winter StreetO 2027/28".
	 *
	 * @param int $start_year The calendar year the season starts in.
	 */
	public static function name( int $start_year ): string {
		return sprintf( 'Winter StreetO %s', self::label( $start_year ) );
	}

	/**
	 * The shortcode slug, e.g. "2027-28".
	 *
	 * @param int $start_year The calendar year the season starts in.
	 */
	public static function slug( int $start_year ): string {
		return sprintf( '%d-%02d', $start_year, ( $start_year + 1 ) % 100 );
	}

	/**
	 * The season as people say it, e.g. "2027/28".
	 *
	 * @param int $start_year The calendar year the season starts in.
	 */
	public static function label( int $start_year ): string {
		return sprintf( '%d/%02d', $start_year, ( $start_year + 1 ) % 100 );
	}

	/**
	 * The eight fixtures for a season.
	 *
	 * @param int $start_year The calendar year the season starts in.
	 * @return array<int,array{event_number:int,event_date:string,title:string,venue:string}>
	 */
	public static function fixtures( int $start_year ): array {
		$venues   = self::KNOWN_VENUES[ $start_year ] ?? array();
		$fixtures = array();

		foreach ( self::MONTHS as $index => $month ) {
			list( $name, $in_new_year ) = $month;

			$year  = $in_new_year ? $start_year + 1 : $start_year;
			$title = $venues[ $index ] ?? $name;

			$fixtures[] = array(
				'event_number' => $index + 1,
				'event_date'   => self::third_tuesday( $name, $year ),
				'title'        => $title,
				'venue'        => $venues[ $index ] ?? '',
			);
		}

		return $fixtures;
	}

	/**
	 * The third Tuesday of a given month.
	 *
	 * @param string $month Month name.
	 * @param int    $year  Calendar year.
	 */
	private static function third_tuesday( string $month, int $year ): string {
		return ( new \DateTimeImmutable( sprintf( 'third tuesday of %s %d', $month, $year ) ) )
			->format( 'Y-m-d' );
	}

	/**
	 * Starting years worth offering, newest first.
	 *
	 * Reaches back a couple of years so a past season can be recorded, and
	 * forward a few so next season can be set up before it starts.
	 *
	 * @param int|null $current_year Year to centre on; defaults to today's.
	 * @return int[]
	 */
	public static function selectable_years( ?int $current_year = null ): array {
		$current = $current_year ?? (int) gmdate( 'Y' );
		$years   = range( $current + 3, $current - 2 );

		return array_values( $years );
	}

	/**
	 * Which season a date falls in.
	 *
	 * A season starting in September means January to April belong to the year
	 * before, so the default offered in August is next season and not the one
	 * that just finished.
	 *
	 * @param int $year  Calendar year.
	 * @param int $month Calendar month, 1-12.
	 */
	public static function season_for( int $year, int $month ): int {
		return $month >= 5 ? $year : $year - 1;
	}
}
