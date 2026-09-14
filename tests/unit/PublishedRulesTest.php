<?php
/**
 * Tests that what the plugin publishes is what the series is actually scored by.
 *
 * Three rules used to be written twice: the courses on offer were a literal
 * `array( '60', '40' )` in six admin screens, the published event footnote said
 * "multiplied by 150%", and the league footnote said "the best 5 results
 * count". All three are configurable on the series row, so each was a sentence
 * that would start lying the first time a season differed — and an offered
 * course the config does not know is worse than a wrong footnote, because
 * factor_for_course() leaves it unscaled while the numeric fallback still
 * charges it a late penalty. The result looks plausible and is wrong.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Event_Presenter;
use MVOC\StreetO\Domain\League_Presenter;
use MVOC\StreetO\Domain\Scoring_Config;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\Scoring_Config::course_labels
 * @covers \MVOC\StreetO\Domain\Event_Presenter::present
 * @covers \MVOC\StreetO\Domain\League_Presenter::present
 */
class PublishedRulesTest extends TestCase {

	public function test_the_default_courses_come_from_the_config(): void {
		$this->assertSame( array( '60', '40' ), ( new Scoring_Config() )->course_labels() );
	}

	/**
	 * Longest first, whatever order the config happens to list them in: the
	 * screens offer the long course as the default and fill its MapRun name
	 * first, so the ordering carries meaning rather than being cosmetic.
	 */
	public function test_courses_are_returned_longest_first(): void {
		$config = new Scoring_Config(
			array(
				'course_factors' => array(
					'30' => 2.0,
					'90' => 0.667,
					'60' => 1.0,
				),
			)
		);

		$this->assertSame( array( '90', '60', '30' ), $config->course_labels() );
	}

	/**
	 * A season on different courses offers those, not last season's.
	 */
	public function test_a_reconfigured_series_offers_its_own_courses(): void {
		$config = new Scoring_Config(
			array(
				'course_factors' => array(
					'60' => 1.0,
					'45' => 1.333,
				),
			)
		);

		$this->assertSame( array( '60', '45' ), $config->course_labels() );
		$this->assertNotContains( '40', $config->course_labels() );
	}

	public function test_the_event_footnote_reports_the_real_scaling(): void {
		$config = new Scoring_Config(
			array(
				'course_factors' => array(
					'60' => 1.0,
					'45' => 1.333,
				),
			)
		);

		$model = ( new Event_Presenter( $config ) )->present(
			array(
				$this->row( '60', 500 ),
				$this->row( '45', 400 ),
			)
		);

		$this->assertTrue( $model['has_short_course'] );
		$this->assertSame(
			array( array( 'label' => '45', 'percent' => 133 ) ),
			$model['scaled_courses']
		);
	}

	/**
	 * An event nobody ran the short course at gets no footnote at all.
	 */
	public function test_no_scaled_course_means_no_footnote(): void {
		$model = ( new Event_Presenter() )->present( array( $this->row( '60', 500 ) ) );

		$this->assertFalse( $model['has_short_course'] );
		$this->assertSame( array(), $model['scaled_courses'] );
	}

	/**
	 * Each scaled course is reported once, however many ran it.
	 */
	public function test_a_scaled_course_is_reported_once(): void {
		$model = ( new Event_Presenter() )->present(
			array(
				$this->row( '40', 400 ),
				$this->row( '40', 380 ),
				$this->row( '60', 500 ),
			)
		);

		$this->assertCount( 1, $model['scaled_courses'] );
		$this->assertSame( 150, $model['scaled_courses'][0]['percent'] );
	}

	public function test_the_league_footnote_reports_how_many_scores_count(): void {
		$model = ( new League_Presenter( new Scoring_Config( array( 'counting_events' => 6 ) ) ) )
			->present( array(), array(), 'overall' );

		$this->assertSame( 6, $model['counting_events'] );
	}

	public function test_the_league_footnote_defaults_to_the_workbook_rule(): void {
		$model = ( new League_Presenter() )->present( array(), array(), 'overall' );

		$this->assertSame( 5, $model['counting_events'] );
	}

	/**
	 * A scored row as Scoring_Engine hands one to the presenter.
	 *
	 * @param string $course Course label.
	 * @param int    $score  Raw score.
	 * @return array<string,mixed>
	 */
	private function row( string $course, int $score ): array {
		return array(
			'display_name'   => 'A Runner',
			'club'           => 'MVOC',
			'course_label'   => $course,
			'score'          => $score,
			'penalty'        => 0,
			'total'          => $score,
			'position'       => 1,
			'position_label' => '1st',
			'league_points'  => 100,
		);
	}
}
