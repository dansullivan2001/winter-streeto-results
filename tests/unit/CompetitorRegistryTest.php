<?php
/**
 * Tests for the competitor registry.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Competitor_Registry;
use MVOC\StreetO\Domain\Name_Matcher;
use MVOC\StreetO\Domain\Scoring_Config;
use MVOC\StreetO\MapRun\Parser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\Competitor_Registry
 */
class CompetitorRegistryTest extends TestCase {

	/**
	 * The real Worcester Park response, parsed.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function real_rows(): array {
		$decoded = json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/fixtures/maprun-worcester-park.json' ),
			true
		);

		return ( new Parser() )->parse( Parser::unwrap( $decoded ), '60' );
	}

	/**
	 * @param string   $first   First name.
	 * @param string   $surname Surname.
	 * @param int|null $year    Year of birth.
	 * @return array<string,mixed>
	 */
	private function row( string $first, string $surname, ?int $year = null, string $gender = 'M' ): array {
		return array(
			'first_name'    => $first,
			'surname'       => $surname,
			'display_name'  => trim( $first . ' ' . $surname ),
			'club'          => 'MVOC',
			'gender'        => $gender,
			'year_of_birth' => $year,
		);
	}

	public function test_a_known_alias_resolves_silently(): void {
		$registry = new Competitor_Registry();

		$result = $registry->resolve(
			array( $this->row( 'Robert', 'Yardley' ) ),
			array( array( 'id' => 7, 'first_name' => 'Rob', 'surname' => 'Yardley' ) ),
			array( 'robert yardley' => 7 )
		);

		$this->assertSame( 7, $result['rows'][0]['competitor_id'] );
		$this->assertSame( array(), $result['unmatched'], 'a confirmed alias should not be re-asked' );
	}

	public function test_an_unknown_name_is_queued_with_suggestions(): void {
		$registry = new Competitor_Registry();

		$result = $registry->resolve(
			array( $this->row( 'Robert', 'Yardley', 1967 ) ),
			array( array( 'id' => 7, 'first_name' => 'Rob', 'surname' => 'Yardley', 'club' => 'MVOC' ) ),
			array()
		);

		$this->assertNull( $result['rows'][0]['competitor_id'] );
		$this->assertCount( 1, $result['unmatched'] );
		$this->assertSame( 7, $result['unmatched'][0]['suggestions'][0]['competitor']['id'] );
	}

	public function test_a_strong_suggestion_is_never_applied_automatically(): void {
		// A wrong merge hands one runner another's league points. Even a
		// perfect-looking match waits for a click.
		$registry = new Competitor_Registry();

		$result = $registry->resolve(
			array( $this->row( 'David', 'Wolstenholme', 1969 ) ),
			array(
				array(
					'id'            => 3,
					'first_name'    => 'David',
					'surname'       => 'Wolstenholme',
					'club'          => 'MVOC',
					'year_of_birth' => 1969,
				),
			),
			array()
		);

		$this->assertNull( $result['rows'][0]['competitor_id'] );
		$this->assertTrue( $result['unmatched'][0]['has_strong_suggestion'] );
	}

	public function test_a_repeated_name_is_one_decision_not_several(): void {
		// Brian Ashby-Prentice uploads three times in the real response.
		$registry = new Competitor_Registry();

		$result = $registry->resolve(
			array(
				$this->row( 'Gavin', 'Ashby-Prentice', 1959 ),
				$this->row( 'Gavin', 'Ashby-Prentice', 1959 ),
				$this->row( 'Gavin', 'Ashby-Prentice', 1959 ),
			),
			array(),
			array()
		);

		$this->assertCount( 3, $result['rows'] );
		$this->assertCount( 1, $result['unmatched'] );
	}

	public function test_queue_entries_keep_the_richest_details(): void {
		// One upload may carry a club the other left blank.
		$registry = new Competitor_Registry();

		$sparse         = $this->row( 'Petra', 'Loveridge', null, 'F' );
		$sparse['club'] = '';

		$result = $registry->resolve(
			array( $sparse, $this->row( 'Petra', 'Loveridge', 1992, 'F' ) ),
			array(),
			array()
		);

		$this->assertSame( 'MVOC', $result['unmatched'][0]['club'] );
	}

	public function test_a_proposed_competitor_derives_both_category_flags(): void {
		$registry = new Competitor_Registry( null, new Scoring_Config( array( 'category_year' => 2026 ) ) );

		$vet = $registry->propose_competitor( $this->row( 'Christine', 'Wetherby', 1953, 'F' ) );

		$this->assertTrue( $vet['is_female'] );
		$this->assertTrue( $vet['is_over55'] );

		$young = $registry->propose_competitor( $this->row( 'Tim', 'Orpington', 1984, 'M' ) );

		$this->assertFalse( $young['is_female'] );
		$this->assertFalse( $young['is_over55'] );
	}

	public function test_category_drift_reports_only_what_changed(): void {
		// MapRun values are self-declared and occasionally corrected. The
		// registry reports the disagreement rather than overwriting a
		// deliberate fix.
		$registry = new Competitor_Registry( null, new Scoring_Config( array( 'category_year' => 2026 ) ) );

		$stored = array( 'is_female' => false, 'is_over55' => false );
		$drift  = $registry->category_drift( $stored, $this->row( 'Christine', 'Wetherby', 1953, 'F' ) );

		$this->assertSame( array( 'is_female' => true, 'is_over55' => true ), $drift );
	}

	public function test_no_drift_when_stored_flags_already_agree(): void {
		$registry = new Competitor_Registry( null, new Scoring_Config( array( 'category_year' => 2026 ) ) );

		$stored = array( 'is_female' => true, 'is_over55' => true );

		$this->assertSame(
			array(),
			$registry->category_drift( $stored, $this->row( 'Christine', 'Wetherby', 1953, 'F' ) )
		);
	}

	public function test_missing_maprun_values_never_cause_drift(): void {
		// An absent gender or year must not silently clear a stored flag.
		$registry = new Competitor_Registry();

		$row = $this->row( 'Someone', 'Unknown' );
		$row['gender'] = '';

		$this->assertSame(
			array(),
			$registry->category_drift( array( 'is_female' => true, 'is_over55' => true ), $row )
		);
	}

	public function test_the_real_event_queues_every_distinct_runner_once(): void {
		// Sixteen rows, but Brian Ashby-Prentice appears three times and both
		// Warren Wolstenholme and Nigel Gladwell twice: 16 - 2 - 1 - 1 = 12 people.
		$registry = new Competitor_Registry();
		$result   = $registry->resolve( $this->real_rows(), array(), array() );

		$this->assertCount( 16, $result['rows'] );
		$this->assertCount( 12, $result['unmatched'] );
	}

	public function test_revision_suffixes_do_not_split_an_identity(): void {
		// A surname with a "(Rev30)" suffix and the same surname without one
		// must queue as one person, not two - the parser strips the suffix
		// before the registry ever sees it.
		$registry = new Competitor_Registry();
		$result   = $registry->resolve( $this->real_rows(), array(), array() );

		$names = array_column( $result['unmatched'], 'display_name' );

		$this->assertContains( 'Warren Wolstenholme', $names );
		$this->assertSame( 1, count( array_keys( $names, 'Warren Wolstenholme', true ) ) );
	}

	public function test_a_second_event_asks_nothing_once_aliases_exist(): void {
		// The point of the registry: confirming a name costs one click once,
		// then the whole field resolves silently at every later event.
		$registry = new Competitor_Registry();
		$rows     = $this->real_rows();

		$first = $registry->resolve( $rows, array(), array() );

		$competitors = array();
		$aliases     = array();
		foreach ( $first['unmatched'] as $index => $entry ) {
			$id                        = $index + 1;
			$competitors[]             = array( 'id' => $id ) + $entry['proposed'];
			$aliases[ $entry['alias_key'] ] = $id;
		}

		$second = $registry->resolve( $rows, $competitors, $aliases );

		$this->assertSame( array(), $second['unmatched'] );
		foreach ( $second['rows'] as $row ) {
			$this->assertNotNull( $row['competitor_id'], $row['display_name'] . ' should resolve' );
		}
	}

	public function test_an_alias_pointing_at_a_deleted_competitor_re_queues(): void {
		// A dangling alias must not resolve to nothing silently.
		$registry = new Competitor_Registry();

		$result = $registry->resolve(
			array( $this->row( 'Jane', 'Pargeter' ) ),
			array(),
			array( 'callum pargeter' => 99 )
		);

		$this->assertNull( $result['rows'][0]['competitor_id'] );
		$this->assertCount( 1, $result['unmatched'] );
	}
}
