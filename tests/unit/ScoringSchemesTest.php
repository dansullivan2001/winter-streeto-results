<?php
/**
 * Tests the custom scoring schemes and the rule set that selects them.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Scheme_Result;
use MVOC\StreetO\Domain\Scoring_Rule_Set;
use MVOC\StreetO\Domain\Scoring_Scheme_Registry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\Scoring_Rule_Set
 * @covers \MVOC\StreetO\Domain\Scoring_Scheme_Registry
 * @covers \MVOC\StreetO\Domain\Scheme_Any_Order
 * @covers \MVOC\StreetO\Domain\Scheme_Sequence
 * @covers \MVOC\StreetO\Domain\Scheme_Consecutive_Groups
 */
class ScoringSchemesTest extends TestCase {

	/**
	 * Punches in the order given, in the shape the parser hands over.
	 *
	 * @param string[] $controls Control ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function punches( array $controls ): array {
		$punches = array();

		foreach ( $controls as $index => $control ) {
			$punches[] = array(
				'control'   => $control,
				'time_secs' => ( $index + 1 ) * 60,
				'is_extra'  => false,
			);
		}

		return $punches;
	}

	/**
	 * @param array<string,mixed> $definition Rule set as an array.
	 */
	private function rules( array $definition ): Scoring_Rule_Set {
		$parsed = Scoring_Rule_Set::from_array( $definition );
		$this->assertSame( array(), $parsed['errors'] );

		return $parsed['rules'];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function groups_definition(): array {
		return array(
			'scheme' => 'consecutive_groups',
			'params' => array(
				'group_by'      => 'last_digit',
				'control_range' => array( 10, 59 ),
				'bonus'         => array(
					'start' => 20,
					'step'  => 10,
				),
			),
		);
	}

	public function test_any_order_with_the_default_rule_matches_the_standard_scorer(): void {
		$rules  = $this->rules( array( 'scheme' => 'any_order' ) );
		$result = $rules->score( $this->punches( array( '13', '27', '55', '27' ) ) );

		$this->assertSame( 80, $result->score );
	}

	public function test_any_order_uses_listed_values_over_the_default(): void {
		$rules = $this->rules(
			array(
				'scheme'   => 'any_order',
				'controls' => array( '13' => 75 ),
			)
		);

		$this->assertSame( 95, $rules->score( $this->punches( array( '13', '27' ) ) )->score );
	}

	public function test_default_rule_none_makes_unlisted_controls_worthless(): void {
		$rules = $this->rules(
			array(
				'scheme'               => 'any_order',
				'default_control_rule' => 'none',
				'controls'             => array( '13' => 5 ),
			)
		);

		$this->assertSame( 5, $rules->score( $this->punches( array( '13', '27' ) ) )->score );
	}

	public function test_no_punches_is_null_not_zero(): void {
		$rules = $this->rules( array( 'scheme' => 'any_order' ) );

		$this->assertNull( $rules->score( array() )->score );
	}

	public function test_sequence_counts_only_the_next_expected_control(): void {
		$rules = $this->rules(
			array(
				'scheme' => 'sequence',
				'params' => array( 'sequence' => array( '31', '32', '33' ) ),
			)
		);

		// 33 is out of turn and ignored; 32 then 33 are still to come.
		$result = $rules->score( $this->punches( array( '31', '33', '32', '33' ) ) );

		$this->assertSame( 90, $result->score );
		$this->assertSame( Scheme_Result::IGNORED, $result->breakdown[1]['outcome'] );
	}

	public function test_sequence_keeps_what_was_completed_when_the_runner_stops(): void {
		$rules = $this->rules(
			array(
				'scheme' => 'sequence',
				'params' => array( 'sequence' => array( '31', '32', '33', '34' ) ),
			)
		);

		$this->assertSame( 60, $rules->score( $this->punches( array( '31', '32' ) ) )->score );
	}

	public function test_sequence_ignores_punches_once_it_is_finished(): void {
		$rules = $this->rules(
			array(
				'scheme' => 'sequence',
				'params' => array( 'sequence' => array( '31' ) ),
			)
		);

		$this->assertSame( 30, $rules->score( $this->punches( array( '31', '31' ) ) )->score );
	}

	public function test_a_completed_group_earns_the_bonus(): void {
		$rules = $this->rules( $this->groups_definition() );

		// Last digit 3: 13, 23, 33, 43, 53 = 10+20+30+40+50 = 150, plus 20 bonus.
		$result = $rules->score( $this->punches( array( '53', '13', '33', '23', '43' ) ) );

		$this->assertSame( 170, $result->score );
	}

	public function test_another_groups_control_in_between_breaks_the_run(): void {
		$rules = $this->rules( $this->groups_definition() );

		// 14 splits the group-3 run, so no bonus: just the points.
		$result = $rules->score( $this->punches( array( '13', '23', '14', '33', '43', '53' ) ) );

		$this->assertSame( 10 + 20 + 10 + 30 + 40 + 50, $result->score );
	}

	public function test_a_broken_group_can_be_completed_on_a_later_clean_run(): void {
		$rules = $this->rules( $this->groups_definition() );

		$result = $rules->score(
			$this->punches( array( '13', '23', '14', '13', '23', '33', '43', '53' ) )
		);

		// Points: 13,23,14,33,43,53 once each = 10+20+10+30+40+50; bonus 20.
		$this->assertSame( 160 + 20, $result->score );
	}

	public function test_a_repeat_inside_the_run_does_not_break_it(): void {
		$rules = $this->rules( $this->groups_definition() );

		$result = $rules->score( $this->punches( array( '13', '23', '13', '33', '43', '53' ) ) );

		$this->assertSame( 150 + 20, $result->score );
	}

	public function test_the_bonus_rises_with_each_group_completed_in_order_of_completion(): void {
		$rules = $this->rules(
			array(
				'scheme'   => 'consecutive_groups',
				'controls' => array(
					'11' => 0,
					'12' => 0,
					'21' => 0,
					'22' => 0,
				),
				'default_control_rule' => 'none',
				'params'   => array(
					'groups' => array(
						'A' => array( '11', '12' ),
						'B' => array( '21', '22' ),
					),
					'bonus'  => array(
						'start' => 20,
						'step'  => 10,
					),
				),
			)
		);

		// B completes first and so earns the first (20) bonus, then A earns 30.
		$result = $rules->score( $this->punches( array( '21', '22', '11', '12' ) ) );

		$this->assertSame( 50, $result->score );
	}

	public function test_a_bonus_list_repeats_its_last_value(): void {
		$rules = $this->rules(
			array(
				'scheme'               => 'consecutive_groups',
				'default_control_rule' => 'none',
				'params'               => array(
					'groups' => array(
						'A' => array( '1' ),
						'B' => array( '2' ),
						'C' => array( '3' ),
					),
					'bonus'  => array( 5, 8 ),
				),
			)
		);

		$this->assertSame( 5 + 8 + 8, $rules->score( $this->punches( array( '1', '2', '3' ) ) )->score );
	}

	public function test_an_unlisted_control_neither_counts_to_nor_breaks_a_group(): void {
		$rules = $this->rules(
			array(
				'scheme'               => 'consecutive_groups',
				'default_control_rule' => 'none',
				'params'               => array(
					'groups' => array( 'A' => array( '1', '2' ) ),
					'bonus'  => array( 10 ),
				),
			)
		);

		$this->assertSame( 10, $rules->score( $this->punches( array( '1', '99', '2' ) ) )->score );
	}

	public function test_an_unknown_key_is_an_error_not_ignored(): void {
		$parsed = Scoring_Rule_Set::from_array(
			array(
				'scheme' => 'any_order',
				'contols' => array( '13' => 5 ),
			)
		);

		$this->assertNull( $parsed['rules'] );
		$this->assertContains( 'contols: unknown key.', $parsed['errors'] );
	}

	public function test_an_unknown_scheme_is_an_error(): void {
		$parsed = Scoring_Rule_Set::from_array( array( 'scheme' => 'telepathy' ) );

		$this->assertNull( $parsed['rules'] );
		$this->assertStringContainsString( 'scheme: must be one of', $parsed['errors'][0] );
	}

	public function test_scheme_params_are_validated(): void {
		$parsed = Scoring_Rule_Set::from_array(
			array(
				'scheme' => 'sequence',
				'params' => array( 'sequence' => array() ),
			)
		);

		$this->assertNull( $parsed['rules'] );
		$this->assertStringContainsString( 'params.sequence', $parsed['errors'][0] );
	}

	public function test_a_control_in_two_groups_is_an_error(): void {
		$parsed = Scoring_Rule_Set::from_array(
			array(
				'scheme' => 'consecutive_groups',
				'params' => array(
					'groups' => array(
						'A' => array( '1', '2' ),
						'B' => array( '2', '3' ),
					),
					'bonus'  => array( 10 ),
				),
			)
		);

		$this->assertNull( $parsed['rules'] );
		$this->assertStringContainsString( 'control 2 is in both', $parsed['errors'][0] );
	}

	public function test_json_that_is_not_an_object_is_rejected(): void {
		$this->assertNull( Scoring_Rule_Set::parse( '[1,2]' )['rules'] );
		$this->assertNull( Scoring_Rule_Set::parse( 'nonsense' )['rules'] );
	}

	public function test_a_rule_set_survives_a_round_trip_through_json(): void {
		$rules = $this->rules( $this->groups_definition() );
		$again = Scoring_Rule_Set::parse( $rules->to_json() );

		$this->assertSame( array(), $again['errors'] );
		$this->assertSame( $rules->to_json(), $again['rules']->to_json() );
	}

	/**
	 * Every scheme's own documented example must pass its own validator, so
	 * the prompt can never teach a definition the plugin then rejects.
	 */
	public function test_every_registered_scheme_validates_its_own_example(): void {
		foreach ( Scoring_Scheme_Registry::all() as $id => $scheme ) {
			$parsed = Scoring_Rule_Set::from_array(
				array(
					'scheme' => $id,
					'params' => $scheme->example_params(),
				)
			);

			$this->assertSame( array(), $parsed['errors'], $id );
		}
	}
}
