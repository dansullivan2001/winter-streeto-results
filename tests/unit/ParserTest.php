<?php
/**
 * Tests for the MapRun response parser.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\MapRun\Parser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\MapRun\Parser
 */
class ParserTest extends TestCase {

	/**
	 * Load a fixture and return its decoded contents.
	 *
	 * @param string $name Fixture file name without extension.
	 * @return array<string,mixed>
	 */
	private function fixture( string $name ): array {
		$path = dirname( __DIR__ ) . '/fixtures/' . $name . '.json';

		return json_decode( (string) file_get_contents( $path ), true );
	}

	public function test_unwrap_returns_result_rows(): void {
		$rows = Parser::unwrap( $this->fixture( 'maprun-confirmed-shape' ) );

		$this->assertCount( 3, $rows );
		$this->assertSame( 'Alice', $rows[0]['Firstname'] );
	}

	public function test_unwrap_rejects_an_error_envelope(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Event not found' );

		Parser::unwrap( $this->fixture( 'maprun-error' ) );
	}

	public function test_unwrap_accepts_a_falsy_error_flag(): void {
		// errorFlag has been seen as both a bool and 0/1; neither false nor 0
		// may be mistaken for an error.
		$rows = Parser::unwrap(
			array(
				'errorFlag' => 0,
				'results'   => array( array( 'Id' => 7 ) ),
			)
		);

		$this->assertCount( 1, $rows );
	}

	public function test_unwrap_rejects_a_missing_results_array(): void {
		$this->expectException( RuntimeException::class );

		Parser::unwrap( array( 'errorFlag' => false ) );
	}

	public function test_parse_row_normalises_the_confirmed_fields(): void {
		$rows   = Parser::unwrap( $this->fixture( 'maprun-confirmed-shape' ) );
		$parsed = ( new Parser() )->parse( $rows, '60' );

		$alice = $parsed[0];
		$this->assertSame( '1', $alice['maprun_id'] );
		$this->assertSame( 'Alice Runner', $alice['display_name'] );
		$this->assertSame( 'MVOC', $alice['club'] );
		$this->assertSame( 'F', $alice['gender'] );
		$this->assertSame( 'OK', $alice['classifier'] );
		$this->assertSame( 3465, $alice['time_secs'] );
		$this->assertSame( '60', $alice['course_label'] );
	}

	public function test_non_ok_classifiers_are_kept_not_dropped(): void {
		// DNF rows must reach the review screen so the co-ordinator can decide,
		// rather than vanishing silently.
		$rows   = Parser::unwrap( $this->fixture( 'maprun-confirmed-shape' ) );
		$parsed = ( new Parser() )->parse( $rows );

		$this->assertCount( 3, $parsed );
		$this->assertSame( 'DNF', $parsed[2]['classifier'] );
	}

	public function test_extra_punches_are_resorted_into_time_order(): void {
		// Bob's third punch is a repeat of control 21, which MapRun appends to
		// the end of the array even though it happened at 250s, before his
		// punch of control 22 at 400s.
		$rows   = Parser::unwrap( $this->fixture( 'maprun-confirmed-shape' ) );
		$parsed = ( new Parser() )->parse( $rows );

		$controls = array_column( $parsed[1]['punches'], 'control' );
		$this->assertSame( array( '21', '21', '22' ), $controls );

		$times = array_column( $parsed[1]['punches'], 'time_secs' );
		$this->assertSame( array( 90, 250, 400 ), $times );
	}

	public function test_punch_order_is_preserved_when_times_cannot_be_paired(): void {
		// A length mismatch means the two arrays cannot be zipped, so MapRun's
		// own order is the best available and must not be shuffled.
		$parsed = ( new Parser() )->parse_row(
			array(
				'punchControlIds'         => array( '5', '6', '7' ),
				'punchTimeAfterStartSecs' => array( 10, 20 ),
			)
		);

		$this->assertSame( array( '5', '6', '7' ), array_column( $parsed['punches'], 'control' ) );
	}

	public function test_score_and_penalty_come_from_gross_and_net(): void {
		// MapRun's GrossScore/NetScore map onto the workbook's Score and Total,
		// with the penalty as the difference between them.
		$parsed = ( new Parser() )->parse_row(
			array( 'GrossScore' => 780, 'NetScore' => 750 )
		);

		$this->assertSame( 780, $parsed['score'] );
		$this->assertSame( 750, $parsed['net_score'] );
		$this->assertSame( 30, $parsed['penalty'] );
	}

	public function test_no_penalty_when_gross_equals_net(): void {
		$parsed = ( new Parser() )->parse_row( array( 'GrossScore' => 550, 'NetScore' => 550 ) );

		$this->assertSame( 0, $parsed['penalty'] );
	}

	public function test_a_lone_score_field_implies_no_penalty(): void {
		// A missing counterpart means no penalty information, and must not be
		// read as a penalty of the entire score.
		$parsed = ( new Parser() )->parse_row( array( 'NetScore' => 400 ) );

		$this->assertSame( 400, $parsed['score'] );
		$this->assertSame( 0, $parsed['penalty'] );
	}

	public function test_score_is_null_when_neither_field_is_present(): void {
		$parsed = ( new Parser() )->parse_row( array( 'Firstname' => 'Nobody' ) );

		$this->assertNull( $parsed['score'] );
		$this->assertSame( 0, $parsed['penalty'] );
	}

	public function test_missing_fields_do_not_fatal(): void {
		$parsed = ( new Parser() )->parse_row( array() );

		$this->assertSame( '', $parsed['display_name'] );
		$this->assertNull( $parsed['time_secs'] );
		$this->assertSame( array(), $parsed['punches'] );
	}

	/**
	 * @dataProvider gender_provider
	 */
	public function test_gender_is_reduced_to_a_single_letter( $input, string $expected ): void {
		$parsed = ( new Parser() )->parse_row( array( 'Gender' => $input ) );

		$this->assertSame( $expected, $parsed['gender'] );
	}

	public function gender_provider(): array {
		return array(
			'female letter' => array( 'F', 'F' ),
			'lowercase'     => array( 'f', 'F' ),
			'full word'     => array( 'Female', 'F' ),
			'male'          => array( 'M', 'M' ),
			'empty'         => array( '', '' ),
			'unrecognised'  => array( 'X', '' ),
		);
	}

	/**
	 * @dataProvider time_provider
	 */
	public function test_hhmmss_parsing( string $input, ?int $expected ): void {
		$this->assertSame( $expected, Parser::parse_hhmmss( $input ) );
	}

	public function time_provider(): array {
		return array(
			'hours'      => array( '0:57:45', 3465 ),
			'over an hour' => array( '1:05:00', 3900 ),
			'mm:ss'      => array( '45:00', 2700 ),
			'empty'      => array( '', null ),
			'nonsense'   => array( 'not a time', null ),
		);
	}

	public function test_field_inventory_lists_every_key_seen(): void {
		$rows      = Parser::unwrap( $this->fixture( 'maprun-confirmed-shape' ) );
		$inventory = Parser::field_inventory( $rows );

		$this->assertArrayHasKey( 'Classifier', $inventory );
		$this->assertSame( 3, $inventory['Classifier']['count'] );
		$this->assertSame( 'array(3)', $inventory['punchControlIds']['sample'] );
	}

	public function test_field_inventory_prefers_a_non_empty_sample(): void {
		// ClubName is empty on the third row but set on the first two; the
		// inventory exists to show what a field looks like, so an empty first
		// value must not become the sample.
		$inventory = Parser::field_inventory(
			array(
				array( 'ClubName' => '' ),
				array( 'ClubName' => 'MVOC' ),
			)
		);

		$this->assertSame( 'MVOC', $inventory['ClubName']['sample'] );
	}
}
