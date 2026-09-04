<?php
/**
 * Tests for the published table models.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Event_Presenter;
use MVOC\StreetO\Domain\League_Builder;
use MVOC\StreetO\Domain\League_Presenter;
use MVOC\StreetO\Domain\Scoring_Engine;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\Event_Presenter
 * @covers \MVOC\StreetO\Domain\League_Presenter
 */
class PresentersTest extends TestCase {

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function scored(): array {
		return ( new Scoring_Engine() )->score_event(
			array(
				array( 'display_name' => 'Rowan Orpington', 'club' => 'MV', 'course_label' => '60', 'score' => 1180, 'penalty' => 0 ),
				array( 'display_name' => 'Nadia Dalrymple', 'club' => '', 'course_label' => '60', 'score' => 960, 'penalty' => 0 ),
				array( 'display_name' => 'Leonard Quilter', 'club' => 'MVOC', 'course_label' => '60', 'score' => 780, 'penalty' => 30 ),
				array( 'display_name' => 'Test Run', 'club' => '', 'course_label' => '60', 'score' => 9990, 'penalty' => 0, 'is_excluded' => true ),
			)
		);
	}

	public function test_event_columns_match_what_the_club_asked_for(): void {
		$this->assertSame(
			array( 'Pos', 'Name', 'Club', 'Course', 'Score', 'Penalty', 'Total', 'League pts' ),
			( new Event_Presenter() )->columns()
		);
	}

	public function test_elapsed_time_is_not_published(): void {
		// Deliberate: the tie-break ignores time, so a time column would invite
		// "why am I below someone slower?" when the rule simply does not care.
		$columns = ( new Event_Presenter() )->columns();

		$this->assertNotContains( 'Time', $columns );
	}

	public function test_the_event_table_is_in_finishing_order(): void {
		$model = ( new Event_Presenter() )->present( $this->scored() );

		$this->assertSame(
			array( 'Rowan Orpington', 'Nadia Dalrymple', 'Leonard Quilter' ),
			array_column( $model['rows'], 'name' )
		);
		$this->assertSame( array( 1, 2, 3 ), array_column( $model['rows'], 'position' ) );
	}

	public function test_excluded_rows_never_reach_the_public_table(): void {
		// Test runs, course setters, failed uploads and discarded duplicates.
		$model = ( new Event_Presenter() )->present( $this->scored() );

		$this->assertNotContains( 'Test Run', array_column( $model['rows'], 'name' ) );
	}

	public function test_withdrawn_rows_are_not_published_either(): void {
		$model = ( new Event_Presenter() )->present(
			array(
				array( 'display_name' => 'Gone', 'total' => 500, 'position' => 1, 'position_label' => '1st', 'league_points' => 50, 'is_withdrawn' => true ),
			)
		);

		$this->assertSame( array(), $model['rows'] );
	}

	public function test_the_penalty_is_shown_alongside_the_score(): void {
		$model  = ( new Event_Presenter() )->present( $this->scored() );
		$morgan = $model['rows'][2];

		$this->assertSame( 780, $morgan['score'] );
		$this->assertSame( 30, $morgan['penalty'] );
		$this->assertSame( 750, $morgan['total'] );
	}

	public function test_the_organiser_is_listed_last_and_unranked(): void {
		$model = ( new Event_Presenter() )->present(
			$this->scored(),
			array( array( 'display_name' => 'Greta Yalding', 'club' => 'MVOC' ) )
		);

		$last = end( $model['rows'] );

		$this->assertSame( 'Greta Yalding', $last['name'] );
		$this->assertTrue( $last['is_organiser'] );
		$this->assertNull( $last['position'] );
		$this->assertNull( $last['league_points'] );
	}

	public function test_an_event_run_jointly_lists_every_organiser(): void {
		// Rare, but it happens: two people share the organising for one event.
		$model = ( new Event_Presenter() )->present(
			$this->scored(),
			array(
				array( 'display_name' => 'Greta Yalding', 'club' => 'MVOC' ),
				array( 'display_name' => 'Hugh Carshalton', 'club' => '' ),
			)
		);

		$organisers = array_values( array_filter( $model['rows'], static fn( array $r ): bool => ! empty( $r['is_organiser'] ) ) );

		$this->assertCount( 2, $organisers );
		$this->assertSame( array( 'Greta Yalding', 'Hugh Carshalton' ), array_column( $organisers, 'name' ) );
	}

	public function test_no_footnote_when_everyone_ran_the_long_course(): void {
		$model = ( new Event_Presenter() )->present( $this->scored() );

		$this->assertFalse( $model['has_short_course'] );
	}

	public function test_a_short_course_runner_flags_the_footnote(): void {
		$scored = ( new Scoring_Engine() )->score_event(
			array(
				array( 'display_name' => 'Long', 'course_label' => '60', 'score' => 500, 'penalty' => 0 ),
				array( 'display_name' => 'Short', 'course_label' => '40', 'score' => 300, 'penalty' => 0 ),
			)
		);

		$model = ( new Event_Presenter() )->present( $scored );

		$this->assertTrue( $model['has_short_course'] );

		$by_name = array_column( $model['rows'], null, 'name' );
		$this->assertTrue( $by_name['Short']['is_scaled'] );
		$this->assertFalse( $by_name['Long']['is_scaled'] );

		// 300 over 40 minutes scales to 450, which still loses to 500 over the
		// hour - the scaling makes the courses comparable, not equivalent.
		$this->assertSame( 450, $by_name['Short']['total'] );
		$this->assertSame( 1, $by_name['Long']['position'] );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function standings(): array {
		return ( new League_Builder() )->build(
			array(
				array( 'display_name' => 'Fast Man', 'event_points' => array( 50, 50, null ) ),
				array( 'display_name' => 'Fast Lady', 'event_points' => array( 49, 48, null ), 'is_female' => true ),
				array( 'display_name' => 'Vet Man', 'event_points' => array( 40, null, null ), 'is_over55' => true ),
				array( 'display_name' => 'Vet Lady', 'event_points' => array( 30, 30, null ), 'is_female' => true, 'is_over55' => true ),
			)
		);
	}

	public function test_the_league_defaults_to_the_overall_table(): void {
		$model = ( new League_Presenter() )->present( $this->standings(), array( 'Sep', 'Oct', 'Nov' ) );

		$this->assertSame( 'overall', $model['category'] );
		$this->assertCount( 4, $model['rows'] );
		$this->assertSame( 'Fast Man', $model['rows'][0]['name'] );
	}

	public function test_a_category_table_holds_only_that_category(): void {
		$model = ( new League_Presenter() )->present( $this->standings(), array( 'Sep', 'Oct', 'Nov' ), 'o55_women' );

		$this->assertSame( 'Over 55 Women', $model['label'] );
		$this->assertSame( array( 'Vet Lady' ), array_column( $model['rows'], 'name' ) );
		$this->assertSame( 1, $model['rows'][0]['position'] );
	}

	public function test_category_tables_renumber_from_one(): void {
		// The ladies table starts at 1 even though its leader is 2nd overall.
		$model = ( new League_Presenter() )->present( $this->standings(), array( 'Sep', 'Oct', 'Nov' ), 'ladies' );

		$this->assertSame( array( 1, 2 ), array_column( $model['rows'], 'position' ) );
		$this->assertSame( 2, $model['rows'][0]['overall_position'] );
	}

	public function test_an_unknown_category_falls_back_to_overall(): void {
		// A typo in a shortcode attribute should show something sensible
		// rather than an empty table on a live page.
		$model = ( new League_Presenter() )->present( $this->standings(), array( 'Sep' ), 'over55' );

		$this->assertSame( 'overall', $model['category'] );
	}

	public function test_event_detail_lines_up_with_the_series(): void {
		// Missed events stay as nulls so the expander is column-aligned for
		// everyone, whoever ran what.
		$model  = ( new League_Presenter() )->present( $this->standings(), array( 'Sep', 'Oct', 'Nov' ) );
		$vet    = array_column( $model['rows'], null, 'name' )['Vet Man'];
		$detail = $vet['event_points'];

		$this->assertCount( 3, $detail );
		$this->assertSame( array( 'Sep', 'Oct', 'Nov' ), array_column( $detail, 'label' ) );
		$this->assertSame( array( 40, null, null ), array_column( $detail, 'points' ) );
	}

	public function test_rows_come_back_in_position_order(): void {
		$model     = ( new League_Presenter() )->present( $this->standings(), array( 'Sep', 'Oct', 'Nov' ) );
		$positions = array_column( $model['rows'], 'position' );
		$sorted    = $positions;
		sort( $sorted );

		$this->assertSame( $sorted, $positions );
	}

	public function test_every_row_carries_all_four_rankings(): void {
		// One table shows Pos, Ladies, M55 and W55 side by side, the way the
		// club's spreadsheet did, so each row has to know all of them.
		$model   = ( new League_Presenter() )->present( $this->standings(), array( 'Sep', 'Oct', 'Nov' ) );
		$by_name = array_column( $model['rows'], null, 'name' );

		$this->assertSame( 1, $by_name['Fast Man']['positions']['overall'] );
		$this->assertNull( $by_name['Fast Man']['positions']['ladies'] );

		$this->assertSame( 2, $by_name['Fast Lady']['positions']['overall'] );
		$this->assertSame( 1, $by_name['Fast Lady']['positions']['ladies'] );
		$this->assertNull( $by_name['Fast Lady']['positions']['o55_women'] );

		$this->assertSame( 2, $by_name['Vet Lady']['positions']['ladies'] );
		$this->assertSame( 1, $by_name['Vet Lady']['positions']['o55_women'] );
		$this->assertNull( $by_name['Vet Lady']['positions']['o55_men'] );

		$this->assertSame( 1, $by_name['Vet Man']['positions']['o55_men'] );
	}

	public function test_the_category_columns_are_named_for_display(): void {
		$this->assertSame(
			array( 'ladies' => 'Ladies', 'o55_men' => 'M55', 'o55_women' => 'W55' ),
			League_Presenter::category_columns()
		);
	}

	public function test_a_filtered_table_still_carries_every_ranking(): void {
		// Filtering picks which rows appear, not which columns - so a ladies
		// table still shows where each of them sits overall.
		$model = ( new League_Presenter() )->present( $this->standings(), array( 'Sep' ), 'ladies' );

		// Leading lady: 1st in this table, 2nd overall, and both are visible.
		$this->assertSame( 1, $model['rows'][0]['position'] );
		$this->assertSame( 1, $model['rows'][0]['positions']['ladies'] );
		$this->assertSame( 2, $model['rows'][0]['positions']['overall'] );
	}

	public function test_all_four_categories_are_offered(): void {
		$this->assertSame(
			array( 'overall', 'ladies', 'o55_men', 'o55_women' ),
			League_Presenter::categories()
		);
	}
}
