<?php
/**
 * Tests for the club's late penalty, and for it replacing MapRun's.
 *
 * MapRun charges 30 points per *started* minute. The club charges 1 point per
 * 2 seconds — the same rate, an order of magnitude finer — so a 47-second
 * overrun costs 24 points rather than 30. Every expectation below is worked
 * from the real Worcester Park response, whose elapsed times and MapRun
 * penalties are both committed as a fixture.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Scoring_Config;
use MVOC\StreetO\Domain\Scoring_Engine;
use MVOC\StreetO\MapRun\Parser;
use MVOC\StreetO\Repo\Results_Repo;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\Scoring_Config
 * @covers \MVOC\StreetO\Repo\Results_Repo
 * @covers \MVOC\StreetO\MapRun\Parser
 */
class LatePenaltyTest extends TestCase {

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function parsed(): array {
		$response = json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/fixtures/maprun-worcester-park.json' ),
			true
		);

		return ( new Parser() )->parse( Parser::unwrap( $response ), '60' );
	}

	/**
	 * A stored result row, as the repo hydrates one.
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

	public function test_a_part_block_is_charged_in_full(): void {
		// Leonard Quilter finished 47 seconds over the hour. At 1 point per 2
		// seconds that is 23 whole blocks and part of a twenty-fourth, and the
		// part counts: 24, where MapRun charged a whole minute's 30.
		$this->assertSame( 24, ( new Scoring_Config() )->late_penalty( 3647, '60' ) );
	}

	public function test_one_second_late_costs_a_point(): void {
		$this->assertSame( 1, ( new Scoring_Config() )->late_penalty( 3601, '60' ) );
	}

	public function test_finishing_on_the_limit_costs_nothing(): void {
		$this->assertSame( 0, ( new Scoring_Config() )->late_penalty( 3600, '60' ) );
	}

	public function test_finishing_inside_the_limit_costs_nothing(): void {
		$this->assertSame( 0, ( new Scoring_Config() )->late_penalty( 3593, '60' ) );
	}

	public function test_the_short_course_is_measured_against_its_own_limit(): void {
		// 40 minutes is 2400 seconds, so 3593 is not a good run — it is over
		// nineteen minutes late.
		$this->assertSame( 0, ( new Scoring_Config() )->late_penalty( 2400, '40' ) );
		$this->assertSame( 597, ( new Scoring_Config() )->late_penalty( 3593, '40' ) );
	}

	public function test_an_unconfigured_numeric_course_reads_as_minutes(): void {
		$this->assertSame( 2700, ( new Scoring_Config() )->time_limit_for_course( '45' ) );
		$this->assertSame( 1, ( new Scoring_Config() )->late_penalty( 2702, '45' ) );
	}

	public function test_a_course_with_no_knowable_limit_is_not_penalised(): void {
		// Null, never zero. Without a limit there is no lateness to measure,
		// and the caller must fall back to MapRun rather than wipe its penalty.
		$config = new Scoring_Config();

		$this->assertNull( $config->time_limit_for_course( 'sprint' ) );
		$this->assertNull( $config->late_penalty( 3647, 'sprint' ) );
	}

	public function test_a_missing_elapsed_time_is_not_read_as_being_on_time(): void {
		$this->assertNull( ( new Scoring_Config() )->late_penalty( null, '60' ) );
	}

	public function test_the_rule_can_be_turned_off(): void {
		$config = new Scoring_Config( array( 'recompute_late_penalty' => false ) );

		$this->assertNull( $config->late_penalty( 3647, '60' ) );
	}

	public function test_maprun_own_rule_is_reproducible_from_the_elapsed_time(): void {
		// The cross-check that proves the elapsed time and the limit are the
		// right inputs: configured as 30 points per started minute, the rule
		// must reproduce GrossScore - NetScore for every real row.
		$config = new Scoring_Config(
			array(
				'late_penalty_points'  => 30,
				'late_penalty_seconds' => 60,
			)
		);

		$checked = 0;
		foreach ( $this->parsed() as $row ) {
			if ( null === $row['time_secs'] || 0 === $row['time_secs'] ) {
				continue;
			}

			$this->assertSame(
				$row['penalty'],
				$config->late_penalty( $row['time_secs'], '60' ),
				$row['display_name'] . ': MapRun rule should reproduce its own penalty'
			);
			++$checked;
		}

		$this->assertGreaterThan( 10, $checked );
	}

	public function test_the_clubs_penalty_replaces_maprun_on_a_stored_row(): void {
		$effective = Results_Repo::effective( $this->stored(), new Scoring_Config() );

		$this->assertSame( 24, $effective['penalty'] );
		$this->assertSame( 30, $effective['maprun_penalty'] );
		$this->assertSame( 3647, $effective['time_secs'] );
	}

	public function test_maprun_penalty_stands_without_a_config(): void {
		// The unmatched-names screen resolves rows only to read their names, so
		// it passes no rules. It must get MapRun's figure, not a wrong one.
		$effective = Results_Repo::effective( $this->stored() );

		$this->assertSame( 30, $effective['penalty'] );
	}

	public function test_maprun_penalty_stands_where_the_time_is_unknown(): void {
		$row = $this->stored(
			array(
				'raw_time_secs'      => null,
				'resolved_time_secs' => null,
			)
		);

		$this->assertSame( 30, Results_Repo::effective( $row, new Scoring_Config() )['penalty'] );
	}

	public function test_a_correction_still_beats_the_recomputed_penalty(): void {
		$row = $this->stored( array( 'resolved_penalty' => 0 ) );

		// Zero is a real correction — GPS dropout confirmed with the runner —
		// and must not fall through to either derived value.
		$this->assertSame( 0, Results_Repo::effective( $row, new Scoring_Config() )['penalty'] );
	}

	public function test_a_corrected_course_changes_the_limit_the_penalty_uses(): void {
		$row = $this->stored( array( 'resolved_course_label' => '40' ) );

		// 3647 against a 40-minute limit is 1247 seconds late.
		$this->assertSame( 624, Results_Repo::effective( $row, new Scoring_Config() )['penalty'] );
	}

	public function test_the_event_total_uses_the_clubs_penalty(): void {
		$scored = ( new Scoring_Engine() )->score_event(
			array( Results_Repo::effective( $this->stored(), new Scoring_Config() ) )
		);

		// 780 - 24, not MapRun's NetScore of 750.
		$this->assertSame( 756, $scored[0]['total'] );
	}

	public function test_elapsed_times_render_the_way_maprun_writes_them(): void {
		$response = json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/fixtures/maprun-worcester-park.json' ),
			true
		);

		$checked = 0;
		foreach ( Parser::unwrap( $response ) as $row ) {
			$this->assertSame(
				$row['TotalTimehhmmss'],
				Parser::format_hhmmss( (int) $row['TotalTimeSecs'] ),
				'Formatted time should match MapRun'
			);
			++$checked;
		}

		$this->assertGreaterThan( 10, $checked );
	}

	public function test_an_unknown_elapsed_time_renders_as_nothing(): void {
		$this->assertSame( '', Parser::format_hhmmss( null ) );
	}
}
