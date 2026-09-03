<?php
/**
 * Tests for hand-entered results.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Manual_Entry_Parser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\Manual_Entry_Parser
 */
class ManualEntryParserTest extends TestCase {

	public function test_a_single_line_with_everything(): void {
		$result = ( new Manual_Entry_Parser() )->parse( 'Rowan Orpington, 1180, 0, 60' );

		$this->assertSame( array(), $result['errors'] );
		$this->assertCount( 1, $result['rows'] );

		$row = $result['rows'][0];
		$this->assertSame( 'Rowan', $row['first_name'] );
		$this->assertSame( 'Orpington', $row['surname'] );
		$this->assertSame( 1180, $row['score'] );
		$this->assertSame( 0, $row['penalty'] );
		$this->assertSame( '60', $row['course_label'] );
	}

	public function test_tabs_are_accepted_because_spreadsheets_use_them(): void {
		$result = ( new Manual_Entry_Parser() )->parse( "Nadia Dalrymple\t960\t0" );

		$this->assertSame( 960, $result['rows'][0]['score'] );
		$this->assertSame( 'Nadia Dalrymple', $result['rows'][0]['display_name'] );
	}

	public function test_tabs_win_over_commas_in_a_name(): void {
		// "Smith, Dave" pasted from a spreadsheet would otherwise be read as a
		// name and a score.
		$result = ( new Manual_Entry_Parser() )->parse( "Smith, Dave\t500" );

		$this->assertSame( 'Smith, Dave', $result['rows'][0]['display_name'] );
		$this->assertSame( 500, $result['rows'][0]['score'] );
	}

	public function test_a_name_on_its_own_is_enough(): void {
		// Someone who turned up but whose score is not yet known.
		$result = ( new Manual_Entry_Parser() )->parse( 'Callum Pargeter' );

		$this->assertNull( $result['rows'][0]['score'] );
		$this->assertSame( 0, $result['rows'][0]['penalty'] );
	}

	public function test_multi_word_surnames_survive(): void {
		// Hyphens and apostrophes both occur in the club's real data.
		$result = ( new Manual_Entry_Parser() )->parse( "Brian Ashby-Prentice, 500\nSybil O'Halloran, 310" );

		$this->assertSame( 'Ashby-Prentice', $result['rows'][0]['surname'] );
		$this->assertSame( "O'Halloran", $result['rows'][1]['surname'] );
	}

	public function test_a_three_part_name_keeps_the_whole_surname(): void {
		$result = ( new Manual_Entry_Parser() )->parse( 'Maria van der Berg, 400' );

		$this->assertSame( 'Maria', $result['rows'][0]['first_name'] );
		$this->assertSame( 'van der Berg', $result['rows'][0]['surname'] );
	}

	public function test_blank_lines_are_ignored(): void {
		$result = ( new Manual_Entry_Parser() )->parse( "A One, 100\n\n   \nB Two, 200\n" );

		$this->assertCount( 2, $result['rows'] );
	}

	public function test_a_spreadsheet_header_row_is_skipped(): void {
		$result = ( new Manual_Entry_Parser() )->parse( "Name, Score, Penalty\nA One, 100" );

		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( 'A One', $result['rows'][0]['display_name'] );
	}

	public function test_a_header_word_further_down_is_treated_as_a_runner(): void {
		// Only the first line can be a header; a runner actually called Name
		// further down is vanishingly unlikely, but dropping rows silently is
		// worse than the odd oddity getting through.
		$result = ( new Manual_Entry_Parser() )->parse( "A One, 100\nName, 200" );

		$this->assertCount( 2, $result['rows'] );
	}

	public function test_an_unreadable_score_is_reported_not_swallowed(): void {
		// A result quietly missing is far worse than one flagged.
		$result = ( new Manual_Entry_Parser() )->parse( "A One, 100\nB Two, banana\nC Three, 300" );

		$this->assertCount( 2, $result['rows'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'B Two', $result['errors'][0] );
		$this->assertStringContainsString( 'Line 2', $result['errors'][0] );
	}

	public function test_the_default_course_applies_when_a_line_omits_it(): void {
		$result = ( new Manual_Entry_Parser() )->parse( 'A One, 300', '40' );

		$this->assertSame( '40', $result['rows'][0]['course_label'] );
	}

	public function test_a_course_on_the_line_wins(): void {
		$result = ( new Manual_Entry_Parser() )->parse( 'A One, 300, 0, 40 min', '60' );

		$this->assertSame( '40', $result['rows'][0]['course_label'] );
	}

	public function test_a_negative_penalty_is_clamped(): void {
		// A penalty adds to nobody's score.
		$result = ( new Manual_Entry_Parser() )->parse( 'A One, 300, -50' );

		$this->assertSame( 0, $result['rows'][0]['penalty'] );
	}

	public function test_a_whole_field_pasted_from_a_spreadsheet(): void {
		// The case this exists for: MapRun cannot score the event at all.
		$paste = "Name\tScore\tPenalty\n"
			. "Rowan Orpington\t1180\t0\n"
			. "Nadia Dalrymple\t960\t0\n"
			. "Leonard Quilter\t780\t30\n"
			. "Gerald Pemberton\t330\t90\n";

		$result = ( new Manual_Entry_Parser() )->parse( $paste );

		$this->assertSame( array(), $result['errors'] );
		$this->assertCount( 4, $result['rows'] );
		$this->assertSame(
			array( 'Rowan Orpington', 'Nadia Dalrymple', 'Leonard Quilter', 'Gerald Pemberton' ),
			array_column( $result['rows'], 'display_name' )
		);
		$this->assertSame( 30, $result['rows'][2]['penalty'] );
	}

	public function test_empty_input_yields_nothing(): void {
		$result = ( new Manual_Entry_Parser() )->parse( "   \n\n" );

		$this->assertSame( array(), $result['rows'] );
		$this->assertSame( array(), $result['errors'] );
	}
}
