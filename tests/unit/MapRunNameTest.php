<?php
/**
 * Tests for the suggested MapRun event name.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\MapRun_Name;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\MapRun_Name
 */
class MapRunNameTest extends TestCase {

	/**
	 * The club's whole 2025/26 season, as MapRun actually holds it.
	 *
	 * Taken from the MapRun events browser, folder
	 * "UK/Mole Valley/StreetO 25-26 Series". All eight follow the same shape,
	 * which is what turns this from a guess into a convention the plugin can
	 * generate. If a future season departs from it, this is where that shows
	 * up — rather than as a fetch failing on the night.
	 *
	 * @dataProvider real_season_provider
	 */
	public function test_it_reproduces_every_real_name_from_last_season(
		string $venue,
		string $date,
		string $expected
	): void {
		$this->assertSame( $expected, MapRun_Name::suggest( $venue, $date, '60' ) );
	}

	public function real_season_provider(): array {
		// Dates are the third Tuesday of each month, which is the club's
		// fixture pattern; only the month and year reach the name.
		return array(
			array( 'Epsom', '2025-09-16', 'Epsom Sep25 PXAS ScoreQ60' ),
			array( 'Leatherhead', '2025-10-21', 'Leatherhead Oct25 PXAS ScoreQ60' ),
			array( 'Carshalton', '2025-11-18', 'Carshalton Nov25 PXAS ScoreQ60' ),
			array( 'Ashtead', '2025-12-16', 'Ashtead Dec25 PXAS ScoreQ60' ),
			array( 'Esher', '2026-01-20', 'Esher Jan26 PXAS ScoreQ60' ),
			// "Dork v2" is what was actually used: the original MapRun event
			// had a fault and a corrected version was uploaded before the
			// night. So the suffix is a recovery, not a naming habit - but it
			// still shows the venue is free text at MapRun's end, which is why
			// a generated name is only ever a suggestion.
			array( 'Dork v2', '2026-02-17', 'Dork v2 Feb26 PXAS ScoreQ60' ),
			array( 'Cobham', '2026-03-17', 'Cobham Mar26 PXAS ScoreQ60' ),
			array( 'Worcester Park', '2026-04-21', 'Worcester Park Apr26 PXAS ScoreQ60' ),
		);
	}

	public function test_the_season_spans_two_calendar_years_in_the_stamp(): void {
		// Autumn events carry the starting year, spring ones the year after -
		// Sep25 through to Apr26 for a single season.
		$this->assertStringContainsString( 'Sep25', MapRun_Name::suggest( 'Epsom', '2025-09-16', '60' ) );
		$this->assertStringContainsString( 'Apr26', MapRun_Name::suggest( 'Epsom', '2026-04-21', '60' ) );
	}

	public function test_the_short_course_differs_only_in_the_scheme(): void {
		$this->assertSame(
			'Worcester Park Apr26 PXAS ScoreQ40',
			MapRun_Name::suggest( 'Worcester Park', '2026-04-21', '40' )
		);
	}

	/**
	 * @dataProvider fixture_provider
	 */
	public function test_the_season_fixtures( string $venue, string $date, string $expected ): void {
		$this->assertSame( $expected, MapRun_Name::suggest( $venue, $date, '60' ) );
	}

	public function fixture_provider(): array {
		return array(
			array( 'Burpham & Merrow', '2026-09-15', 'Burpham & Merrow Sep26 PXAS ScoreQ60' ),
			array( 'Tattenham Corner', '2026-10-20', 'Tattenham Corner Oct26 PXAS ScoreQ60' ),
			array( 'Cheam', '2026-11-17', 'Cheam Nov26 PXAS ScoreQ60' ),
			array( 'Ashtead', '2026-12-15', 'Ashtead Dec26 PXAS ScoreQ60' ),
			// January to April fall in the following calendar year, and the
			// stamp follows the date rather than the season.
			array( 'Chessington', '2027-01-19', 'Chessington Jan27 PXAS ScoreQ60' ),
			array( 'Fetcham', '2027-04-20', 'Fetcham Apr27 PXAS ScoreQ60' ),
		);
	}

	public function test_a_course_given_with_units_still_works(): void {
		$this->assertSame(
			'Cheam Nov26 PXAS ScoreQ40',
			MapRun_Name::suggest( 'Cheam', '2026-11-17', '40 min' )
		);
	}

	public function test_untidy_whitespace_in_a_venue_is_collapsed(): void {
		$this->assertSame(
			'Tattenham Corner Oct26 PXAS ScoreQ60',
			MapRun_Name::suggest( '  Tattenham   Corner ', '2026-10-20', '60' )
		);
	}

	public function test_a_missing_date_still_gives_something_usable(): void {
		// A fixture whose date has not been set yet should not produce a name
		// with a gap or the word "false" in it.
		$this->assertSame( 'Cheam PXAS ScoreQ60', MapRun_Name::suggest( 'Cheam', '', '60' ) );
	}

	public function test_nothing_without_a_venue_or_a_course(): void {
		// Better an empty suggestion than a confident-looking wrong one.
		$this->assertSame( '', MapRun_Name::suggest( '', '2026-09-15', '60' ) );
		$this->assertSame( '', MapRun_Name::suggest( 'Cheam', '2026-09-15', '' ) );
	}

	public function test_a_malformed_date_is_ignored_rather_than_guessed(): void {
		$this->assertSame( 'Cheam PXAS ScoreQ60', MapRun_Name::suggest( 'Cheam', 'next Tuesday', '60' ) );
	}
}
