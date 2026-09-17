<?php
/**
 * Tests that a penalty correction can be taken back off again.
 *
 * The club's late penalty is recomputed from the elapsed time on every read,
 * which is what lets a rule change apply to a season already imported. A
 * correction wins over it, and rightly — but the penalty box had no way to send
 * "nothing", so `resolved_penalty` could be set and never unset. One correction
 * pinned that row's penalty for good: not recomputed when the rule changed, and
 * not recomputed when a re-fetch brought a different finishing time, which is
 * the case that made it worth fixing — the figure would go on standing against
 * a time it was no longer about.
 *
 * Emptying the box now means "no correction", the same as it does for the
 * score. The other half of the rule is here too: a row that was never corrected
 * has nothing to take off, so an empty box on it records nothing rather than
 * writing a correction nobody made into the audit trail.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Admin\Event_Review_Screen;
use MVOC\StreetO\Domain\Scoring_Config;
use MVOC\StreetO\Repo\Results_Repo;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Admin\Event_Review_Screen::is_uncorrected
 * @covers \MVOC\StreetO\Repo\Results_Repo::effective
 */
class PenaltyOverrideTest extends TestCase {

	/**
	 * A stored row, hydrated as the repo hydrates one.
	 *
	 * Leonard Quilter's Worcester Park run: 47 seconds over the hour, which
	 * MapRun charged a whole started minute's 30 for and the club's rule makes
	 * 24.
	 *
	 * @param array<string,mixed> $overrides Columns to set.
	 * @return array<string,mixed>
	 */
	private function stored( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                    => 1,
				'competitor_id'         => null,
				'maprun_id'             => 'abc',
				'raw_first_name'        => 'Leonard',
				'raw_surname'           => 'Quilter',
				'raw_club'              => 'MVOC',
				'classifier'            => 'OK',
				'course_label'          => '60',
				'resolved_course_label' => '',
				'raw_score'             => 780,
				'resolved_score'        => null,
				'raw_penalty'           => 30,
				'resolved_penalty'      => null,
				'raw_time_secs'         => 3647,
				'resolved_time_secs'    => null,
				'is_excluded'           => false,
				'is_withdrawn'          => false,
				'is_manual'             => false,
			),
			$overrides
		);
	}

	public function test_a_row_that_was_never_corrected_has_nothing_to_clear(): void {
		$this->assertTrue( Event_Review_Screen::is_uncorrected( 'penalty', $this->stored() ) );
		$this->assertTrue( Event_Review_Screen::is_uncorrected( 'score', $this->stored() ) );
	}

	public function test_a_corrected_row_has_something_to_clear(): void {
		$this->assertFalse(
			Event_Review_Screen::is_uncorrected( 'penalty', $this->stored( array( 'resolved_penalty' => 40 ) ) )
		);
	}

	/**
	 * Zero is the case the nullable column was added for.
	 *
	 * "This runner was not late, whatever MapRun charged" is a decision
	 * somebody made, and an empty box has to be able to take it off again — so
	 * it must not be mistaken here for a row nobody touched.
	 */
	public function test_a_penalty_corrected_to_zero_is_still_a_correction(): void {
		$this->assertFalse(
			Event_Review_Screen::is_uncorrected( 'penalty', $this->stored( array( 'resolved_penalty' => 0 ) ) )
		);
		$this->assertFalse(
			Event_Review_Screen::is_uncorrected( 'score', $this->stored( array( 'resolved_score' => 0 ) ) )
		);
	}

	/**
	 * The fields that cannot be emptied are not affected by any of this.
	 *
	 * A competitor set back to "none" and an unticked Exclude box are real
	 * answers rather than blanks, and each is stored in one column with no raw
	 * value underneath to fall back to.
	 */
	public function test_only_the_two_figures_that_can_be_emptied_have_an_answer(): void {
		foreach ( array( 'competitor', 'excluded', 'checked', 'course' ) as $field ) {
			$this->assertFalse(
				Event_Review_Screen::is_uncorrected( $field, $this->stored() ),
				"{$field} should not be treated as a clearable blank"
			);
		}
	}

	/**
	 * What the correction was doing while it could not be removed.
	 */
	public function test_a_penalty_correction_stands_over_the_club_rule(): void {
		$row = Results_Repo::effective(
			$this->stored( array( 'resolved_penalty' => 40 ) ),
			new Scoring_Config()
		);

		$this->assertSame( 40, $row['penalty'] );
		$this->assertSame( 30, $row['maprun_penalty'] );
	}

	/**
	 * And what clearing it restores: the rule, worked from the elapsed time.
	 */
	public function test_clearing_the_correction_puts_the_row_back_on_the_club_rule(): void {
		$row = Results_Repo::effective( $this->stored( array( 'resolved_penalty' => null ) ), new Scoring_Config() );

		$this->assertSame( 24, $row['penalty'] );
		$this->assertSame( 30, $row['maprun_penalty'] );
	}

	/**
	 * The point of restoring it: the figure follows a corrected time.
	 *
	 * A re-fetch refreshes the raw elapsed time, so a row back on the rule is
	 * recharged from whatever MapRun now says. A pinned 40 would have gone on
	 * standing against a run that is no longer late at all.
	 */
	public function test_a_cleared_penalty_follows_a_later_fetch(): void {
		$row = Results_Repo::effective(
			$this->stored(
				array(
					'resolved_penalty' => null,
					'raw_time_secs'    => 3540,
				)
			),
			new Scoring_Config()
		);

		$this->assertSame( 0, $row['penalty'] );
	}
}
