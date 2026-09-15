<?php
/**
 * Tests for a row MapRun recorded no completed run for.
 *
 * `--` was handled from the start. `DNF` was not handled anywhere: it is not
 * `--`, so nothing excluded it, and Scoring_Engine ranks on "is the score
 * numeric?" — where zero is numeric. Every DNF row therefore ranked.
 *
 * A real Cobham response had eight, in two shapes that look nothing alike:
 *
 *   Seven were empty on every measure — no time, no score, no punches at all,
 *   indistinguishable from the `--` rows beside them except for the label.
 *   Each ranked equal-55th and drew 46 league points. A runner whose only row
 *   was one of these would have banked 46 for opening the app and walking
 *   away, against 50 for genuinely finishing 51st.
 *
 *   One was not empty. Sixteen controls, 550 points, real punch times — and no
 *   finish punch, so no elapsed time. It ranked 47th and pushed thirteen
 *   runners down a place each. Under the club's rule a missing finish punch
 *   means no score, so it should not have been there either.
 *
 * Both are now excluded on import and stay visible on the review screen. The
 * zero-elapsed-time condition is the safety catch, and is tested here because
 * it is the thing that stops the rule eating a result nobody knew about.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Scoring_Config;
use MVOC\StreetO\Domain\Scoring_Engine;
use MVOC\StreetO\MapRun\Parser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\MapRun\Parser::is_unfinished
 */
class UnfinishedRowTest extends TestCase {

	/**
	 * @param array<string,mixed> $overrides Field overrides.
	 * @return array<string,mixed>
	 */
	private function maprun_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'Id'                      => 584264,
				'Surname'                 => 'Ashdown',
				'Firstname'               => 'Rowan',
				'Gender'                  => 'F',
				'StartPunchTimeLocal'     => '19:50:00',
				'FinishPunchTimeLocal'    => '00:00:00',
				'TotalTimehhmmss'         => '00:00',
				'TotalTimeSecs'           => 0,
				'Classifier'              => 'DNF',
				'GrossScore'              => 0,
				'NetScore'                => 0,
				'punchControlIds'         => array(),
				'punchTimeAfterStartSecs' => array(),
			),
			$overrides
		);
	}

	public function test_an_abandoned_dnf_is_excluded_on_import(): void {
		$this->assertTrue( ( new Parser() )->parse_row( $this->maprun_row() )['is_failed'] );
	}

	public function test_a_dnf_carrying_a_score_is_excluded_too(): void {
		// The 550-point row. It ran, it scored, it never punched the finish —
		// and the club's rule is that the last of those settles it.
		$parsed = ( new Parser() )->parse_row(
			$this->maprun_row(
				array(
					'GrossScore'      => 550,
					'NetScore'        => 550,
					'punchControlIds' => array( '58', '43', '27' ),
					'punchTimeAfterStartSecs' => array( 29, 121, 163 ),
				)
			)
		);

		$this->assertTrue( $parsed['is_failed'] );
		// The score is still recorded, because excluding is not deleting: the
		// row stays on the review screen with its 550 showing, and the
		// co-ordinator can put it back if the night says otherwise.
		$this->assertSame( 550, $parsed['score'] );
	}

	public function test_a_failed_upload_is_still_excluded(): void {
		$this->assertTrue(
			( new Parser() )->parse_row( $this->maprun_row( array( 'Classifier' => '--' ) ) )['is_failed']
		);
	}

	public function test_a_completed_run_is_untouched(): void {
		$parsed = ( new Parser() )->parse_row(
			$this->maprun_row(
				array(
					'Classifier'    => 'OK',
					'TotalTimeSecs' => 3468,
					'GrossScore'    => 700,
					'NetScore'      => 700,
				)
			)
		);

		$this->assertFalse( $parsed['is_failed'] );
	}

	public function test_a_dnf_with_a_real_time_is_left_for_the_coordinator(): void {
		// The safety catch. Zero elapsed time is the trace of the finish punch
		// that never came; a DNF carrying a real one is something this rule has
		// not seen, and the honest response to that is to leave it visible
		// rather than drop a result on a guess.
		$this->assertFalse( Parser::is_unfinished( Parser::CLASSIFIER_DNF, 3468 ) );
		$this->assertFalse( Parser::is_unfinished( Parser::CLASSIFIER_FAILED, 3468 ) );
	}

	public function test_a_hand_added_row_is_never_unfinished(): void {
		// It legitimately carries a score and no elapsed time, which is the
		// exact shape this rule is looking for.
		$this->assertFalse( Parser::is_unfinished( Parser::CLASSIFIER_MANUAL, null ) );
		$this->assertFalse( Parser::is_unfinished( 'OK', 0 ) );
	}

	public function test_an_excluded_dnf_takes_no_place_and_no_points(): void {
		// What all of it is for: the row must stop ranking, or it goes on
		// taking a position and league points from a run that did not finish.
		$config = new Scoring_Config();
		$engine = new Scoring_Engine( $config );

		$scored = $engine->score_event(
			array(
				array( 'score' => 700, 'course_label' => '60', 'is_excluded' => false ),
				array( 'score' => 550, 'course_label' => '60', 'is_excluded' => true ),
				array( 'score' => 540, 'course_label' => '60', 'is_excluded' => false ),
			)
		);

		$by_score = array_column( $scored, null, 'score' );

		$this->assertNull( $by_score[550]['position'] );
		$this->assertNull( $by_score[550]['league_points'] );
		// And the runner below it moves up, which is the whole point.
		$this->assertSame( 2, $by_score[540]['position'] );
	}
}
