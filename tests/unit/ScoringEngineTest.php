<?php
/**
 * Tests for the event scoring engine.
 *
 * The headline test replays a real event from the club's 2019/20 workbook and
 * asserts the engine reproduces the workbook's own computed positions and
 * league points, row for row.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Scoring_Config;
use MVOC\StreetO\Domain\Scoring_Engine;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\Scoring_Engine
 * @covers \MVOC\StreetO\Domain\Scoring_Config
 */
class ScoringEngineTest extends TestCase {

	/**
	 * Load the Epsom Downs golden fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function workbook_event(): array {
		return json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/fixtures/workbook-event-epsom-downs.json' ),
			true
		);
	}

	public function test_reproduces_the_workbook_event_exactly(): void {
		$fixture = $this->workbook_event();
		$scored  = ( new Scoring_Engine() )->score_event( $fixture['rows'] );

		$checked = 0;
		foreach ( $scored as $row ) {
			if ( ! empty( $row['organiser'] ) ) {
				continue;
			}

			$this->assertSame(
				$row['expected_total'],
				$row['total'],
				$row['name'] . ': total'
			);
			$this->assertSame(
				$row['expected_position'],
				$row['position'],
				$row['name'] . ': position'
			);
			$this->assertSame(
				$row['expected_league_points'],
				$row['league_points'],
				$row['name'] . ': league points'
			);
			++$checked;
		}

		$this->assertSame( 39, $checked, 'Expected 39 ranked rows in the fixture.' );
	}

	public function test_the_organiser_is_listed_but_not_ranked(): void {
		// The workbook shows the organiser on the results table with a dash for
		// league points, so the row must survive scoring rather than be dropped.
		$fixture = $this->workbook_event();
		$scored  = ( new Scoring_Engine() )->score_event( $fixture['rows'] );

		$organisers = array_values( array_filter( $scored, static fn( $r ) => ! empty( $r['organiser'] ) ) );

		$this->assertCount( 1, $organisers );
		$this->assertSame( 'Greta Yalding', $organisers[0]['name'] );
		$this->assertNull( $organisers[0]['position'] );
		$this->assertNull( $organisers[0]['league_points'] );
	}

	public function test_unranked_rows_sort_to_the_end(): void {
		$scored = ( new Scoring_Engine() )->score_event(
			array(
				array( 'name' => 'Organiser', 'is_organiser' => true ),
				array( 'name' => 'Winner', 'score' => 500, 'penalty' => 0 ),
			)
		);

		$this->assertSame( 'Winner', $scored[0]['name'] );
		$this->assertSame( 'Organiser', $scored[1]['name'] );
	}

	public function test_excluded_rows_are_kept_but_do_not_affect_positions(): void {
		// A course-setter's run must not push everyone else down a place.
		$scored = ( new Scoring_Engine() )->score_event(
			array(
				array( 'name' => 'Setter', 'score' => 900, 'penalty' => 0, 'is_excluded' => true ),
				array( 'name' => 'Winner', 'score' => 500, 'penalty' => 0 ),
			)
		);

		$positions = array_column( $scored, 'position', 'name' );
		$this->assertSame( 1, $positions['Winner'] );
		$this->assertNull( $positions['Setter'] );
	}

	/**
	 * The four tie shapes present in the workbook, isolated.
	 *
	 * @dataProvider tie_provider
	 */
	public function test_tie_break_shapes( array $rows, array $expected ): void {
		$scored = ( new Scoring_Engine() )->score_event( $rows );

		$this->assertSame( $expected, array_column( $scored, 'position', 'name' ) );
	}

	public function tie_provider(): array {
		return array(
			'equal totals share a position, next skips' => array(
				array(
					array( 'name' => 'a', 'score' => 590, 'penalty' => 0 ),
					array( 'name' => 'b', 'score' => 590, 'penalty' => 0 ),
					array( 'name' => 'c', 'score' => 580, 'penalty' => 0 ),
				),
				array( 'a' => 1, 'b' => 1, 'c' => 3 ),
			),
			'three-way tie skips two places' => array(
				array(
					array( 'name' => 'a', 'score' => 560, 'penalty' => 0 ),
					array( 'name' => 'b', 'score' => 560, 'penalty' => 0 ),
					array( 'name' => 'c', 'score' => 560, 'penalty' => 0 ),
					array( 'name' => 'd', 'score' => 530, 'penalty' => 0 ),
				),
				array( 'a' => 1, 'b' => 1, 'c' => 1, 'd' => 4 ),
			),
			'equal total split by penalty' => array(
				array(
					array( 'name' => 'clean', 'score' => 530, 'penalty' => 0 ),
					array( 'name' => 'late', 'score' => 540, 'penalty' => 10 ),
				),
				array( 'clean' => 1, 'late' => 2 ),
			),
			'tie then a penalised runner below it' => array(
				array(
					array( 'name' => 'a', 'score' => 430, 'penalty' => 0 ),
					array( 'name' => 'b', 'score' => 430, 'penalty' => 0 ),
					array( 'name' => 'c', 'score' => 460, 'penalty' => 30 ),
				),
				array( 'a' => 1, 'b' => 1, 'c' => 3 ),
			),
		);
	}

	public function test_finishing_time_never_breaks_a_tie(): void {
		// Explicit guard on the club's most surprising rule: two equal totals
		// with equal penalties finish equal, however fast either runner was.
		$scored = ( new Scoring_Engine() )->score_event(
			array(
				array( 'name' => 'quick', 'score' => 400, 'penalty' => 0, 'time_secs' => 1800 ),
				array( 'name' => 'slow', 'score' => 400, 'penalty' => 0, 'time_secs' => 3599 ),
			)
		);

		$this->assertSame( array( 'quick' => 1, 'slow' => 1 ), array_column( $scored, 'position', 'name' ) );
	}

	public function test_league_points_ladder(): void {
		$config = new Scoring_Config();

		$this->assertSame( 50, $config->points_for_position( 1 ) );
		$this->assertSame( 49, $config->points_for_position( 2 ) );
		$this->assertSame( 1, $config->points_for_position( 50 ) );
		$this->assertSame( 1, $config->points_for_position( 51 ) );
		$this->assertSame( 1, $config->points_for_position( 200 ) );
	}

	public function test_forty_minute_scores_are_scaled_by_150_percent(): void {
		// The club's rule: "a 40 minute score ... where the net score is
		// multiplied by 150% for inclusion in the results". 150% is 60/40.
		$scored = ( new Scoring_Engine() )->score_event(
			array(
				array( 'name' => 'short', 'score' => 300, 'penalty' => 0, 'course_label' => '40' ),
				array( 'name' => 'long', 'score' => 440, 'penalty' => 0, 'course_label' => '60' ),
			)
		);

		$totals = array_column( $scored, 'total', 'name' );
		$this->assertSame( 450, $totals['short'] );
		$this->assertSame( 440, $totals['long'] );

		// The scaled 40-minute runner therefore wins the combined table.
		$this->assertSame( 'short', $scored[0]['name'] );
	}

	public function test_scaling_rounds_to_the_nearest_whole_point(): void {
		// 310 * 1.5 = 465 exactly, so use a value that does not divide evenly:
		// 305 * 1.5 = 457.5, which must round to 458.
		$scored = ( new Scoring_Engine() )->score_event(
			array( array( 'name' => 'x', 'score' => 305, 'penalty' => 0, 'course_label' => '40' ) )
		);

		$this->assertSame( 458, $scored[0]['total'] );
	}

	public function test_penalty_is_deducted_before_scaling(): void {
		// Sourced from the club's own wording - it is the *net* score that is
		// multiplied. (300 - 30) * 1.5 = 405, not 300 * 1.5 - 30 = 420.
		$scored = ( new Scoring_Engine() )->score_event(
			array( array( 'name' => 'x', 'score' => 300, 'penalty' => 30, 'course_label' => '40' ) )
		);

		$this->assertSame( 405, $scored[0]['total'] );
	}

	public function test_penalty_after_scaling_is_configurable(): void {
		// The ordering is now sourced, but stays a setting so a rule change is
		// a config edit rather than a code change.
		$config = new Scoring_Config( array( 'penalty_before_scaling' => false ) );
		$scored = ( new Scoring_Engine( $config ) )->score_event(
			array( array( 'name' => 'x', 'score' => 300, 'penalty' => 30, 'course_label' => '40' ) )
		);

		$this->assertSame( 420, $scored[0]['total'] );
	}

	public function test_an_unknown_course_label_is_not_scaled(): void {
		// A mislabelled course should look odd, not silently score differently.
		$scored = ( new Scoring_Engine() )->score_event(
			array( array( 'name' => 'x', 'score' => 300, 'penalty' => 0, 'course_label' => '90' ) )
		);

		$this->assertSame( 300, $scored[0]['total'] );
	}

	public function test_config_survives_a_json_round_trip(): void {
		$config  = new Scoring_Config( array( 'counting_events' => 4, 'rounding' => Scoring_Config::ROUND_DOWN ) );
		$revived = Scoring_Config::from_json( $config->to_json() );

		$this->assertSame( 4, $revived->counting_events );
		$this->assertSame( Scoring_Config::ROUND_DOWN, $revived->rounding );
		$this->assertSame( 50, $revived->points_for_position( 1 ) );
	}

	/**
	 * @dataProvider ordinal_provider
	 */
	public function test_ordinals( int $number, string $expected ): void {
		$this->assertSame( $expected, Scoring_Engine::ordinal( $number ) );
	}

	public function ordinal_provider(): array {
		return array(
			array( 1, '1st' ),
			array( 2, '2nd' ),
			array( 3, '3rd' ),
			array( 4, '4th' ),
			array( 11, '11th' ),
			array( 12, '12th' ),
			array( 13, '13th' ),
			array( 21, '21st' ),
			array( 22, '22nd' ),
			array( 23, '23rd' ),
			array( 101, '101st' ),
			array( 111, '111th' ),
		);
	}
}
