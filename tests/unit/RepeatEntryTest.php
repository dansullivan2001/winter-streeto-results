<?php
/**
 * Tests for a runner scoring more than once at one event.
 *
 * Every shape below is taken from a real Cobham response, which had eleven
 * such runners in a field of fifty-one. Duplicate_Detector saw three of them:
 * its signature needs the name, start, finish and elapsed time to match
 * exactly, which is right for the question it asks and blind to this one.
 *
 * What it could not see, and what these tests pin:
 *
 *   A real run and a stray, sharing nothing. One runner had a 700 over 2:48
 *   from one start and a second row scoring 0 over 2:48 from another an hour
 *   later. Different start, different finish — no match — and 0 is a numeric
 *   score, so the stray ranked.
 *
 *   Two real runs seconds apart. Another had 830 and 730 from the same start,
 *   finishing fourteen seconds apart against two course revisions. Both
 *   ranked; the league kept the 830 and told nobody.
 *
 * Names are invented. The numbers, the gaps and the classifiers are the real
 * ones, and no assertion rests on a name.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Repeat_Entry_Detector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\Repeat_Entry_Detector
 */
class RepeatEntryTest extends TestCase {

	/**
	 * One effective row, with the fields the detector reads.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 * @return array<string,mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'result_id'     => 1,
				'competitor_id' => null,
				'first_name'    => 'Rowan',
				'surname'       => 'Ashdown',
				'display_name'  => 'Rowan Ashdown',
				'score'          => 700,
				'position_label' => '33rd',
				'is_excluded'    => false,
				'is_withdrawn'  => false,
			),
			$overrides
		);
	}

	public function test_one_row_each_is_not_a_clash(): void {
		$rows = array(
			$this->row( array( 'result_id' => 1 ) ),
			$this->row(
				array(
					'result_id'   => 2,
					'first_name'  => 'Casper',
					'surname'     => 'Greenway',
					'display_name' => 'Casper Greenway',
				)
			),
		);

		$this->assertSame( array(), ( new Repeat_Entry_Detector() )->find( $rows ) );
	}

	public function test_a_real_run_and_a_stray_recording_clash(): void {
		// The 2:48 pair: nothing about them matches, and the stray scores 0,
		// which ranks.
		$rows = array(
			$this->row( array( 'result_id' => 1, 'score' => 700 ) ),
			$this->row( array( 'result_id' => 2, 'score' => 0 ) ),
		);

		$clashes = ( new Repeat_Entry_Detector() )->find( $rows );

		$this->assertCount( 1, $clashes );
		$this->assertSame(
			array(
				'name'       => 'Rowan Ashdown',
				'count'      => 2,
				'result_ids' => array( 1, 2 ),
			),
			Repeat_Entry_Detector::describe( $clashes[0] )
		);
	}

	public function test_two_real_runs_seconds_apart_clash(): void {
		// 830 and 730, fourteen seconds apart, which the strict signature
		// cannot group however obvious it looks to a person.
		$rows = array(
			$this->row( array( 'result_id' => 1, 'score' => 830 ) ),
			$this->row( array( 'result_id' => 2, 'score' => 730 ) ),
		);

		$this->assertCount( 1, ( new Repeat_Entry_Detector() )->find( $rows ) );
	}

	public function test_three_rows_are_one_clash_not_three(): void {
		// One runner had a 950 and two zero-time rows. That is one decision to
		// make, and reporting it three times would bury it.
		$rows = array(
			$this->row( array( 'result_id' => 1, 'score' => 950 ) ),
			$this->row( array( 'result_id' => 2, 'score' => 0 ) ),
			$this->row( array( 'result_id' => 3, 'score' => 0 ) ),
		);

		$clashes = ( new Repeat_Entry_Detector() )->find( $rows );

		$this->assertCount( 1, $clashes );
		$this->assertSame( 3, Repeat_Entry_Detector::describe( $clashes[0] )['count'] );
	}

	public function test_excluding_a_row_settles_the_clash(): void {
		// The only answer the screen offers, so it has to work: exclude the
		// stray and the warning goes, with no second step.
		$rows = array(
			$this->row( array( 'result_id' => 1, 'score' => 700 ) ),
			$this->row( array( 'result_id' => 2, 'score' => 0, 'is_excluded' => true ) ),
		);

		$this->assertSame( array(), ( new Repeat_Entry_Detector() )->find( $rows ) );
	}

	public function test_a_withdrawn_row_is_not_a_clash(): void {
		// Not in the latest import, so it is not competing for anything.
		$rows = array(
			$this->row( array( 'result_id' => 1 ) ),
			$this->row( array( 'result_id' => 2, 'is_withdrawn' => true ) ),
		);

		$this->assertSame( array(), ( new Repeat_Entry_Detector() )->find( $rows ) );
	}

	public function test_rows_that_cannot_rank_are_not_a_clash(): void {
		// No score is no place taken, so two of them are not competing.
		$rows = array(
			$this->row( array( 'result_id' => 1, 'score' => null ) ),
			$this->row( array( 'result_id' => 2, 'score' => null ) ),
		);

		$this->assertSame( array(), ( new Repeat_Entry_Detector() )->find( $rows ) );
	}

	public function test_a_zero_score_still_counts_as_scoring(): void {
		// The whole reason the strays rank: 0 is a number, so the row takes a
		// position at the foot of the table rather than dropping out.
		$rows = array(
			$this->row( array( 'result_id' => 1, 'score' => 700 ) ),
			$this->row( array( 'result_id' => 2, 'score' => 0 ) ),
		);

		$this->assertCount( 1, ( new Repeat_Entry_Detector() )->find( $rows ) );
	}

	public function test_the_confirmed_competitor_wins_over_the_name(): void {
		// Two rows, one name, two different people behind it. Linking them is
		// what says so, and once it is said they must never be grouped again.
		$rows = array(
			$this->row( array( 'result_id' => 1, 'competitor_id' => 7 ) ),
			$this->row( array( 'result_id' => 2, 'competitor_id' => 9 ) ),
		);

		$this->assertSame( array(), ( new Repeat_Entry_Detector() )->find( $rows ) );
	}

	public function test_one_competitor_across_two_names_still_clashes(): void {
		// The other direction: MapRun spelled them differently across two
		// phones, and the co-ordinator linked both to one competitor. The name
		// says two people, the link says one, and the link is the one that
		// knows.
		$rows = array(
			$this->row( array( 'result_id' => 1, 'competitor_id' => 7 ) ),
			$this->row(
				array(
					'result_id'     => 2,
					'competitor_id' => 7,
					'first_name'    => 'Ro',
					'surname'       => 'Ashdown',
				)
			),
		);

		$this->assertCount( 1, ( new Repeat_Entry_Detector() )->find( $rows ) );
	}

	public function test_unlinked_rows_are_grouped_by_name(): void {
		// On a fresh import nothing is linked, which is exactly when the
		// co-ordinator is reading the table and most likely to be caught out.
		$rows = array(
			$this->row( array( 'result_id' => 1 ) ),
			$this->row( array( 'result_id' => 2, 'first_name' => 'rowan', 'surname' => ' ASHDOWN ' ) ),
		);

		$this->assertCount( 1, ( new Repeat_Entry_Detector() )->find( $rows ) );
	}

	public function test_a_nameless_unlinked_row_is_never_grouped(): void {
		// Nothing to group on, and grouping every nameless row together would
		// invent a runner.
		$rows = array(
			$this->row( array( 'result_id' => 1, 'first_name' => '', 'surname' => '' ) ),
			$this->row( array( 'result_id' => 2, 'first_name' => '', 'surname' => '' ) ),
		);

		$this->assertSame( array(), ( new Repeat_Entry_Detector() )->find( $rows ) );
	}

	public function test_an_excluded_row_is_told_what_its_runner_already_has(): void {
		// The question the co-ordinator cannot answer by eye fifty rows in, and
		// the one that decides whether un-excluding is a fix or a second
		// scoring row. Excluded rows are not clashes, but they still need the
		// answer.
		$rows = array(
			$this->row( array( 'result_id' => 1, 'score' => 830, 'position_label' => '18th' ) ),
			$this->row( array( 'result_id' => 2, 'score' => 0, 'is_excluded' => true ) ),
		);

		$elsewhere = ( new Repeat_Entry_Detector() )->elsewhere( $rows );

		$this->assertCount( 1, $elsewhere[2] );
		$this->assertSame( 830, $elsewhere[2][0]['score'] );
		$this->assertSame( '18th', $elsewhere[2][0]['position_label'] );

		// And the scoring row has no company, because the other is excluded.
		$this->assertSame( array(), $elsewhere[1] );
	}

	public function test_an_excluded_row_with_no_counterpart_says_so(): void {
		// Excluded, and nothing else of theirs scores: as it stands this runner
		// is not in the results at all, which is when un-excluding is right.
		$rows = array(
			$this->row( array( 'result_id' => 1, 'score' => 700, 'is_excluded' => true ) ),
			$this->row(
				array(
					'result_id'    => 2,
					'first_name'   => 'Casper',
					'surname'      => 'Greenway',
					'display_name' => 'Casper Greenway',
				)
			),
		);

		$this->assertSame( array(), ( new Repeat_Entry_Detector() )->elsewhere( $rows )[1] );
	}

	public function test_a_row_is_never_its_own_company(): void {
		// An empty list has to mean "nothing else", or the note built from it
		// tells the co-ordinator the row duplicates itself.
		$rows = array( $this->row( array( 'result_id' => 1 ) ) );

		$this->assertSame( array(), ( new Repeat_Entry_Detector() )->elsewhere( $rows )[1] );
	}

	public function test_clashing_ids_marks_every_row_in_the_group(): void {
		$rows = array(
			$this->row( array( 'result_id' => 4, 'score' => 950 ) ),
			$this->row( array( 'result_id' => 5, 'score' => 0 ) ),
			$this->row(
				array(
					'result_id'    => 6,
					'first_name'   => 'Casper',
					'surname'      => 'Greenway',
					'display_name' => 'Casper Greenway',
				)
			),
		);

		$this->assertSame(
			array( 4 => true, 5 => true ),
			( new Repeat_Entry_Detector() )->clashing_ids( $rows )
		);
	}
}
