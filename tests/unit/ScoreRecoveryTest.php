<?php
/**
 * Tests that a score MapRun failed to report is rebuilt from the punches.
 *
 * Burpham, September 2026: the event was set up as "Score Q60" rather than
 * "ScoreQ60", MapRun did not recognise it as a score course, and all 44
 * finishers came back MP with a score of zero — with every punch recorded.
 * The names pulled through and the results table published a field of nobodies
 * on nil points.
 *
 * What is asserted here is as much about what the recovery must *not* touch as
 * what it repairs: a row MapRun scored keeps MapRun's figure, and a failed
 * upload keeps its nothing.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Import_Reconciler;
use MVOC\StreetO\Domain\Scoring_Config;
use MVOC\StreetO\Importer;
use MVOC\StreetO\MapRun\Parser;
use MVOC\StreetO\Repo\Results_Repo;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\MapRun\Parser::parse_row
 * @covers \MVOC\StreetO\Importer::count_recovered
 * @covers \MVOC\StreetO\Repo\Results_Repo::effective
 */
class ScoreRecoveryTest extends TestCase {

	/**
	 * The rows of the Burpham fixture, parsed.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function burpham(): array {
		$payload = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/maprun-burpham-unscored.json' );

		return ( new Parser() )->parse( Parser::unwrap( json_decode( $payload, true ) ) );
	}

	/**
	 * One row of the fixture, by the name it carries.
	 *
	 * @param string $surname Surname to find.
	 * @return array<string,mixed>
	 */
	private function runner( string $surname ): array {
		foreach ( $this->burpham() as $row ) {
			if ( $row['surname'] === $surname ) {
				return $row;
			}
		}

		$this->fail( "no row for {$surname} in the fixture" );
	}

	public function test_a_zero_score_beside_punches_is_rebuilt(): void {
		$row = $this->runner( 'Fenwick' );

		// Thirty-five distinct controls, the thirty-sixth punch a repeat of 57.
		$this->assertSame( 1150, $row['score'] );
		$this->assertSame( 1150, $row['net_score'] );
		$this->assertSame( Parser::SCORE_FIELD_PUNCHES, $row['score_field'] );
	}

	/**
	 * No penalty is attributed to MapRun, which charged none.
	 *
	 * The club's own late penalty is recomputed downstream from the elapsed
	 * time, so a run that finished at 1:00:12 is still charged for its twelve
	 * seconds — but by the rule, not by a figure invented here and shown on the
	 * review screen as MapRun's.
	 */
	public function test_a_rebuilt_score_carries_no_maprun_penalty(): void {
		$this->assertSame( 0, $this->runner( 'Fenwick' )['penalty'] );
	}

	/**
	 * The "(Extra)" punch MapRun appends is a repeat, not another control.
	 */
	public function test_a_repeat_punch_does_not_score_twice(): void {
		$row = $this->runner( 'Gallimore' );

		// Fourteen punches, thirteen controls: 20 was punched twice.
		$this->assertSame( 430, $row['score'] );
	}

	public function test_a_course_revision_suffix_does_not_stop_recovery(): void {
		$row = $this->runner( 'Ellwood' );

		$this->assertSame( 30, $row['course_revision'] );
		$this->assertSame( 1250, $row['score'] );
	}

	/**
	 * A failed upload has nothing to recover from and must keep its nothing.
	 *
	 * This is the case that makes the rule safe: were an empty punch list
	 * scored as zero rather than left alone, a phone that never uploaded would
	 * become a runner who scored nothing, and rank accordingly.
	 */
	public function test_a_failed_upload_is_left_alone(): void {
		$row = $this->runner( 'Ashcombe' );

		$this->assertTrue( $row['is_failed'] );
		$this->assertSame( 0, $row['score'] );
		$this->assertNotSame( Parser::SCORE_FIELD_PUNCHES, $row['score_field'] );
	}

	/**
	 * The whole point of the feature, counted the way the import reports it.
	 */
	public function test_the_import_counts_what_it_rebuilt(): void {
		$this->assertSame( 4, Importer::count_recovered( $this->burpham() ) );
	}

	/**
	 * A response MapRun scored properly must come through untouched.
	 *
	 * Worcester Park is the fixture the whole parser was pinned against, and
	 * every row in it carries a real MapRun score. If the recovery ever reached
	 * one of them it would be replacing a published figure with the plugin's
	 * own arithmetic, which is a worse failure than the one it repairs.
	 */
	public function test_a_scored_response_is_never_rebuilt(): void {
		$payload = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/maprun-worcester-park.json' );
		$rows    = ( new Parser() )->parse( Parser::unwrap( json_decode( $payload, true ) ) );

		$this->assertSame( 0, Importer::count_recovered( $rows ) );
	}

	/**
	 * A zero MapRun really meant stands, where there are no punches behind it.
	 */
	public function test_a_zero_score_without_punches_stands(): void {
		$row = ( new Parser() )->parse_row(
			array(
				'Id'         => '1',
				'Classifier' => 'OK',
				'GrossScore' => 0,
				'NetScore'   => 0,
			)
		);

		$this->assertSame( 0, $row['score'] );
		$this->assertSame( 'GrossScore', $row['score_field'] );
	}

	/**
	 * Punches worth nothing are not a repair either.
	 */
	public function test_punches_worth_nothing_leave_maprun_alone(): void {
		$row = ( new Parser() )->parse_row(
			array(
				'Id'                      => '1',
				'Classifier'              => 'MP',
				'GrossScore'              => 0,
				'NetScore'                => 0,
				'punchControlIds'         => array( '1' ),
				'punchTimeAfterStartSecs' => array( 30 ),
			)
		);

		$this->assertSame( 0, $row['score'] );
		$this->assertSame( 'GrossScore', $row['score_field'] );
	}

	/**
	 * A row with no score fields at all is still recoverable from punches.
	 */
	public function test_a_row_with_no_score_fields_is_rebuilt_from_punches(): void {
		$row = ( new Parser() )->parse_row(
			array(
				'Id'                      => '1',
				'Classifier'              => 'MP',
				'punchControlIds'         => array( '13', '55' ),
				'punchTimeAfterStartSecs' => array( 30, 60 ),
			)
		);

		$this->assertSame( 60, $row['score'] );
		$this->assertSame( Parser::SCORE_FIELD_PUNCHES, $row['score_field'] );
	}

	/**
	 * One parsed row put through the columns an import writes, then resolved
	 * exactly as the review screen resolves it.
	 *
	 * @param string              $surname   Surname to find in the fixture.
	 * @param array<string,mixed> $overrides Resolved columns to set.
	 * @return array<string,mixed>
	 */
	private function round_tripped( string $surname, array $overrides = array() ): array {
		$stored = array_merge(
			Import_Reconciler::raw_columns( $this->runner( $surname ) ),
			array(
				'id'                    => 1,
				'competitor_id'         => null,
				'resolved_score'        => null,
				'resolved_penalty'      => null,
				'resolved_time_secs'    => null,
				'resolved_course_label' => '',
				'is_excluded'           => false,
				'is_manual'             => false,
				'is_withdrawn'          => false,
			),
			$overrides
		);

		return Results_Repo::effective( $stored, new Scoring_Config() );
	}

	/**
	 * The marker has to survive the database, or no screen can show it.
	 *
	 * Where a score came from is only worth recording if it is still there when
	 * the co-ordinator opens the event a week later. The import writes it, the
	 * resolver reads it, and this goes the same long way round as the rest of
	 * the seam tests rather than trusting that it does.
	 */
	public function test_the_marker_survives_the_round_trip(): void {
		$row = $this->round_tripped( 'Fenwick' );

		$this->assertSame( 1150, $row['score'] );
		$this->assertSame( Parser::SCORE_FIELD_PUNCHES, $row['score_source'] );
	}

	/**
	 * The club's late penalty is charged on a rebuilt score like any other.
	 *
	 * 1:00:12 is twelve seconds over the hour, which is six blocks of two.
	 */
	public function test_a_rebuilt_row_is_still_charged_for_being_late(): void {
		$this->assertSame( 6, $this->round_tripped( 'Fenwick' )['penalty'] );
	}

	/**
	 * A corrected score is the co-ordinator's own, and answers for itself.
	 */
	public function test_a_correction_drops_the_marker(): void {
		$row = $this->round_tripped( 'Fenwick', array( 'resolved_score' => 1100 ) );

		$this->assertSame( 1100, $row['score'] );
		$this->assertSame( '', $row['score_source'] );
	}
}
