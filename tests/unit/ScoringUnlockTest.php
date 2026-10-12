<?php
/**
 * Tests the unlock scheme against the World Cup style event it was built for.
 *
 * Four groups of six (11-16, 21-26, 31-36, 41-46) at 10 each. Quarter finals
 * 19, 29, 39, 49 (20 each) need five of their own group. Semi finals 79
 * (needs 19 and 39) and 89 (needs 29 and 49) are 30 each. The final, 99, is
 * 50 and needs both semi finals.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Scheme_Result;
use MVOC\StreetO\Domain\Scoring_Rule_Set;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\Scheme_Unlock_Rules
 */
class ScoringUnlockTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function world_cup(): array {
		$controls = array();
		$unlocks  = array();

		foreach ( array( 1, 2, 3, 4 ) as $group ) {
			$members = array();

			for ( $n = 1; $n <= 6; $n++ ) {
				$controls[ $group . $n ] = 10;
				$members[]               = $group . $n;
			}

			$controls[ $group . '9' ] = 20;
			$unlocks[ $group . '9' ]  = array(
				'after'    => $members,
				'at_least' => 5,
			);
		}

		$controls['79'] = 30;
		$controls['89'] = 30;
		$controls['99'] = 50;
		$unlocks['79']  = array(
			'after'    => array( '19', '39' ),
			'at_least' => 2,
		);
		$unlocks['89']  = array(
			'after'    => array( '29', '49' ),
			'at_least' => 2,
		);
		$unlocks['99']  = array(
			'after'    => array( '79', '89' ),
			'at_least' => 2,
		);

		return array(
			'name'                 => 'World Cup',
			'scheme'               => 'unlock_rules',
			'default_control_rule' => 'none',
			'controls'             => $controls,
			'params'               => array( 'unlocks' => $unlocks ),
		);
	}

	private function rules( ?array $definition = null ): Scoring_Rule_Set {
		$parsed = Scoring_Rule_Set::from_array( $definition ?? $this->world_cup() );
		$this->assertSame( array(), $parsed['errors'] );

		return $parsed['rules'];
	}

	/**
	 * @param string[] $controls Control ids in order.
	 */
	private function score( array $controls, ?Scoring_Rule_Set $rules = null ): Scheme_Result {
		$punches = array();

		foreach ( $controls as $index => $control ) {
			$punches[] = array(
				'control'   => $control,
				'time_secs' => ( $index + 1 ) * 30,
				'is_extra'  => false,
			);
		}

		return ( $rules ?? $this->rules() )->score( $punches );
	}

	public function test_group_controls_score_in_any_order(): void {
		$this->assertSame( 60, $this->score( array( '16', '11', '14', '13', '12', '15' ) )->score );
	}

	public function test_a_quarter_final_before_five_group_controls_does_not_score(): void {
		$result = $this->score( array( '11', '12', '13', '14', '19' ) );

		$this->assertSame( 40, $result->score );
		$this->assertSame( Scheme_Result::IGNORED, $result->breakdown[4]['outcome'] );
	}

	public function test_it_scores_when_punched_again_once_unlocked(): void {
		$result = $this->score( array( '11', '12', '13', '14', '19', '15', '19' ) );

		$this->assertSame( 50 + 20, $result->score );
	}

	public function test_five_of_six_unlocks_and_the_sixth_still_scores_afterwards(): void {
		$result = $this->score( array( '11', '12', '13', '14', '15', '19', '16' ) );

		$this->assertSame( 60 + 20, $result->score );
	}

	public function test_a_semi_final_needs_both_its_quarter_finals_scored(): void {
		$one_qf = array( '11', '12', '13', '14', '15', '19', '79' );

		$this->assertSame( 50 + 20, $this->score( $one_qf )->score );

		$both = array( '11', '12', '13', '14', '15', '19', '31', '32', '33', '34', '35', '39', '79' );

		$this->assertSame( 100 + 40 + 30, $this->score( $both )->score );
	}

	public function test_a_quarter_final_punched_too_early_does_not_unlock_the_semi(): void {
		// 39 is punched with only four of group 3 done, so it has not scored.
		$result = $this->score(
			array( '11', '12', '13', '14', '15', '19', '31', '32', '33', '34', '39', '79' )
		);

		$this->assertSame( 50 + 20 + 40, $result->score );
	}

	public function test_the_final_needs_both_semi_finals_and_group_controls_still_count_after_it(): void {
		$route = array();

		foreach ( array( 1, 2, 3, 4 ) as $group ) {
			for ( $n = 1; $n <= 5; $n++ ) {
				$route[] = $group . $n;
			}

			$route[] = $group . '9';
		}

		$route = array_merge( $route, array( '79', '89', '99', '16', '26', '36', '46' ) );

		// 24 group controls, 4 QFs, 2 SFs and the final.
		$this->assertSame( 240 + 80 + 60 + 50, $this->score( $route )->score );
	}

	public function test_the_final_before_both_semis_does_not_score(): void {
		$result = $this->score( array( '99' ) );

		$this->assertSame( 0, $result->score );
	}

	public function test_a_repeat_punch_scores_once(): void {
		$this->assertSame( 10, $this->score( array( '11', '11' ) )->score );
	}

	public function test_no_punches_is_null(): void {
		$this->assertNull( $this->rules()->score( array() )->score );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public function invalid_provider(): array {
		$base = static fn( array $unlocks ): array => array(
			'scheme' => 'unlock_rules',
			'params' => array( 'unlocks' => $unlocks ),
		);

		return array(
			'at_least above the list' => array(
				$base( array( '19' => array( 'after' => array( '11', '12' ), 'at_least' => 3 ) ) ),
				'at_least',
			),
			'at_least zero'           => array(
				$base( array( '19' => array( 'after' => array( '11' ), 'at_least' => 0 ) ) ),
				'at_least',
			),
			'unlocked by itself'      => array(
				$base( array( '19' => array( 'after' => array( '19' ), 'at_least' => 1 ) ) ),
				'by itself',
			),
			'a loop'                  => array(
				$base(
					array(
						'19' => array( 'after' => array( '29' ), 'at_least' => 1 ),
						'29' => array( 'after' => array( '19' ), 'at_least' => 1 ),
					)
				),
				'wait on each other',
			),
			'unknown key'             => array(
				$base( array( '19' => array( 'after' => array( '11' ), 'at_least' => 1, 'extra' => 1 ) ) ),
				'unknown key',
			),
			'no unlocks'              => array( $base( array() ), 'non-empty' ),
		);
	}

	/**
	 * @dataProvider invalid_provider
	 *
	 * @param array<string,mixed> $definition Rule set.
	 * @param string              $fragment   Expected in the first error.
	 */
	public function test_bad_definitions_are_rejected( array $definition, string $fragment ): void {
		$parsed = Scoring_Rule_Set::from_array( $definition );

		$this->assertNull( $parsed['rules'] );
		$this->assertStringContainsString( $fragment, $parsed['errors'][0] );
	}

	public function test_the_world_cup_definition_survives_a_json_round_trip(): void {
		$rules = $this->rules();
		$again = Scoring_Rule_Set::parse( $rules->to_json() );

		$this->assertSame( array(), $again['errors'] );
		$this->assertSame( $rules->to_json(), $again['rules']->to_json() );
	}
}
