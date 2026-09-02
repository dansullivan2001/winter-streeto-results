<?php
/**
 * Tests for the league builder.
 *
 * The headline test replays the club's 2019/20 league and asserts the builder
 * reproduces the workbook's own totals, organiser bonuses, event counts and
 * both position columns for all 111 competitors.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\League_Builder;
use MVOC\StreetO\Domain\Scoring_Config;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\League_Builder
 */
class LeagueBuilderTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function workbook_league(): array {
		return json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/fixtures/workbook-league.json' ),
			true
		);
	}

	public function test_reproduces_the_workbook_league_exactly(): void {
		$fixture   = $this->workbook_league();
		$standings = ( new League_Builder() )->build( $fixture['competitors'] );

		$this->assertCount( 111, $standings );

		foreach ( $standings as $row ) {
			$this->assertSame( $row['expected_total'], $row['total'], $row['name'] . ': total' );
			$this->assertSame( $row['expected_position'], $row['position'], $row['name'] . ': position' );
			$this->assertSame(
				$row['expected_events_entered'],
				$row['events_entered'],
				$row['name'] . ': events entered'
			);
			$this->assertSame(
				$row['expected_organiser_points'],
				$row['organiser_points'],
				$row['name'] . ': organiser points'
			);
			$this->assertSame(
				$row['expected_women_position'],
				$row['ladies_position'],
				$row['name'] . ': ladies position'
			);
		}
	}

	public function test_best_five_of_eight_counts(): void {
		// Kenneth Ravenscroft's six scores in the workbook: the lowest, 44, is dropped.
		$standings = ( new League_Builder() )->build(
			array(
				array( 'name' => 'x', 'event_points' => array( 44, 50, 45, 47, 49, 48, null, null ) ),
			)
		);

		$this->assertSame( 239, $standings[0]['total'] );
	}

	public function test_fewer_than_five_scores_are_all_counted(): void {
		$standings = ( new League_Builder() )->build(
			array( array( 'name' => 'x', 'event_points' => array( 40, 30, null, null ) ) )
		);

		$this->assertSame( 70, $standings[0]['total'] );
	}

	public function test_organiser_bonus_is_their_best_score_and_competes_for_a_slot(): void {
		// Six scores plus a bonus equal to the best of them gives seven
		// candidates, of which five count.
		$standings = ( new League_Builder() )->build(
			array(
				array(
					'name'         => 'x',
					'event_points' => array( 10, 20, 30, 40, 50, 45, null, null ),
					'organised'    => 'Epsom',
				),
			)
		);

		$this->assertSame( 50, $standings[0]['organiser_points'] );
		$this->assertSame( 50 + 50 + 45 + 40 + 30, $standings[0]['total'] );
	}

	public function test_organiser_bonus_can_be_added_on_top_instead(): void {
		// Held as a setting; the workbook has the bonus competing for a slot.
		$config    = new Scoring_Config( array( 'organiser_bonus_mode' => Scoring_Config::BONUS_ADDED ) );
		$standings = ( new League_Builder( $config ) )->build(
			array(
				array(
					'name'         => 'x',
					'event_points' => array( 10, 20, 30, 40, 50, 45, null, null ),
					'organised'    => 'Epsom',
				),
			)
		);

		$this->assertSame( 50 + 45 + 40 + 30 + 20 + 50, $standings[0]['total'] );
	}

	public function test_an_organiser_who_never_ran_scores_nothing(): void {
		// max() of an empty set is not an error here: they organised but have no
		// results to take a best from.
		$standings = ( new League_Builder() )->build(
			array( array( 'name' => 'x', 'event_points' => array( null, null ), 'organised' => 'Cheam' ) )
		);

		$this->assertSame( 0, $standings[0]['organiser_points'] );
		$this->assertSame( 0, $standings[0]['total'] );
	}

	public function test_events_entered_excludes_the_organiser_bonus(): void {
		$standings = ( new League_Builder() )->build(
			array(
				array( 'name' => 'x', 'event_points' => array( 40, 30, null ), 'organised' => 'Ewell' ),
			)
		);

		$this->assertSame( 2, $standings[0]['events_entered'] );
	}

	public function test_ties_share_a_position_and_the_next_skips(): void {
		$standings = ( new League_Builder() )->build(
			array(
				array( 'name' => 'a', 'event_points' => array( 50 ) ),
				array( 'name' => 'b', 'event_points' => array( 50 ) ),
				array( 'name' => 'c', 'event_points' => array( 40 ) ),
			)
		);

		$this->assertSame(
			array( 'a' => 1, 'b' => 1, 'c' => 3 ),
			array_column( $standings, 'position', 'name' )
		);
	}

	public function test_category_positions_rank_within_their_own_subset(): void {
		// Each ranking is the same operation over a different subset, so one
		// competitor can be 4th overall, 2nd lady and 1st over-55 woman.
		$standings = ( new League_Builder() )->build(
			array(
				array( 'name' => 'man', 'event_points' => array( 50 ) ),
				array( 'name' => 'fast lady', 'event_points' => array( 45 ), 'is_female' => true ),
				array( 'name' => 'vet man', 'event_points' => array( 42 ), 'is_over55' => true ),
				array(
					'name'         => 'vet lady',
					'event_points' => array( 40 ),
					'is_female'    => true,
					'is_over55'    => true,
				),
			)
		);

		$by_name = array_column( $standings, null, 'name' );

		$this->assertSame( 4, $by_name['vet lady']['position'] );
		$this->assertSame( 2, $by_name['vet lady']['ladies_position'] );
		$this->assertSame( 1, $by_name['vet lady']['o55_women_position'] );
		$this->assertSame( 1, $by_name['vet man']['o55_men_position'] );
		$this->assertSame( 1, $by_name['fast lady']['ladies_position'] );
	}

	public function test_competitors_outside_a_category_get_no_position_in_it(): void {
		$standings = ( new League_Builder() )->build(
			array( array( 'name' => 'man', 'event_points' => array( 50 ) ) )
		);

		$this->assertSame( 1, $standings[0]['position'] );
		$this->assertNull( $standings[0]['ladies_position'] );
		$this->assertNull( $standings[0]['o55_men_position'] );
		$this->assertNull( $standings[0]['o55_women_position'] );
	}

	public function test_over_55_is_split_by_gender(): void {
		// The club awards separate 55+ titles to a man and a woman, so a vet
		// man and a vet woman are each first in their own category rather than
		// being ranked against one another.
		$standings = ( new League_Builder() )->build(
			array(
				array( 'name' => 'vet lady', 'event_points' => array( 50 ), 'is_female' => true, 'is_over55' => true ),
				array( 'name' => 'vet man', 'event_points' => array( 45 ), 'is_over55' => true ),
				array( 'name' => 'slower vet lady', 'event_points' => array( 30 ), 'is_female' => true, 'is_over55' => true ),
			)
		);

		$by_name = array_column( $standings, null, 'name' );

		$this->assertSame( 1, $by_name['vet lady']['o55_women_position'] );
		$this->assertSame( 2, $by_name['slower vet lady']['o55_women_position'] );
		$this->assertNull( $by_name['vet lady']['o55_men_position'] );

		// The vet man is first among over-55 men despite scoring less than the
		// leading vet woman.
		$this->assertSame( 1, $by_name['vet man']['o55_men_position'] );
		$this->assertNull( $by_name['vet man']['o55_women_position'] );
	}

	public function test_a_vet_woman_appears_in_both_ladies_and_o55_women(): void {
		$standings = ( new League_Builder() )->build(
			array(
				array( 'name' => 'young lady', 'event_points' => array( 50 ), 'is_female' => true ),
				array( 'name' => 'vet lady', 'event_points' => array( 40 ), 'is_female' => true, 'is_over55' => true ),
			)
		);

		$by_name = array_column( $standings, null, 'name' );

		$this->assertSame( 2, $by_name['vet lady']['ladies_position'] );
		$this->assertSame( 1, $by_name['vet lady']['o55_women_position'] );
	}

	public function test_standings_are_returned_best_first(): void {
		$standings = ( new League_Builder() )->build(
			array(
				array( 'name' => 'low', 'event_points' => array( 10 ) ),
				array( 'name' => 'high', 'event_points' => array( 50 ) ),
			)
		);

		$this->assertSame( 'high', $standings[0]['name'] );
	}

	public function test_a_different_counting_limit_is_honoured(): void {
		$config    = new Scoring_Config( array( 'counting_events' => 3 ) );
		$standings = ( new League_Builder( $config ) )->build(
			array( array( 'name' => 'x', 'event_points' => array( 10, 20, 30, 40, 50 ) ) )
		);

		$this->assertSame( 120, $standings[0]['total'] );
	}
}
