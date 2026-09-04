<?php
/**
 * Tests for re-import reconciliation.
 *
 * These prove the design's central promise without needing a database: that a
 * correction made after one import is still there after the next.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Import_Reconciler;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\Import_Reconciler
 */
class ImportReconcilerTest extends TestCase {

	/**
	 * A stored result row.
	 *
	 * @param int    $id        Result id.
	 * @param string $maprun_id MapRun result id.
	 * @param array  $extra     Extra columns.
	 * @return array<string,mixed>
	 */
	private function stored( int $id, string $maprun_id, array $extra = array() ): array {
		// array_replace, not "+": the union operator keeps the left operand's
		// keys, which would silently discard every override passed in $extra.
		return array_replace(
			array(
				'id'           => $id,
				'maprun_id'    => $maprun_id,
				'is_manual'    => false,
				'is_withdrawn' => false,
			),
			$extra
		);
	}

	/**
	 * An incoming parsed row.
	 *
	 * @param string $maprun_id MapRun result id.
	 * @param int    $score     Gross score.
	 * @return array<string,mixed>
	 */
	private function incoming( string $maprun_id, int $score = 500 ): array {
		return array(
			'maprun_id'  => $maprun_id,
			'first_name' => 'Test',
			'surname'    => 'Runner',
			'score'      => $score,
			'penalty'    => 0,
		);
	}

	/**
	 * Index actions by the id they act on, for readable assertions.
	 *
	 * @param array<int,array<string,mixed>> $actions Actions.
	 * @return array<string,string>
	 */
	private function by_target( array $actions ): array {
		$map = array();

		foreach ( $actions as $action ) {
			$key         = $action['result_id'] ?? ( 'new:' . $action['row']['maprun_id'] );
			$map[ $key ] = $action['action'];
		}

		// Sorted so assertions describe what happened to each row rather than
		// pinning the order actions happen to come back in.
		ksort( $map );

		return $map;
	}

	public function test_a_first_import_inserts_everything(): void {
		$actions = ( new Import_Reconciler() )->reconcile(
			array(),
			array( $this->incoming( '1' ), $this->incoming( '2' ) )
		);

		$this->assertCount( 2, $actions );
		foreach ( $actions as $action ) {
			$this->assertSame( Import_Reconciler::INSERT, $action['action'] );
			$this->assertNull( $action['result_id'] );
		}
	}

	public function test_a_known_row_is_updated_in_place_keeping_its_id(): void {
		// Keeping the id is what keeps the overrides attached to it.
		$actions = ( new Import_Reconciler() )->reconcile(
			array( $this->stored( 42, '591275' ) ),
			array( $this->incoming( '591275' ) )
		);

		$this->assertCount( 1, $actions );
		$this->assertSame( Import_Reconciler::UPDATE, $actions[0]['action'] );
		$this->assertSame( 42, $actions[0]['result_id'] );
	}

	public function test_a_late_upload_is_added_without_disturbing_the_rest(): void {
		$actions = ( new Import_Reconciler() )->reconcile(
			array( $this->stored( 1, 'a' ), $this->stored( 2, 'b' ) ),
			array( $this->incoming( 'a' ), $this->incoming( 'b' ), $this->incoming( 'c' ) )
		);

		$this->assertSame(
			array(
				1       => Import_Reconciler::UPDATE,
				2       => Import_Reconciler::UPDATE,
				'new:c' => Import_Reconciler::INSERT,
			),
			$this->by_target( $actions )
		);
	}

	public function test_a_vanished_row_is_withdrawn_not_deleted(): void {
		// MapRun dropping a result is likelier a glitch than a fact, and a
		// delete would take the co-ordinator's correction with it.
		$actions = ( new Import_Reconciler() )->reconcile(
			array( $this->stored( 1, 'a' ), $this->stored( 2, 'b' ) ),
			array( $this->incoming( 'a' ) )
		);

		$this->assertSame(
			array( 1 => Import_Reconciler::UPDATE, 2 => Import_Reconciler::WITHDRAW ),
			$this->by_target( $actions )
		);
	}

	public function test_a_returning_row_is_restored_with_its_id_intact(): void {
		$actions = ( new Import_Reconciler() )->reconcile(
			array( $this->stored( 7, 'a', array( 'is_withdrawn' => true ) ) ),
			array( $this->incoming( 'a' ) )
		);

		$this->assertSame( Import_Reconciler::RESTORE, $actions[0]['action'] );
		$this->assertSame( 7, $actions[0]['result_id'] );
	}

	public function test_an_already_withdrawn_row_is_not_withdrawn_again(): void {
		$actions = ( new Import_Reconciler() )->reconcile(
			array( $this->stored( 7, 'a', array( 'is_withdrawn' => true ) ) ),
			array()
		);

		$this->assertSame( array(), $actions );
	}

	public function test_manual_rows_are_never_touched(): void {
		// A hand-added runner exists precisely because MapRun does not know
		// about them, so an import must not reconsider the row.
		$actions = ( new Import_Reconciler() )->reconcile(
			array(
				$this->stored( 1, '', array( 'is_manual' => true ) ),
				$this->stored( 2, 'b' ),
			),
			array( $this->incoming( 'b' ) )
		);

		$this->assertSame( array( 2 => Import_Reconciler::UPDATE ), $this->by_target( $actions ) );
	}

	public function test_a_manual_row_that_somehow_has_a_maprun_id_is_still_left_alone(): void {
		$actions = ( new Import_Reconciler() )->reconcile(
			array( $this->stored( 1, 'a', array( 'is_manual' => true ) ) ),
			array()
		);

		$this->assertSame( array(), $actions );
	}

	public function test_incoming_rows_without_an_id_are_skipped(): void {
		// Such a row cannot be tracked between fetches, so inserting it would
		// duplicate the runner on every import.
		$actions = ( new Import_Reconciler() )->reconcile(
			array(),
			array( $this->incoming( '' ), $this->incoming( 'a' ) )
		);

		$this->assertCount( 1, $actions );
		$this->assertSame( 'a', $actions[0]['row']['maprun_id'] );
	}

	public function test_the_full_cycle_leaves_corrected_rows_addressable(): void {
		// The scenario the design exists for: import, correct, re-import with a
		// late upload and one result missing. Every previously known row keeps
		// its id, so every correction keyed to that id survives.
		$stored = array(
			$this->stored( 10, '591179' ),
			$this->stored( 11, '591188' ),
			$this->stored( 12, '591556' ),
			$this->stored( 13, '', array( 'is_manual' => true ) ),
		);

		$actions = ( new Import_Reconciler() )->reconcile(
			$stored,
			array(
				$this->incoming( '591179' ),
				$this->incoming( '591188' ),
				$this->incoming( '591999' ),
			)
		);

		$this->assertSame(
			array(
				10           => Import_Reconciler::UPDATE,
				11           => Import_Reconciler::UPDATE,
				12           => Import_Reconciler::WITHDRAW,
				'new:591999' => Import_Reconciler::INSERT,
			),
			$this->by_target( $actions )
		);

		$this->assertSame(
			array(
				Import_Reconciler::INSERT   => 1,
				Import_Reconciler::UPDATE   => 2,
				Import_Reconciler::WITHDRAW => 1,
				Import_Reconciler::RESTORE  => 0,
			),
			Import_Reconciler::summarise( $actions )
		);
	}

	public function test_a_replaced_maprun_event_loses_nothing(): void {
		// This happens for real: an event is found to be faulty and a corrected
		// version is uploaded to MapRun before the night, which is why one of
		// last season's is named "Dork v2". If it were ever replaced *after*
		// results had come in, pointing the plugin at the new event would meet
		// an entirely different set of MapRun ids.
		//
		// Nothing may be lost in that case. The old rows are withdrawn rather
		// than deleted, so they keep their ids and therefore their corrections,
		// stay visible on the review screen, and can be brought back. The new
		// rows are added alongside.
		$actions = ( new Import_Reconciler() )->reconcile(
			array(
				$this->stored( 1, 'v1-a' ),
				$this->stored( 2, 'v1-b' ),
				$this->stored( 3, '', array( 'is_manual' => true ) ),
			),
			array( $this->incoming( 'v2-a' ), $this->incoming( 'v2-b' ) )
		);

		$this->assertSame(
			array(
				1           => Import_Reconciler::WITHDRAW,
				2           => Import_Reconciler::WITHDRAW,
				'new:v2-a'  => Import_Reconciler::INSERT,
				'new:v2-b'  => Import_Reconciler::INSERT,
			),
			$this->by_target( $actions )
		);

		// Nothing is deleted, so no correction is destroyed by the swap.
		foreach ( $actions as $action ) {
			$this->assertNotSame( 'delete', $action['action'] );
		}
	}

	public function test_a_hand_added_runner_survives_a_replaced_event(): void {
		// Someone entered by hand because their phone failed must not vanish
		// because MapRun's event was swapped underneath them.
		$actions = ( new Import_Reconciler() )->reconcile(
			array( $this->stored( 9, '', array( 'is_manual' => true ) ) ),
			array( $this->incoming( 'v2-a' ) )
		);

		$this->assertSame( array( 'new:v2-a' => Import_Reconciler::INSERT ), $this->by_target( $actions ) );
	}

	public function test_raw_columns_never_include_a_resolved_value(): void {
		// An import refreshes only what MapRun is authoritative about. Any
		// resolved_* column here would overwrite a correction on every fetch.
		$columns = Import_Reconciler::raw_columns( $this->incoming( 'a', 640 ) );

		foreach ( array_keys( $columns ) as $column ) {
			$this->assertStringStartsNotWith( 'resolved_', $column );
		}

		$this->assertArrayNotHasKey( 'is_excluded', $columns );
		$this->assertArrayNotHasKey( 'competitor_id', $columns );
		$this->assertSame( 640, $columns['raw_score'] );
	}

	public function test_raw_columns_map_the_parsed_shape(): void {
		$columns = Import_Reconciler::raw_columns(
			array(
				'maprun_id'    => '591556',
				'first_name'   => 'Philip',
				'surname'      => 'Redmayne',
				'club'         => 'MVOC',
				'gender'       => 'M',
				'classifier'   => 'OK',
				'course_label' => '60',
				'score'        => 780,
				'penalty'      => 30,
				'time_secs'    => 3647,
			)
		);

		$this->assertSame( 'Redmayne', $columns['raw_surname'] );
		$this->assertSame( 780, $columns['raw_score'] );
		$this->assertSame( 30, $columns['raw_penalty'] );
		$this->assertSame( 3647, $columns['raw_time_secs'] );
	}

	public function test_raw_columns_keep_the_category_not_the_birth_year(): void {
		// A competitor may be created from a row weeks after the import that
		// produced it, so the category has to survive - but the date of birth
		// that produced it does not, and must not be written anywhere.
		$columns = Import_Reconciler::raw_columns(
			array( 'maprun_id' => 'a', 'is_over55' => true, 'year_of_birth' => 1953 )
		);

		$this->assertSame( 1, $columns['raw_is_over55'] );
		$this->assertArrayNotHasKey( 'raw_year_of_birth', $columns );

		foreach ( $columns as $column => $value ) {
			$this->assertStringNotContainsString( 'birth', $column );
			$this->assertNotSame( 1953, $value, 'a birth year must not reach the database' );
		}
	}

	public function test_a_re_import_refreshes_the_category_flag(): void {
		// The recovery path depends on this: raw_is_over55 is a raw column, so
		// a re-import repopulates it on rows that predate the plugin keeping
		// it. If it were treated as resolved data it would never come back.
		$actions = ( new Import_Reconciler() )->reconcile(
			array( $this->stored( 5, 'a' ) ),
			array( array( 'maprun_id' => 'a', 'is_over55' => true ) )
		);

		$this->assertSame( Import_Reconciler::UPDATE, $actions[0]['action'] );
		$this->assertSame(
			1,
			Import_Reconciler::raw_columns( $actions[0]['row'] )['raw_is_over55']
		);
	}

	public function test_an_unknown_category_stays_null(): void {
		// Null means MapRun told us nothing, which is different from "not
		// over 55" and must not be recorded as a decision.
		$columns = Import_Reconciler::raw_columns( array( 'maprun_id' => 'a' ) );

		$this->assertNull( $columns['raw_is_over55'] );
	}
}
