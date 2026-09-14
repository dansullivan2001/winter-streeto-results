<?php
/**
 * Tests that a parsed row records which MapRun field its score came from.
 *
 * The MapRun Explorer exists to confirm the contract on an unfamiliar response,
 * and it reported the score field by reading `score_field` off each parsed row.
 * The parser never returned that key, so the check was always empty and the
 * screen always took its failure branch: "No score field recognised", on
 * responses it had just parsed perfectly.
 *
 * Nothing caught it. php -l cannot see a missing array key, and
 * tools/check-references.php audits self:: constants and $this-> calls, not
 * keys. So the contract is asserted here instead.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\MapRun\Parser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\MapRun\Parser::parse_row
 */
class ScoreFieldTest extends TestCase {

	public function test_a_parsed_row_names_the_field_the_score_came_from(): void {
		$row = ( new Parser() )->parse_row(
			array(
				'Id'         => '1',
				'GrossScore' => 660,
				'NetScore'   => 630,
			)
		);

		$this->assertSame( 'GrossScore', $row['score_field'] );
		$this->assertSame( 660, $row['score'] );
	}

	/**
	 * Gross is the score the club publishes, so it is the one named.
	 */
	public function test_gross_wins_where_both_are_present(): void {
		$row = ( new Parser() )->parse_row(
			array(
				'Id'         => '1',
				'GrossScore' => 500,
				'NetScore'   => 500,
			)
		);

		$this->assertSame( 'GrossScore', $row['score_field'] );
	}

	public function test_a_lone_net_score_is_named_as_such(): void {
		$row = ( new Parser() )->parse_row(
			array(
				'Id'       => '1',
				'NetScore' => 470,
			)
		);

		$this->assertSame( 'NetScore', $row['score_field'] );
		$this->assertSame( 470, $row['score'] );
	}

	/**
	 * The only case where the Explorer's warning is the honest answer.
	 */
	public function test_a_row_with_no_score_names_no_field(): void {
		$row = ( new Parser() )->parse_row( array( 'Id' => '1' ) );

		$this->assertSame( '', $row['score_field'] );
		$this->assertNull( $row['score'] );
	}

	/**
	 * The real response, end to end: the Explorer must recognise the field on
	 * the fixture the whole parser was pinned against.
	 */
	public function test_the_real_response_reports_a_score_field(): void {
		$payload = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/maprun-worcester-park.json' );
		$rows    = ( new Parser() )->parse( Parser::unwrap( json_decode( $payload, true ) ) );

		$fields = array_filter( array_column( $rows, 'score_field' ) );

		$this->assertNotEmpty( $fields, 'the Explorer would report "no score field recognised"' );
		$this->assertSame( 'GrossScore', reset( $fields ) );
	}
}
