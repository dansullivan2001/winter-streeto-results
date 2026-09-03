<?php
/**
 * Tests for deriving a season from its starting year.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Season;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\Season
 */
class SeasonTest extends TestCase {

	public function test_the_derived_fixtures_reproduce_the_published_calendar(): void {
		// The whole reason a season can be generated from one number: the
		// club's published 2026/27 dates are all third Tuesdays. If this ever
		// fails, the pattern has been broken and the generator is guessing.
		$this->assertSame(
			array(
				'2026-09-15',
				'2026-10-20',
				'2026-11-17',
				'2026-12-15',
				'2027-01-19',
				'2027-02-16',
				'2027-03-16',
				'2027-04-20',
			),
			array_column( Season::fixtures( 2026 ), 'event_date' )
		);
	}

	public function test_the_published_venues_come_through_for_2026(): void {
		$this->assertSame(
			array(
				'Burpham & Merrow',
				'Tattenham Corner',
				'Cheam',
				'Ashtead',
				'Chessington',
				'Ewell',
				'Epsom',
				'Fetcham',
			),
			array_column( Season::fixtures( 2026 ), 'title' )
		);
	}

	public function test_a_future_season_falls_back_to_month_names(): void {
		// Venues are not known years ahead, and a month name reads acceptably
		// as a league column heading until one is set.
		$fixtures = Season::fixtures( 2027 );

		$this->assertSame( 'September', $fixtures[0]['title'] );
		$this->assertSame( 'April', $fixtures[7]['title'] );
		$this->assertSame( '', $fixtures[0]['venue'] );
	}

	public function test_a_season_spans_two_calendar_years(): void {
		$dates = array_column( Season::fixtures( 2030 ), 'event_date' );

		$this->assertStringStartsWith( '2030-09', $dates[0] );
		$this->assertStringStartsWith( '2030-12', $dates[3] );
		$this->assertStringStartsWith( '2031-01', $dates[4] );
		$this->assertStringStartsWith( '2031-04', $dates[7] );
	}

	public function test_there_are_always_eight_numbered_in_order(): void {
		$fixtures = Season::fixtures( 2029 );

		$this->assertCount( 8, $fixtures );
		$this->assertSame( range( 1, 8 ), array_column( $fixtures, 'event_number' ) );
	}

	/**
	 * @dataProvider naming_provider
	 */
	public function test_naming( int $year, string $name, string $slug, string $label ): void {
		$this->assertSame( $name, Season::name( $year ) );
		$this->assertSame( $slug, Season::slug( $year ) );
		$this->assertSame( $label, Season::label( $year ) );
	}

	public function naming_provider(): array {
		return array(
			array( 2026, 'Winter StreetO 2026/27', '2026-27', '2026/27' ),
			array( 2027, 'Winter StreetO 2027/28', '2027-28', '2027/28' ),
			// The turn of the century has to pad rather than produce "2099/0".
			array( 2099, 'Winter StreetO 2099/00', '2099-00', '2099/00' ),
			array( 2100, 'Winter StreetO 2100/01', '2100-01', '2100/01' ),
		);
	}

	public function test_the_slug_matches_what_the_existing_series_already_uses(): void {
		// Shortcodes on live pages refer to this, so the format cannot drift.
		$this->assertSame( '2026-27', Season::slug( 2026 ) );
	}

	/**
	 * @dataProvider season_provider
	 */
	public function test_which_season_a_month_belongs_to( int $year, int $month, int $expected ): void {
		$this->assertSame( $expected, Season::season_for( $year, $month ) );
	}

	public function season_provider(): array {
		return array(
			// A season starts in September, so autumn belongs to its own year.
			'september' => array( 2026, 9, 2026 ),
			'december'  => array( 2026, 12, 2026 ),
			// January to April belong to the season that began the year before.
			'january'   => array( 2027, 1, 2026 ),
			'april'     => array( 2027, 4, 2026 ),
			// By August the next season is the one being set up.
			'august'    => array( 2027, 8, 2027 ),
		);
	}

	public function test_selectable_years_span_past_and_future(): void {
		$years = Season::selectable_years( 2026 );

		$this->assertSame( 2029, $years[0], 'newest first' );
		$this->assertContains( 2026, $years );
		$this->assertContains( 2024, $years, 'a past season can still be recorded' );
	}
}
