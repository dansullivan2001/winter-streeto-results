<?php
/**
 * Tests the club's control-numbering rule.
 *
 * The rule is the whole basis on which a score MapRun never reported can be
 * published, so it is pinned here against the cases that decide a total: a
 * control punched twice, a repeat MapRun marked and one it did not, and the
 * punches that are worth nothing.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Punch_Scorer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\Punch_Scorer
 */
class PunchScorerTest extends TestCase {

	/**
	 * Build punches in the shape the parser hands over.
	 *
	 * @param array<int,string> $controls Control ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function punches( array $controls ): array {
		return array_map(
			static fn( string $control ): array => array(
				'control'   => $control,
				'time_secs' => 100,
				'is_extra'  => false,
			),
			$controls
		);
	}

	public function test_a_control_is_worth_its_first_digit_times_ten(): void {
		$this->assertSame( 10, Punch_Scorer::points_for( '13' ) );
		$this->assertSame( 20, Punch_Scorer::points_for( '27' ) );
		$this->assertSame( 30, Punch_Scorer::points_for( '30' ) );
		$this->assertSame( 40, Punch_Scorer::points_for( '49' ) );
		$this->assertSame( 50, Punch_Scorer::points_for( '55' ) );
	}

	/**
	 * A start control numbered 1 is worth nothing, by the same arithmetic.
	 */
	public function test_a_control_below_ten_scores_nothing(): void {
		$this->assertSame( 0, Punch_Scorer::points_for( '1' ) );
	}

	public function test_a_control_that_is_not_a_number_scores_nothing(): void {
		$this->assertSame( 0, Punch_Scorer::points_for( 'F' ) );
		$this->assertSame( 0, Punch_Scorer::points_for( '' ) );
	}

	public function test_a_run_scores_the_sum_of_its_controls(): void {
		$scorer = new Punch_Scorer();

		$this->assertSame( 80, $scorer->score( $this->punches( array( '13', '26', '55' ) ) ) );
	}

	/**
	 * However many times it is punched, a control is worth its points once.
	 */
	public function test_a_repeated_control_scores_once(): void {
		$scorer = new Punch_Scorer();

		$this->assertSame( 70, $scorer->score( $this->punches( array( '21', '55', '21' ) ) ) );
	}

	/**
	 * The repeat is recognised by its control number, not by MapRun's marker.
	 *
	 * The parser strips "(Extra)" into a flag and re-sorts the punches into
	 * time order, which moves the marked one away from the end. Counting on
	 * either would let a repeat score twice.
	 */
	public function test_a_repeat_is_recognised_without_its_marker(): void {
		$scorer  = new Punch_Scorer();
		$punches = array(
			array(
				'control'   => '50',
				'time_secs' => 743,
				'is_extra'  => true,
			),
			array(
				'control'   => '50',
				'time_secs' => 422,
				'is_extra'  => false,
			),
		);

		$this->assertSame( 50, $scorer->score( $punches ) );
	}

	/**
	 * Null, not zero: a failed upload has no punches, and did not score nil.
	 */
	public function test_no_punches_at_all_scores_null(): void {
		$this->assertNull( ( new Punch_Scorer() )->score( array() ) );
	}

	public function test_punches_worth_nothing_score_zero(): void {
		$this->assertSame( 0, ( new Punch_Scorer() )->score( $this->punches( array( '1', 'F' ) ) ) );
	}
}
