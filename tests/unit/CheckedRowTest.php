<?php
/**
 * Tests for the tick that answers a review warning.
 *
 * The zero-time and off-date flags name a row that scores on a figure nobody
 * has stood behind, and both are honest about not knowing which way the answer
 * goes: a broken upload and a real run MapRun mistimed look identical from
 * here. Until now the only answer the screen accepted was Exclude. A row the
 * co-ordinator had checked against the night and decided was genuine went on
 * warning — every notice, every visit, for the rest of the season — and a
 * warning that cannot be answered is the one that teaches someone to read past
 * the colour, taking the next real warning with it.
 *
 * So `is_checked` records the other answer: looked at, and it stands. What
 * these tests pin down is the two things that make it safe. It must change no
 * number — a checked row scores exactly as it did, because the alternative is a
 * tick that quietly moves a league table. And it must not survive the evidence
 * it was given about, or a re-import could silence a warning nobody had read,
 * which is the failure the flag exists to prevent.
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
 * @covers \MVOC\StreetO\Repo\Results_Repo::effective
 * @covers \MVOC\StreetO\Domain\Import_Reconciler::evidence_changed
 */
class CheckedRowTest extends TestCase {

	/**
	 * The zero-time row from ZeroTimeRowTest: scored, with no run recorded.
	 *
	 * @return array<string,mixed>
	 */
	private function broken_row(): array {
		return array(
			'Id'                      => 543899,
			'Surname'                 => 'Ashdown',
			'Firstname'               => 'Casper',
			'Gender'                  => 'M',
			'StartPunchTimeLocal'     => '19:20:10',
			'FinishPunchTimeLocal'    => '19:20:10',
			'TotalTimehhmmss'         => '00:00',
			'TotalTimeSecs'           => 0,
			'Classifier'              => 'OK',
			'ClubName'                => '',
			'GrossScore'              => 660,
			'NetScore'                => 660,
			'TrackStartDateTimeUTC'   => '2026-09-11T09:20:10Z',
			'punchControlIds'         => array( '25', '37', '26' ),
			'punchTimeAfterStartSecs' => array( 0, 0, 0 ),
		);
	}

	/**
	 * A parsed row put through the columns an import writes.
	 *
	 * @param array<string,mixed> $parsed Parser output.
	 * @param array<string,mixed> $flags  Flag columns to set on the stored row.
	 * @return array<string,mixed>
	 */
	private function stored( array $parsed, array $flags = array() ): array {
		return array_merge(
			Import_Reconciler::raw_columns( $parsed ),
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
				'is_checked'            => false,
			),
			$flags
		);
	}

	private function parsed(): array {
		return ( new Parser() )->parse_row( $this->broken_row() );
	}

	public function test_a_row_starts_unchecked(): void {
		$row = Results_Repo::effective( $this->stored( $this->parsed() ), new Scoring_Config() );

		$this->assertTrue( $row['is_zero_time'] );
		$this->assertFalse( $row['is_checked'] );
	}

	public function test_the_tick_survives_the_trip_to_the_screen(): void {
		$row = Results_Repo::effective(
			$this->stored( $this->parsed(), array( 'is_checked' => 1 ) ),
			new Scoring_Config()
		);

		$this->assertTrue( $row['is_checked'] );
	}

	public function test_a_row_with_no_such_column_reads_as_unchecked(): void {
		// maybe_upgrade() runs on plugins_loaded, so between a plugin update
		// and that hook firing every row is one column short. Unchecked is the
		// right reading: it warns, which is the safe direction to be wrong in.
		$stored = $this->stored( $this->parsed() );
		unset( $stored['is_checked'] );

		$this->assertFalse( Results_Repo::effective( $stored, new Scoring_Config() )['is_checked'] );
	}

	public function test_the_tick_changes_no_number(): void {
		// The whole point: it answers the warning and touches nothing else. A
		// tick that moved a total would be a correction wearing a warning's
		// clothes, and the co-ordinator would have no idea they had made one.
		$config = new Scoring_Config();
		$engine = new Scoring_Engine( $config );

		$before = $engine->score_event(
			array( Results_Repo::effective( $this->stored( $this->parsed() ), $config ) )
		)[0];

		$after = $engine->score_event(
			array(
				Results_Repo::effective(
					$this->stored( $this->parsed(), array( 'is_checked' => 1 ) ),
					$config
				),
			)
		)[0];

		$this->assertSame( 660, $before['total'] );
		$this->assertSame( $before['total'], $after['total'] );
		$this->assertSame( $before['position'], $after['position'] );
		$this->assertSame( $before['league_points'], $after['league_points'] );
	}

	public function test_the_tick_is_recorded_like_any_other_correction(): void {
		// It goes through the same overrides trail as a score or a penalty, so
		// "why is this row in the table?" has an answer with a name and a date
		// against it.
		$this->assertArrayHasKey( 'checked', Results_Repo::OVERRIDABLE );
		$this->assertSame( 'is_checked', Results_Repo::OVERRIDABLE['checked'] );
	}

	/**
	 * A stored row as the reconciler sees it: hydrated, so ints are ints.
	 *
	 * @param array<string,mixed> $parsed Parser output.
	 * @return array<string,mixed>
	 */
	private function as_reconciler_sees_it( array $parsed ): array {
		return array_merge(
			Import_Reconciler::raw_columns( $parsed ),
			array(
				'id'           => 1,
				'is_withdrawn' => false,
				'is_manual'    => false,
			)
		);
	}

	public function test_an_import_that_changes_nothing_keeps_the_tick(): void {
		// The normal case, and the one that decides whether this is usable at
		// all: the co-ordinator imports two or three times on the night as late
		// uploads arrive, and every import issues an UPDATE for every matched
		// row whether or not anything moved. Un-checking on the action alone
		// would throw away every decision made that evening.
		$stored = $this->as_reconciler_sees_it( $this->parsed() );

		$this->assertFalse( Import_Reconciler::evidence_changed( $stored, $this->parsed() ) );
	}

	public function test_a_changed_name_or_club_keeps_the_tick(): void {
		// Neither says anything about whether the row is a broken upload.
		$stored = $this->as_reconciler_sees_it( $this->parsed() );

		$row              = $this->broken_row();
		$row['ClubName']  = 'MV';
		$row['Firstname'] = 'Cas';

		$this->assertFalse(
			Import_Reconciler::evidence_changed( $stored, ( new Parser() )->parse_row( $row ) )
		);
	}

	/**
	 * @dataProvider changed_evidence_provider
	 *
	 * @param string $field MapRun field to change.
	 * @param mixed  $value Its new value.
	 */
	public function test_changed_evidence_drops_the_tick( string $field, $value ): void {
		$stored = $this->as_reconciler_sees_it( $this->parsed() );

		$row           = $this->broken_row();
		$row[ $field ] = $value;

		$this->assertTrue(
			Import_Reconciler::evidence_changed( $stored, ( new Parser() )->parse_row( $row ) ),
			sprintf( 'A changed %s must send the row back for checking.', $field )
		);
	}

	/**
	 * Each of the four fields the two warnings are read from.
	 *
	 * @return array<string,array{0:string,1:mixed}>
	 */
	public function changed_evidence_provider(): array {
		return array(
			// MapRun finally sent a real time: the row may not even be flagged
			// any more, and the old decision was about a different row.
			'elapsed time' => array( 'TotalTimeSecs', 3421 ),
			'score'        => array( 'GrossScore', 700 ),
			// MapRun's own penalty, which is the difference between the two
			// score fields — and on a zero-time row it stands unrecomputed,
			// because the club's rule has no elapsed time to work from.
			'penalty'      => array( 'NetScore', 600 ),
			'classifier'   => array( 'Classifier', '--' ),
			// The off-date warning is read from this one alone, so a row
			// checked for its time could acquire a date problem unseen.
			'track start'  => array( 'TrackStartDateTimeUTC', '2026-04-14T09:20:10Z' ),
		);
	}

	public function test_the_reconciler_marks_a_changed_row_for_rechecking(): void {
		$stored = $this->as_reconciler_sees_it( $this->parsed() );

		$row                  = $this->broken_row();
		$row['TotalTimeSecs'] = 3421;

		$actions = ( new Import_Reconciler() )->reconcile(
			array( $stored ),
			array( ( new Parser() )->parse_row( $row ) )
		);

		$this->assertCount( 1, $actions );
		$this->assertSame( Import_Reconciler::UPDATE, $actions[0]['action'] );
		$this->assertTrue( $actions[0]['recheck'] );
	}

	public function test_the_reconciler_leaves_an_unchanged_row_alone(): void {
		$stored = $this->as_reconciler_sees_it( $this->parsed() );

		$actions = ( new Import_Reconciler() )->reconcile( array( $stored ), array( $this->parsed() ) );

		$this->assertCount( 1, $actions );
		$this->assertFalse( $actions[0]['recheck'] );
	}
}
