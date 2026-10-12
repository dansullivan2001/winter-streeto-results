<?php
/**
 * Tests that a custom scoring definition rescores stored rows on read.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Import_Reconciler;
use MVOC\StreetO\Domain\Scoring_Config;
use MVOC\StreetO\Domain\Scoring_Rule_Set;
use MVOC\StreetO\MapRun\Parser;
use MVOC\StreetO\Repo\Results_Repo;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Repo\Results_Repo
 * @covers \MVOC\StreetO\Domain\Import_Reconciler
 * @covers \MVOC\StreetO\MapRun\Parser
 */
class CustomScoreOnReadTest extends TestCase {

	private function payload(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/maprun-worcester-park.json' );
	}

	/**
	 * A stored row as the database would hand it back.
	 *
	 * @param array<string,mixed> $extra Columns to add or replace.
	 * @return array<string,mixed>
	 */
	private function stored( array $extra = array() ): array {
		$parsed = ( new Parser() )->parse( Parser::unwrap( json_decode( $this->payload(), true ) ), '60' );
		$row    = null;

		foreach ( $parsed as $candidate ) {
			if ( ! empty( $candidate['punches'] ) ) {
				$row = $candidate;
				break;
			}
		}

		$this->assertNotNull( $row );

		return array_merge(
			Import_Reconciler::raw_columns( $row ),
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
			),
			$extra
		);
	}

	private function flat_rules( int $per_control ): Scoring_Rule_Set {
		$parsed = Scoring_Rule_Set::from_array(
			array(
				'scheme'               => 'any_order',
				'default_control_rule' => 'none',
				'controls'             => array_fill_keys( array_map( 'strval', range( 1, 99 ) ), $per_control ),
			)
		);

		return $parsed['rules'];
	}

	public function test_punches_are_stored_and_read_back(): void {
		$row = $this->stored();

		$this->assertIsString( $row['raw_punches'] );
		$this->assertNotEmpty( json_decode( $row['raw_punches'], true ) );
	}

	public function test_without_a_rule_set_the_maprun_score_stands(): void {
		$row = $this->stored();
		$out = Results_Repo::effective( $row, new Scoring_Config() );

		$this->assertSame( $row['raw_score'], $out['score'] );
		$this->assertNotSame( 'custom', $out['score_source'] );
	}

	public function test_a_rule_set_rescores_from_the_punches_and_keeps_the_maprun_figure(): void {
		$row      = $this->stored();
		$distinct = count( array_unique( array_column( json_decode( $row['raw_punches'], true ), 'control' ) ) );
		$out      = Results_Repo::effective( $row, new Scoring_Config(), $this->flat_rules( 7 ) );

		$this->assertSame( $distinct * 7, $out['score'] );
		$this->assertSame( 'custom', $out['score_source'] );
		$this->assertSame( $row['raw_score'], $out['maprun_score'] );
		$this->assertNotEmpty( $out['score_breakdown'] );
	}

	public function test_a_correction_still_wins_over_custom_scoring(): void {
		$out = Results_Repo::effective( $this->stored( array( 'resolved_score' => 123 ) ), new Scoring_Config(), $this->flat_rules( 7 ) );

		$this->assertSame( 123, $out['score'] );
		$this->assertSame( '', $out['score_source'] );
	}

	public function test_a_row_without_punches_keeps_its_own_score(): void {
		$row = $this->stored( array( 'raw_punches' => null ) );
		$out = Results_Repo::effective( $row, new Scoring_Config(), $this->flat_rules( 7 ) );

		$this->assertSame( $row['raw_score'], $out['score'] );
	}

	public function test_punch_lists_can_be_read_back_out_of_a_stored_payload(): void {
		$lists = Parser::punch_lists( $this->payload() );

		$this->assertNotEmpty( $lists );
		$this->assertIsArray( reset( $lists ) );
	}
}
