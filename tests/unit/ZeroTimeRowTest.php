<?php
/**
 * Tests for a row that scores without having recorded a run.
 *
 * Driven by a real row from MVOC's "Sep25 PXAS ScoreQ60" response, id 543899.
 * MapRun returned it with `Classifier: "OK"`, twenty control ids, a score of
 * 660 — and an elapsed time of zero, a distance of zero, identical start and
 * finish punches, and every entry in punchTimeAfterStartSecs set to 0.
 *
 * It is not a failed upload by MapRun's own marking, so nothing excluded it; it
 * is not a duplicate of anything, so the duplicate detector never saw it; and
 * the scoring engine ranks on a numeric score alone, so it took 660 points in a
 * field scoring 350 to 950. The event carried no warning flag either. Nothing
 * in the plugin said a word about it.
 *
 * Names are invented, as in the committed fixtures: every number and structural
 * quirk below is exactly as MapRun returned it, and none of the assertions rest
 * on a name, so pseudonymising costs nothing and keeps real competitors out of
 * the repository.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Import_Reconciler;
use MVOC\StreetO\Domain\Scoring_Config;
use MVOC\StreetO\Domain\Scoring_Engine;
use MVOC\StreetO\MapRun\Parser;
use MVOC\StreetO\Repo\Results_Repo;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\MapRun\Parser::is_zero_time
 * @covers \MVOC\StreetO\Repo\Results_Repo::effective
 */
class ZeroTimeRowTest extends TestCase {

	/**
	 * The real row, field for field, with the name replaced.
	 *
	 * @return array<string,mixed>
	 */
	private function broken_row(): array {
		return array(
			'Id'                      => 543899,
			'Surname'                 => 'Ashdown',
			'Firstname'               => 'Casper',
			'Gender'                  => 'M',
			'YearOfBirth'             => 1979,
			'StartPunchTimeLocal'     => '19:20:10',
			'FinishPunchTimeLocal'    => '19:20:10',
			'TotalTimehhmmss'         => '00:00',
			'TotalTimeSecs'           => 0,
			'Classifier'              => 'OK',
			'ClubName'                => '',
			'GrossScore'              => 660,
			'NetScore'                => 660,
			'Distance'                => 0.0,
			'punchControlIds'         => array( '25', '37', '26', '53', '15', '27', '47', '38' ),
			'punchTimeAfterStartSecs' => array( 0, 0, 0, 0, 0, 0, 0, 0 ),
		);
	}

	/**
	 * A row from the same event that really was run.
	 *
	 * @return array<string,mixed>
	 */
	private function good_row(): array {
		return array(
			'Id'                      => 543882,
			'Surname'                 => 'Greenway',
			'Firstname'               => 'Rufus',
			'Gender'                  => 'M',
			'YearOfBirth'             => 1962,
			'StartPunchTimeLocal'     => '19:12:36',
			'FinishPunchTimeLocal'    => '20:11:45',
			'TotalTimehhmmss'         => '59:09',
			'TotalTimeSecs'           => 3549,
			'Classifier'              => 'OK',
			'ClubName'                => 'GO',
			'GrossScore'              => 650,
			'NetScore'                => 650,
			'punchControlIds'         => array( '12', '30', '51' ),
			'punchTimeAfterStartSecs' => array( 235, 358, 463 ),
		);
	}

	/**
	 * One parsed row put through the columns an import writes, then resolved
	 * exactly as a screen resolves it.
	 *
	 * @param array<string,mixed> $parsed   Parser output.
	 * @param bool                $excluded Whether the co-ordinator excluded it.
	 * @return array<string,mixed>
	 */
	private function round_trip( array $parsed, bool $excluded = false ): array {
		$stored = array_merge(
			Import_Reconciler::raw_columns( $parsed ),
			array(
				'id'                    => 1,
				'competitor_id'         => null,
				'resolved_score'        => null,
				'resolved_penalty'      => null,
				'resolved_time_secs'    => null,
				'resolved_course_label' => '',
				'is_excluded'           => $excluded || ! empty( $parsed['is_failed'] ),
				'is_manual'             => false,
				'is_withdrawn'          => false,
			)
		);

		return Results_Repo::effective( $stored, new Scoring_Config() );
	}

	public function test_the_real_broken_row_is_flagged(): void {
		$parsed = ( new Parser() )->parse_row( $this->broken_row() );

		$this->assertTrue( $parsed['is_zero_time'] );
		$this->assertSame( 660, $parsed['score'] );
		$this->assertSame( 0, $parsed['time_secs'] );
	}

	public function test_a_row_that_was_actually_run_is_not_flagged(): void {
		$this->assertFalse( ( new Parser() )->parse_row( $this->good_row() )['is_zero_time'] );
	}

	public function test_a_failed_upload_is_not_flagged_as_well(): void {
		// A `--` row with no time is excluded on import already. Flagging it
		// again would ask the co-ordinator for a decision that is made.
		$row = $this->broken_row();

		$row['Classifier'] = Parser::CLASSIFIER_FAILED;
		$row['GrossScore'] = 0;
		$row['NetScore']   = 0;

		$parsed = ( new Parser() )->parse_row( $row );

		$this->assertTrue( $parsed['is_failed'] );
		$this->assertFalse( $parsed['is_zero_time'] );
	}

	public function test_a_hand_added_row_is_never_flagged(): void {
		// Hand entry takes a name and a score and no time at all, so the
		// classifier the plugin writes for it has to be exempt or every
		// manually added runner would come back as a warning.
		$this->assertFalse( Parser::is_zero_time( Parser::CLASSIFIER_MANUAL, null, 640 ) );
	}

	public function test_a_row_with_no_score_is_not_flagged(): void {
		// Nothing that would rank, so nothing to warn about.
		$this->assertFalse( Parser::is_zero_time( 'OK', 0, null ) );
	}

	public function test_a_missing_time_counts_as_no_time(): void {
		$this->assertTrue( Parser::is_zero_time( 'OK', null, 660 ) );
	}

	public function test_the_flag_survives_the_round_trip_into_storage(): void {
		// The flag is rebuilt from stored columns rather than stored in one, so
		// the parse-time answer and the stored-row answer have to agree. This
		// is the seam the duplicate detector silently lost for a whole season.
		$parsed = ( new Parser() )->parse_row( $this->broken_row() );

		$this->assertTrue( $this->round_trip( $parsed )['is_zero_time'] );
		$this->assertFalse( $this->round_trip( ( new Parser() )->parse_row( $this->good_row() ) )['is_zero_time'] );
	}

	public function test_a_flagged_row_still_scores_until_it_is_excluded(): void {
		// Deliberate. The plugin cannot tell a broken upload from a real run
		// whose timing MapRun lost, and silently dropping the second would
		// remove a runner's result without anyone being told. The flag asks;
		// it does not decide.
		$rows = array(
			$this->round_trip( ( new Parser() )->parse_row( $this->good_row() ) ),
			$this->round_trip( ( new Parser() )->parse_row( $this->broken_row() ) ),
		);

		$scored = ( new Scoring_Engine() )->score_event( $rows );
		$broken = array_values( array_filter( $scored, static fn( array $r ): bool => ! empty( $r['is_zero_time'] ) ) );

		$this->assertCount( 1, $broken );
		$this->assertSame( 660, $broken[0]['total'], 'the flag must not quietly change the result' );
		$this->assertNotNull( $broken[0]['position'], 'and must not quietly unrank it either' );
	}

	public function test_a_hand_added_row_is_not_flagged_once_stored_either(): void {
		// The exemption has to hold at the seam, not just in the predicate:
		// hand entry writes a score, no time and the MANUAL classifier, which
		// is the exact shape the flag otherwise looks for.
		$stored = array(
			'id'                    => 1,
			'competitor_id'         => null,
			'maprun_id'             => '',
			'raw_first_name'        => 'Dave',
			'raw_surname'           => 'Smith',
			'raw_club'              => '',
			'classifier'            => Parser::CLASSIFIER_MANUAL,
			'course_label'          => '60',
			'raw_score'             => 640,
			'raw_penalty'           => 0,
			'raw_time_secs'         => null,
			'raw_start_local'       => '',
			'raw_finish_local'      => '',
			'raw_course_revision'   => null,
			'resolved_score'        => 640,
			'resolved_penalty'      => 0,
			'resolved_time_secs'    => null,
			'resolved_course_label' => '60',
			'is_excluded'           => false,
			'is_manual'             => true,
			'is_withdrawn'          => false,
		);

		$this->assertFalse( Results_Repo::effective( $stored, new Scoring_Config() )['is_zero_time'] );
	}

	public function test_correcting_the_time_clears_the_flag(): void {
		// The other way out, and the one that keeps the runner's result. A
		// correction is a statement that the run happened, so the warning has
		// no business surviving it.
		$stored = array_merge(
			Import_Reconciler::raw_columns( ( new Parser() )->parse_row( $this->broken_row() ) ),
			array(
				'id'                    => 1,
				'competitor_id'         => null,
				'resolved_score'        => null,
				'resolved_penalty'      => null,
				'resolved_time_secs'    => 3480,
				'resolved_course_label' => '',
				'is_excluded'           => false,
				'is_manual'             => false,
				'is_withdrawn'          => false,
			)
		);

		$row = Results_Repo::effective( $stored, new Scoring_Config() );

		$this->assertFalse( $row['is_zero_time'] );
		$this->assertSame( 3480, $row['time_secs'] );
	}

	public function test_excluding_a_flagged_row_takes_it_out_of_the_ranking(): void {
		$rows = array(
			$this->round_trip( ( new Parser() )->parse_row( $this->good_row() ) ),
			$this->round_trip( ( new Parser() )->parse_row( $this->broken_row() ), true ),
		);

		$scored = ( new Scoring_Engine() )->score_event( $rows );
		$broken = array_values( array_filter( $scored, static fn( array $r ): bool => ! empty( $r['is_zero_time'] ) ) );

		$this->assertCount( 1, $broken );
		$this->assertNull( $broken[0]['total'] );
		$this->assertNull( $broken[0]['position'] );
	}
}
