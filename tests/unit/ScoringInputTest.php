<?php
/**
 * Tests pasted scoring definitions and the AI prompt built from the registry.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Scoring_Input;
use MVOC\StreetO\Domain\Scoring_Prompt;
use MVOC\StreetO\Domain\Scoring_Rule_Set;
use MVOC\StreetO\Domain\Scoring_Scheme_Registry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\Scoring_Input
 * @covers \MVOC\StreetO\Domain\Scoring_Prompt
 */
class ScoringInputTest extends TestCase {

	public function test_json_is_passed_to_the_rule_set_parser(): void {
		$parsed = Scoring_Input::parse( '{"scheme":"any_order","controls":{"13":50}}' );

		$this->assertSame( array(), $parsed['errors'] );
		$this->assertSame( array( '13' => 50 ), $parsed['rules']->controls );
	}

	public function test_csv_with_a_header_gives_any_order_scoring(): void {
		$parsed = Scoring_Input::parse( "control,points\n13,50\n27,5", 'Bonus night' );

		$this->assertSame( array(), $parsed['errors'] );
		$this->assertSame( 'any_order', $parsed['rules']->scheme );
		$this->assertSame( 'Bonus night', $parsed['rules']->name );
		$this->assertSame( 'none', $parsed['rules']->default_control_rule );
		$this->assertSame( 55, $parsed['rules']->score( array(
			array( 'control' => '13', 'time_secs' => 1, 'is_extra' => false ),
			array( 'control' => '27', 'time_secs' => 2, 'is_extra' => false ),
		) )->score );
	}

	public function test_csv_with_an_order_column_gives_a_sequence_sorted_by_it(): void {
		$parsed = Scoring_Input::parse( "31\t10\t2\n32\t10\t1\n33\t10\t3" );

		$this->assertSame( array(), $parsed['errors'] );
		$this->assertSame( 'sequence', $parsed['rules']->scheme );
		$this->assertSame( array( '32', '31', '33' ), $parsed['rules']->params['sequence'] );
	}

	public function test_csv_errors_name_the_line(): void {
		$parsed = Scoring_Input::parse( "13,50\nabc,5\n13,60" );

		$this->assertNull( $parsed['rules'] );
		$this->assertStringContainsString( 'Line 2', $parsed['errors'][0] );
		$this->assertStringContainsString( 'Line 3: control 13 appears twice', $parsed['errors'][1] );
	}

	public function test_a_partial_order_column_is_an_error(): void {
		$parsed = Scoring_Input::parse( "31,10,1\n32,10" );

		$this->assertNull( $parsed['rules'] );
	}

	public function test_empty_input_is_an_error(): void {
		$this->assertNull( Scoring_Input::parse( "  \n" )['rules'] );
	}

	public function test_the_prompt_covers_every_scheme_and_each_example_is_valid(): void {
		$prompt = Scoring_Prompt::text();

		foreach ( Scoring_Scheme_Registry::all() as $id => $scheme ) {
			$this->assertStringContainsString( '"' . $id . '"', $prompt );

			preg_match( '/- "' . preg_quote( $id, '/' ) . '".*\n  Example: (.+)/', $prompt, $match );
			$this->assertNotEmpty( $match, $id );
			$this->assertSame( array(), Scoring_Rule_Set::parse( $match[1] )['errors'], $id );
		}
	}
}
