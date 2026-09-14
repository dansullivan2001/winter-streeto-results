<?php
/**
 * Tests that a pasted MapRun response lands on the course it belongs to.
 *
 * A paste carries no course of its own: the response is the same shape whether
 * it came from the 60 or the 40. The importer used to take the first source on
 * the event, which Events_Repo orders `course_label DESC` — so it was always
 * the 60, and the 40 could not be imported by paste at all.
 *
 * Getting it wrong was not a near miss. The rows were parsed under the wrong
 * course label, so a 40 scored unscaled; and because Import_Reconciler matches
 * on MapRun ids, none of the stored 60-minute ids appeared in the response and
 * every one of them was withdrawn. The long course vanished — on the one path
 * available to a host that blocks MapRun's port, which is the situation the
 * paste route exists for.
 *
 * The second test below is the damage, asserted directly against the
 * reconciler, so that anything which reintroduces the guess fails loudly here.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Import_Reconciler;
use MVOC\StreetO\Importer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Importer::sources_for_paste
 */
class PasteSourceTest extends TestCase {

	/**
	 * Both courses, ordered as Events_Repo::sources() returns them.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function sources(): array {
		return array(
			array(
				'id'                => 7,
				'course_label'      => '60',
				'maprun_event_name' => 'Cheam Nov26 PXAS ScoreQ60',
			),
			array(
				'id'                => 8,
				'course_label'      => '40',
				'maprun_event_name' => 'Cheam Nov26 PXAS ScoreQ40',
			),
		);
	}

	public function test_the_chosen_source_is_the_only_one_imported(): void {
		$chosen = Importer::sources_for_paste( $this->sources(), 8 );

		$this->assertCount( 1, $chosen );
		$this->assertSame( 8, (int) $chosen[0]['id'] );
		$this->assertSame( '40', $chosen[0]['course_label'] );
	}

	public function test_the_long_course_can_still_be_chosen(): void {
		$chosen = Importer::sources_for_paste( $this->sources(), 7 );

		$this->assertCount( 1, $chosen );
		$this->assertSame( '60', $chosen[0]['course_label'] );
	}

	/**
	 * No choice must not silently become "the first one".
	 */
	public function test_no_chosen_source_imports_nothing(): void {
		$this->assertSame( array(), Importer::sources_for_paste( $this->sources(), 0 ) );
	}

	/**
	 * A stale id from an event whose sources have since changed.
	 */
	public function test_an_unknown_source_imports_nothing(): void {
		$this->assertSame( array(), Importer::sources_for_paste( $this->sources(), 99 ) );
	}

	public function test_an_event_with_no_sources_imports_nothing(): void {
		$this->assertSame( array(), Importer::sources_for_paste( array(), 7 ) );
	}

	/**
	 * The damage the choice prevents, stated outright.
	 *
	 * Reconciling the 40's rows against the 60's stored rows withdraws every
	 * one of them, because a withdrawal means "MapRun no longer lists this" and
	 * the 40's response quite correctly never listed the 60's runners.
	 */
	public function test_reconciling_one_course_against_the_other_withdraws_the_whole_field(): void {
		$stored_60 = array(
			array(
				'id'           => 1,
				'maprun_id'    => 'A1',
				'is_manual'    => 0,
				'is_withdrawn' => 0,
			),
			array(
				'id'           => 2,
				'maprun_id'    => 'A2',
				'is_manual'    => 0,
				'is_withdrawn' => 0,
			),
		);

		$incoming_40 = array(
			array(
				'maprun_id'  => 'B1',
				'first_name' => 'Short',
				'surname'    => 'Course',
			),
		);

		$summary = Import_Reconciler::summarise(
			( new Import_Reconciler() )->reconcile( $stored_60, $incoming_40 )
		);

		$this->assertSame( 2, $summary[ Import_Reconciler::WITHDRAW ] );
		$this->assertSame( 1, $summary[ Import_Reconciler::INSERT ] );
		$this->assertSame( 0, $summary[ Import_Reconciler::UPDATE ] );
	}
}
