<?php
/**
 * Tests against the real MapRun response.
 *
 * Everything here is driven by the actual payload for MVOC's "Worcester Park
 * Apr26 PXAS ScoreQ60" — the last event of the 2025/26 series. These are the
 * cases a hand-written fixture would not have thought to include, which is
 * precisely why they are worth testing.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Duplicate_Detector;
use MVOC\StreetO\Domain\Scoring_Config;
use MVOC\StreetO\Domain\Scoring_Engine;
use MVOC\StreetO\MapRun\Parser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\MapRun\Parser
 * @covers \MVOC\StreetO\Domain\Duplicate_Detector
 */
class WorcesterParkTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function response(): array {
		return json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/fixtures/maprun-worcester-park.json' ),
			true
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function parsed(): array {
		return ( new Parser() )->parse( Parser::unwrap( $this->response() ), '60' );
	}

	/**
	 * Look a parsed row up by the name it should end with.
	 *
	 * @param string $name Display name.
	 * @return array<string,mixed>
	 */
	private function row( string $name ): array {
		foreach ( $this->parsed() as $row ) {
			if ( $name === $row['display_name'] ) {
				return $row;
			}
		}

		$this->fail( 'No parsed row for ' . $name );
	}

	public function test_the_paste_url_is_ready_to_open(): void {
		// On a host that blocks port 8886 this is the co-ordinator's route in,
		// so the URL has to be correct without them assembling it. Spaces in
		// the event name are the part that would otherwise go wrong.
		$url = \MVOC\StreetO\MapRun\Client::url_for( 'Burpham Sep26 PXAS ScoreQ60' );

		$this->assertStringContainsString( 'p.fne.com.au:8886', $url );
		$this->assertStringContainsString( 'Burpham%20Sep26%20PXAS%20ScoreQ60', $url );
		$this->assertStringNotContainsString( ' ', $url );
	}

	public function test_the_response_parses(): void {
		$this->assertCount( 16, $this->parsed() );
	}

	public function test_the_warning_is_surfaced(): void {
		// This response really did come back with a warning, and it is the
		// direct cause of the duplicate rows. Silently ignoring it would hide
		// the explanation for a genuine data problem.
		$warning = Parser::warning( $this->response() );

		$this->assertNotNull( $warning );
		$this->assertStringContainsString( 'Multiple events found', $warning );
	}

	public function test_a_clean_response_has_no_warning(): void {
		$this->assertNull( Parser::warning( array( 'errorFlag' => false, 'results' => array() ) ) );
		$this->assertNull( Parser::warning( array( 'warningFlag' => false ) ) );
	}

	public function test_a_warning_flag_without_a_message_still_reports(): void {
		$this->assertNotNull( Parser::warning( array( 'warningFlag' => true ) ) );
	}

	public function test_penalties_are_derived_from_gross_minus_net(): void {
		// Leonard Quilter finished 47 seconds over the hour: 780 gross, 750 net.
		$morgan = $this->row( 'Leonard Quilter' );

		$this->assertSame( 780, $morgan['score'] );
		$this->assertSame( 750, $morgan['net_score'] );
		$this->assertSame( 30, $morgan['penalty'] );
	}

	public function test_a_larger_overrun_carries_a_larger_penalty(): void {
		// Lydia Underwood was 3:36 over: 600 gross, 480 net.
		$cadman = $this->row( 'Lydia Underwood' );

		$this->assertSame( 120, $cadman['penalty'] );
	}

	public function test_the_scoring_engine_reproduces_maprun_net_scores(): void {
		// A useful cross-check rather than a coincidence: the engine computes
		// Score - Penalty, and MapRun computed NetScore independently. On the
		// 60-minute course, with no scaling, the two must agree exactly.
		$scored = ( new Scoring_Engine() )->score_event( $this->parsed() );

		$checked = 0;
		foreach ( $scored as $row ) {
			if ( null === $row['total'] ) {
				continue;
			}

			$this->assertSame(
				$row['net_score'],
				$row['total'],
				$row['display_name'] . ': engine total should equal MapRun NetScore'
			);
			++$checked;
		}

		$this->assertGreaterThan( 10, $checked );
	}

	public function test_failed_uploads_are_flagged(): void {
		$failed = array_values(
			array_filter( $this->parsed(), static fn( array $r ): bool => $r['is_failed'] )
		);

		// Nigel Gladwell once, Brian Ashby-Prentice twice.
		$this->assertCount( 3, $failed );
		foreach ( $failed as $row ) {
			$this->assertSame( '--', $row['classifier'] );
			$this->assertSame( 0, $row['time_secs'] );
		}
	}

	public function test_a_failed_upload_row_is_not_ranked(): void {
		$scored = ( new Scoring_Engine() )->score_event(
			array_map(
				// The review screen marks failed uploads excluded before scoring.
				static function ( array $row ): array {
					$row['is_excluded'] = $row['is_failed'];
					return $row;
				},
				$this->parsed()
			)
		);

		foreach ( $scored as $row ) {
			if ( $row['is_failed'] ) {
				$this->assertNull( $row['position'], $row['display_name'] . ' should not rank' );
			}
		}
	}

	public function test_revision_suffixes_are_stripped_from_surnames(): void {
		// "Float (Rev30)" is Warren Wolstenholme, not a different person.
		$float = $this->row( 'Warren Wolstenholme' );

		$this->assertSame( 'Wolstenholme', $float['surname'] );
	}

	public function test_the_revision_number_is_kept(): void {
		$revisions = array();
		foreach ( $this->parsed() as $row ) {
			if ( null !== $row['course_revision'] ) {
				$revisions[ $row['surname_raw'] ] = $row['course_revision'];
			}
		}

		$this->assertSame( 30, $revisions['Gladwell (Rev30)'] );
		$this->assertSame( 30, $revisions['Ormerod (Rev30)'] );
		$this->assertSame( 20, $revisions['Marchmont (Rev20)'] );
	}

	/**
	 * @dataProvider revision_provider
	 */
	public function test_split_revision( string $input, string $surname, ?int $revision ): void {
		$result = Parser::split_revision( $input );

		$this->assertSame( $surname, $result['surname'] );
		$this->assertSame( $revision, $result['revision'] );
	}

	public function revision_provider(): array {
		return array(
			'plain'            => array( 'Wolstenholme', 'Wolstenholme', null ),
			'rev 30'           => array( 'Wolstenholme (Rev30)', 'Wolstenholme', 30 ),
			'rev 20'           => array( 'Marchmont (Rev20)', 'Marchmont', 20 ),
			'spaced'           => array( 'Ormerod (Rev 30)', 'Ormerod', 30 ),
			'lowercase'        => array( 'Smith (rev5)', 'Smith', 5 ),
			'double barrelled' => array( 'Ashby-Prentice', 'Ashby-Prentice', null ),
			'genuine bracket'  => array( "O'Halloran (Jr)", "O'Halloran (Jr)", null ),
		);
	}

	public function test_extra_punches_appended_out_of_order_are_resorted(): void {
		// Nigel Gladwell's row really does end "35 (Extra)", "32 (Extra)" at
		// 725s and 1162s, after preceding punches at 3085s and 3291s.
		$punches = $this->row( 'Nigel Gladwell' )['punches'];
		$times   = array_column( $punches, 'time_secs' );

		$sorted = $times;
		sort( $sorted );
		$this->assertSame( $sorted, $times, 'punches should be in time order' );
	}

	public function test_the_extra_marker_is_split_off_the_control_id(): void {
		$punches = $this->row( 'Nigel Gladwell' )['punches'];
		$by_time = array_column( $punches, 'is_extra', 'time_secs' );

		// 725 is the "35 (Extra)" punch; 244 is a normal one.
		$this->assertTrue( $by_time[725] );
		$this->assertFalse( $by_time[244] );

		foreach ( $punches as $punch ) {
			$this->assertStringNotContainsString( 'Extra', $punch['control'] );
		}
	}

	public function test_year_of_birth_is_read(): void {
		$this->assertSame( 1953, $this->row( 'Elspeth Prendergast' )['year_of_birth'] );
		$this->assertSame( 1984, $this->row( 'Rowan Orpington' )['year_of_birth'] );
	}

	public function test_over_55_derives_from_year_of_birth(): void {
		// The 2026/27 league takes its start year, 2026, per British
		// Orienteering's 31 December rule.
		$config = new Scoring_Config( array( 'category_year' => 2026 ) );

		// Elspeth Prendergast, born 1953, turns 73 in 2026.
		$this->assertTrue( $config->is_over55( $this->row( 'Elspeth Prendergast' )['year_of_birth'] ) );

		// Rowan Orpington, born 1984, turns 42.
		$this->assertFalse( $config->is_over55( $this->row( 'Rowan Orpington' )['year_of_birth'] ) );
	}

	public function test_over_55_is_inclusive_at_exactly_55(): void {
		$config = new Scoring_Config( array( 'category_year' => 2026 ) );

		$this->assertTrue( $config->is_over55( 1971 ), '55 in 2026 qualifies' );
		$this->assertFalse( $config->is_over55( 1972 ), '54 in 2026 does not' );
	}

	public function test_an_unknown_year_of_birth_is_not_over_55(): void {
		// Better to leave someone out of a category than to invent an age.
		$config = new Scoring_Config();

		$this->assertFalse( $config->is_over55( null ) );
		$this->assertFalse( $config->is_over55( 0 ) );
	}

	public function test_duplicate_detection_finds_only_the_genuine_ambiguity(): void {
		// Six rows in this response look like duplicates. Five are failed
		// uploads, which resolve themselves. Only Warren Wolstenholme - the same run
		// scored 760 and 730 against two course revisions - needs a decision.
		$clusters = ( new Duplicate_Detector() )->find( $this->parsed() );

		$this->assertCount( 1, $clusters );
		$this->assertCount( 2, $clusters[0] );

		$scores = array_column( $clusters[0], 'score' );
		sort( $scores );
		$this->assertSame( array( 730, 760 ), $scores );
	}

	public function test_a_duplicate_cluster_describes_itself_for_review(): void {
		$detector = new Duplicate_Detector();
		$clusters = $detector->find( $this->parsed() );
		$described = $detector->describe( $clusters[0] );

		$this->assertSame( 'Warren Wolstenholme', $described['name'] );
		$this->assertSame( '53:23', $described['time_display'] );

		// Later revision shown first, but nothing pre-selected.
		$this->assertSame( 30, $described['options'][0]['revision'] );
		$this->assertNull( $described['options'][1]['revision'] );
	}

	public function test_runners_who_started_and_finished_together_are_not_merged(): void {
		// Pairs run together at every event and upload separately. Identical
		// times must not collapse two people into one result.
		$clusters = ( new Duplicate_Detector() )->find(
			array(
				array(
					'first_name' => 'Karen', 'surname' => 'Underwood', 'display_name' => 'Lydia Underwood',
					'start_local' => '18:28:32', 'finish_local' => '19:32:08', 'time_secs' => 3816,
					'is_failed' => false, 'score' => 600,
				),
				array(
					'first_name' => 'Adrian', 'surname' => 'Croft', 'display_name' => 'Adrian Croft',
					'start_local' => '18:28:32', 'finish_local' => '19:32:08', 'time_secs' => 3816,
					'is_failed' => false, 'score' => 600,
				),
			)
		);

		$this->assertSame( array(), $clusters );
	}

	public function test_rows_without_a_usable_time_are_not_clustered(): void {
		// Two failed uploads for the same person share a zero time; treating
		// that as a match would invent a duplicate decision out of nothing.
		$clusters = ( new Duplicate_Detector() )->find(
			array(
				array(
					'first_name' => 'A', 'surname' => 'B', 'display_name' => 'A B',
					'start_local' => '00:00:00', 'finish_local' => '00:00:00', 'time_secs' => 0,
					'is_failed' => false,
				),
				array(
					'first_name' => 'A', 'surname' => 'B', 'display_name' => 'A B',
					'start_local' => '00:00:00', 'finish_local' => '00:00:00', 'time_secs' => 0,
					'is_failed' => false,
				),
			)
		);

		$this->assertSame( array(), $clusters );
	}
}
