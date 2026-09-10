<?php
/**
 * Tests for what happens after a duplicate has been decided.
 *
 * The screen could not reach this state until the detector started finding
 * duplicates on stored rows, so none of it had ever run: a decided cluster came
 * back looking untouched, and re-deciding one excluded both rows and dropped
 * the runner from the event entirely.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Duplicate_Detector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\Duplicate_Detector
 */
class DuplicateDecisionTest extends TestCase {

	/**
	 * Two rows of one run, as the review screen resolves them.
	 *
	 * @param bool $first_excluded  Whether the Rev30 row is excluded.
	 * @param bool $second_excluded Whether the plain row is excluded.
	 * @return array<int,array<string,mixed>>
	 */
	private function cluster( bool $first_excluded = false, bool $second_excluded = false ): array {
		return array(
			array(
				'result_id'       => 11,
				'maprun_id'       => '591278',
				'display_name'    => 'Warren Wolstenholme',
				'time_display'    => '53:23',
				'course_revision' => 30,
				'score'           => 760,
				'penalty'         => 0,
				'is_excluded'     => $first_excluded,
			),
			array(
				'result_id'       => 12,
				'maprun_id'       => '591275',
				'display_name'    => 'Warren Wolstenholme',
				'time_display'    => '53:23',
				'course_revision' => null,
				'score'           => 730,
				'penalty'         => 0,
				'is_excluded'     => $second_excluded,
			),
		);
	}

	public function test_an_untouched_cluster_still_needs_deciding(): void {
		$described = ( new Duplicate_Detector() )->describe( $this->cluster() );

		$this->assertFalse( $described['is_decided'] );
	}

	public function test_a_cluster_with_one_row_excluded_is_decided(): void {
		$described = ( new Duplicate_Detector() )->describe( $this->cluster( false, true ) );

		$this->assertTrue( $described['is_decided'] );
	}

	public function test_a_cluster_excluded_all_through_is_also_decided(): void {
		// Both rows judged junk. There is no scoring left to choose between, so
		// it must not sit on the screen asking to be answered forever.
		$described = ( new Duplicate_Detector() )->describe( $this->cluster( true, true ) );

		$this->assertTrue( $described['is_decided'] );
	}

	public function test_the_options_carry_which_row_was_excluded(): void {
		$described = ( new Duplicate_Detector() )->describe( $this->cluster( false, true ) );

		// Later revision first, as describe() orders them.
		$this->assertSame( 30, $described['options'][0]['revision'] );
		$this->assertFalse( $described['options'][0]['is_excluded'] );
		$this->assertTrue( $described['options'][1]['is_excluded'] );
	}

	public function test_choosing_a_row_keeps_it_and_excludes_the_rest(): void {
		$resolved = Duplicate_Detector::resolve( array( $this->cluster() ), array( 11 ) );

		$this->assertSame( array( 11 ), $resolved['kept'] );
		$this->assertSame( array( 12 ), $resolved['excluded'] );
	}

	public function test_changing_your_mind_clears_the_earlier_exclusion(): void {
		// The bug this file exists for. Row 12 was excluded by an earlier
		// decision; picking it now must return it as kept, so the caller can
		// clear that exclusion rather than adding row 11's on top and leaving
		// the runner excluded twice over and absent from the results.
		$resolved = Duplicate_Detector::resolve( array( $this->cluster( false, true ) ), array( 12 ) );

		$this->assertSame( array( 12 ), $resolved['kept'] );
		$this->assertSame( array( 11 ), $resolved['excluded'] );
		$this->assertNotContains( 12, $resolved['excluded'] );
	}

	public function test_re_confirming_the_same_choice_changes_nothing(): void {
		// Decided clusters submit their standing answer on every save, so this
		// is the common case, and it must be a no-op rather than a correction.
		$resolved = Duplicate_Detector::resolve( array( $this->cluster( false, true ) ), array( 11 ) );

		$this->assertSame( array( 11 ), $resolved['kept'] );
		$this->assertSame( array( 12 ), $resolved['excluded'] );
	}

	public function test_an_unanswered_cluster_is_left_entirely_alone(): void {
		// Nothing picked means the Exclude boxes govern, which is how a cluster
		// that is junk all through gets excluded in full.
		$resolved = Duplicate_Detector::resolve( array( $this->cluster() ), array() );

		$this->assertSame( array(), $resolved['kept'] );
		$this->assertSame( array(), $resolved['excluded'] );
	}

	public function test_a_pick_that_matches_no_cluster_is_ignored(): void {
		// The ids come from a form; only the clusters say which are real.
		$resolved = Duplicate_Detector::resolve( array( $this->cluster() ), array( 999 ) );

		$this->assertSame( array(), $resolved['kept'] );
		$this->assertSame( array(), $resolved['excluded'] );
	}

	public function test_each_cluster_is_answered_independently(): void {
		$other = array(
			array( 'result_id' => 21, 'score' => 500, 'course_revision' => 20, 'is_excluded' => false ),
			array( 'result_id' => 22, 'score' => 480, 'course_revision' => null, 'is_excluded' => false ),
		);

		$resolved = Duplicate_Detector::resolve(
			array( $this->cluster(), $other ),
			array( 11, 22 )
		);

		$this->assertSame( array( 11, 22 ), $resolved['kept'] );
		$this->assertSame( array( 12, 21 ), $resolved['excluded'] );
	}

	public function test_a_kept_row_is_not_excluded_even_with_a_stale_tick(): void {
		// The exact shape of the bug. Row 12 was excluded by an earlier
		// decision, so its Exclude box renders ticked and comes back ticked.
		// Picking it as the keeper has to beat that tick, or both rows of the
		// cluster end up excluded and the runner scores nothing.
		$resolved = Duplicate_Detector::resolve( array( $this->cluster( false, true ) ), array( 12 ) );

		$this->assertFalse( Duplicate_Detector::is_excluded( 12, true, $resolved ) );
		$this->assertTrue( Duplicate_Detector::is_excluded( 11, false, $resolved ) );
	}

	public function test_a_row_no_decision_covers_follows_its_checkbox(): void {
		// Everyone not in an answered cluster: the Exclude box is the only
		// thing that has an opinion, so it governs.
		$resolved = Duplicate_Detector::resolve( array( $this->cluster() ), array( 11 ) );

		$this->assertTrue( Duplicate_Detector::is_excluded( 77, true, $resolved ) );
		$this->assertFalse( Duplicate_Detector::is_excluded( 77, false, $resolved ) );
	}

	public function test_an_unanswered_cluster_can_be_excluded_entirely_by_hand(): void {
		// Both rows junk: no radio picked, both boxes ticked, both excluded.
		$resolved = Duplicate_Detector::resolve( array( $this->cluster() ), array() );

		$this->assertTrue( Duplicate_Detector::is_excluded( 11, true, $resolved ) );
		$this->assertTrue( Duplicate_Detector::is_excluded( 12, true, $resolved ) );
	}

	public function test_answering_one_cluster_leaves_another_untouched(): void {
		$other = array(
			array( 'result_id' => 21, 'score' => 500, 'course_revision' => 20, 'is_excluded' => false ),
			array( 'result_id' => 22, 'score' => 480, 'course_revision' => null, 'is_excluded' => false ),
		);

		$resolved = Duplicate_Detector::resolve( array( $this->cluster(), $other ), array( 11 ) );

		$this->assertSame( array( 11 ), $resolved['kept'] );
		$this->assertSame( array( 12 ), $resolved['excluded'] );
	}
}
