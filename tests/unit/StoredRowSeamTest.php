<?php
/**
 * Tests that a row survives the round trip into the database and back.
 *
 * The duplicate detector was correct and comprehensively tested, and found
 * nothing on any real event for the whole of the plugin's life. Every test fed
 * it parser output; the review screen feeds it stored rows, and the fields it
 * identifies a run by — start, finish, course revision — were dropped at
 * import. The algorithm was proven and the seam was not.
 *
 * So these tests go the long way round on purpose: parse, through the columns
 * an import actually writes, back through the resolver the screens actually
 * call, and only then into the domain class. Anything that stops surviving
 * that trip fails here rather than going quietly missing on the live site.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Duplicate_Detector;
use MVOC\StreetO\Domain\Import_Reconciler;
use MVOC\StreetO\Domain\Scoring_Config;
use MVOC\StreetO\MapRun\Parser;
use MVOC\StreetO\Repo\Results_Repo;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Repo\Results_Repo
 * @covers \MVOC\StreetO\Domain\Import_Reconciler
 * @covers \MVOC\StreetO\Domain\Duplicate_Detector
 */
class StoredRowSeamTest extends TestCase {

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function parsed(): array {
		$response = json_decode( $this->payload(), true );

		return ( new Parser() )->parse( Parser::unwrap( $response ), '60' );
	}

	private function payload(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/maprun-worcester-park.json' );
	}

	/**
	 * Every parsed row put through the columns an import writes, then resolved
	 * exactly as a screen resolves them.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function round_tripped(): array {
		$stored = array();

		foreach ( $this->parsed() as $index => $row ) {
			$stored[] = array_merge(
				Import_Reconciler::raw_columns( $row ),
				array(
					'id'                    => $index + 1,
					'competitor_id'         => null,
					'resolved_score'        => null,
					'resolved_penalty'      => null,
					'resolved_time_secs'    => null,
					'resolved_course_label' => '',
					'is_excluded'           => ! empty( $row['is_failed'] ),
					'is_manual'             => false,
					'is_withdrawn'          => false,
				)
			);
		}

		return Results_Repo::effective_rows( $stored, new Scoring_Config() );
	}

	public function test_a_stored_row_still_identifies_its_run(): void {
		// The four fields Duplicate_Detector's signature is built from. Losing
		// any one of them silently disables duplicate detection entirely.
		$row = $this->round_tripped()[0];

		$this->assertSame( 'Rowan', $row['first_name'] );
		$this->assertSame( 'Orpington', $row['surname'] );
		$this->assertSame( '18:53:36', $row['start_local'] );
		$this->assertSame( '19:53:30', $row['finish_local'] );
		$this->assertSame( 3593, $row['time_secs'] );
	}

	public function test_the_course_revision_survives_the_round_trip(): void {
		// The suffix is stripped off the surname, as it must be for matching,
		// but the number it carried is kept: it is what tells the co-ordinator
		// which of two scorings of one run is which.
		$rows = array_column( $this->round_tripped(), null, 'maprun_id' );

		$this->assertSame( 'Nigel Gladwell', $rows['591276']['display_name'] );
		$this->assertSame( 30, $rows['591276']['course_revision'] );
		$this->assertNull( $rows['591179']['course_revision'] );
	}

	public function test_duplicates_are_found_on_stored_rows_not_just_parsed_ones(): void {
		// The bug this file exists for. The detector found the Worcester Park
		// duplicate in parser output and nothing at all once the same rows had
		// been through the database.
		$clusters = ( new Duplicate_Detector() )->find( $this->round_tripped() );

		$this->assertCount( 1, $clusters, 'the genuine duplicate should survive storage' );
		$this->assertCount( 2, $clusters[0] );
		$this->assertSame( 'Warren Wolstenholme', $clusters[0][0]['display_name'] );
	}

	public function test_stored_and_parsed_rows_find_the_same_duplicates(): void {
		$detector = new Duplicate_Detector();

		$this->assertSame(
			count( $detector->find( $this->parsed() ) ),
			count( $detector->find( $this->round_tripped() ) ),
			'storage must not change what counts as a duplicate'
		);
	}

	public function test_a_cluster_of_stored_rows_describes_itself_for_review(): void {
		$detector = new Duplicate_Detector();
		$cluster  = $detector->find( $this->round_tripped() )[0];
		$described = $detector->describe( $cluster );

		$this->assertSame( 'Warren Wolstenholme', $described['name'] );
		$this->assertSame( '53:23', $described['time_display'] );

		// Later revision first, and each option carries the id the radio button
		// submits — without it the choice cannot be recorded.
		$this->assertSame( 30, $described['options'][0]['revision'] );
		$this->assertSame( 760, $described['options'][0]['score'] );
		$this->assertNotNull( $described['options'][0]['result_id'] );
		$this->assertNull( $described['options'][1]['revision'] );
		$this->assertSame( 730, $described['options'][1]['score'] );
	}

	public function test_failed_uploads_are_still_recognised_after_storage(): void {
		// Nigel Gladwell has a real run and a failed upload. Were the failure
		// not recognised, it would be offered as a duplicate to choose between.
		$rows = array_column( $this->round_tripped(), null, 'maprun_id' );

		$this->assertTrue( $rows['591110']['is_failed'] );
		$this->assertFalse( $rows['591276']['is_failed'] );
	}

	public function test_a_stored_payload_yields_the_identities_a_backfill_needs(): void {
		// How an already-imported event is repaired without re-fetching it.
		$identities = Parser::identities( $this->payload() );

		$this->assertSame( '18:35:41', $identities['591278']['start_local'] );
		$this->assertSame( '19:29:04', $identities['591278']['finish_local'] );
		$this->assertSame( 30, $identities['591278']['course_revision'] );
		$this->assertNull( $identities['591275']['course_revision'] );
	}

	public function test_a_backfill_recovers_exactly_what_an_import_would_have_written(): void {
		// The backfill is only correct if it produces the same values a fresh
		// import does — otherwise a repaired season and a re-fetched one would
		// disagree about which rows are duplicates.
		$identities = Parser::identities( $this->payload() );

		foreach ( $this->parsed() as $row ) {
			$columns = Import_Reconciler::raw_columns( $row );
			$id      = $columns['maprun_id'];

			$this->assertSame( $columns['raw_start_local'], $identities[ $id ]['start_local'] );
			$this->assertSame( $columns['raw_finish_local'], $identities[ $id ]['finish_local'] );
			$this->assertSame( $columns['raw_course_revision'], $identities[ $id ]['course_revision'] );
		}
	}

	public function test_an_unreadable_payload_yields_nothing_rather_than_throwing(): void {
		// A bad snapshot must leave the columns empty, not stop an upgrade.
		$this->assertSame( array(), Parser::identities( 'not json' ) );
		$this->assertSame( array(), Parser::identities( '{"errorFlag":true,"statusMessage":"gone"}' ) );
	}

	public function test_a_hand_added_row_has_no_run_identity_and_is_not_clustered(): void {
		// Manual rows never came from MapRun, so they carry no start or finish
		// and must not be offered as duplicates of each other.
		$manual = array(
			'id'                    => 1,
			'maprun_id'             => '',
			'competitor_id'         => null,
			'raw_first_name'        => 'Dave',
			'raw_surname'           => 'Smith',
			'raw_club'              => '',
			'classifier'            => 'MANUAL',
			'course_label'          => '60',
			'resolved_course_label' => '60',
			'raw_score'             => 640,
			'resolved_score'        => 640,
			'raw_penalty'           => 0,
			'resolved_penalty'      => 0,
			'raw_time_secs'         => null,
			'resolved_time_secs'    => null,
			'raw_start_local'       => '',
			'raw_finish_local'      => '',
			'raw_course_revision'   => null,
			'raw_is_over55'         => null,
			'is_excluded'           => false,
			'is_manual'             => true,
			'is_withdrawn'          => false,
		);

		$rows = Results_Repo::effective_rows( array( $manual, array_merge( $manual, array( 'id' => 2 ) ) ), new Scoring_Config() );

		$this->assertSame( '', $rows[0]['start_local'] );
		$this->assertSame( array(), ( new Duplicate_Detector() )->find( $rows ) );
	}
}
