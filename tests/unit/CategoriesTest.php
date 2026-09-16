<?php
/**
 * Tests for the shared definition of the four competitions.
 *
 * The event table and the league table both rank Ladies, M55 and W55. They did
 * not always: the league ranked them from its own copy of the rules, and when
 * the event table learned to as well there were two lists that had to agree
 * forever. These tests hold both tables to the one definition, because two
 * tables on the same page disagreeing about who is a W55 is the kind of thing
 * a club notices before the developer does.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Categories;
use MVOC\StreetO\Domain\League_Presenter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\Categories
 */
class CategoriesTest extends TestCase {

	public function test_the_four_competitions_are_the_ones_the_club_runs(): void {
		$this->assertSame(
			array( 'overall', 'ladies', 'o55_men', 'o55_women' ),
			Categories::keys()
		);
	}

	public function test_overall_is_the_plain_position_field(): void {
		// Every other category writes its own field; the overall one is the
		// position the table is sorted by.
		$this->assertSame( 'position', Categories::fields()[ Categories::OVERALL ] );
	}

	public function test_the_ranking_columns_leave_overall_out(): void {
		// Overall has a column of its own, headed "Pos", and it comes first.
		$this->assertArrayNotHasKey( Categories::OVERALL, Categories::columns() );
		$this->assertSame(
			array( 'ladies' => 'Ladies', 'o55_men' => 'M55', 'o55_women' => 'W55' ),
			Categories::columns()
		);
	}

	public function test_the_league_presenter_reads_the_same_definition(): void {
		$this->assertSame( Categories::keys(), League_Presenter::categories() );
		$this->assertSame( Categories::columns(), League_Presenter::category_columns() );
	}

	public function test_an_unknown_category_is_not_one_of_them(): void {
		$this->assertTrue( Categories::exists( 'o55_women' ) );
		$this->assertFalse( Categories::exists( 'over55' ) );
	}

	/**
	 * @dataProvider membership_provider
	 *
	 * @param array<string,bool> $runner   Their flags.
	 * @param string[]           $expected The categories they are in.
	 */
	public function test_who_belongs_to_each_category( array $runner, array $expected ): void {
		$in = array();

		foreach ( Categories::predicates() as $category => $qualifies ) {
			if ( $qualifies( $runner ) ) {
				$in[] = $category;
			}
		}

		$this->assertSame( $expected, $in );
	}

	public function membership_provider(): array {
		return array(
			'a man under 55'   => array( array(), array( 'overall' ) ),
			'a woman under 55' => array( array( 'is_female' => true ), array( 'overall', 'ladies' ) ),
			// The Over-55 titles are awarded separately to a man and a woman, so
			// a W55 is in three of the four and an M55 in two.
			'an M55'           => array( array( 'is_over55' => true ), array( 'overall', 'o55_men' ) ),
			'a W55'            => array(
				array( 'is_female' => true, 'is_over55' => true ),
				array( 'overall', 'ladies', 'o55_women' ),
			),
		);
	}

	public function test_flags_are_merged_onto_a_row_from_its_competitor(): void {
		$rows = Categories::apply(
			array(
				array( 'competitor_id' => 7 ),
				array( 'competitor_id' => 9 ),
			),
			array(
				7 => array( 'is_female' => true, 'is_over55' => true ),
				9 => array( 'is_female' => false, 'is_over55' => false ),
			)
		);

		$this->assertTrue( $rows[0]['is_female'] );
		$this->assertTrue( $rows[0]['is_over55'] );
		$this->assertFalse( $rows[1]['is_female'] );
		$this->assertFalse( $rows[1]['is_over55'] );
	}

	public function test_a_row_with_nobody_confirmed_against_it_gets_neither_flag(): void {
		// Not an error and not a guess: the row still ranks overall, and simply
		// holds no category until the name is confirmed on Confirm names.
		$rows = Categories::apply(
			array(
				array( 'competitor_id' => null, 'display_name' => 'A Newcomer' ),
				array( 'competitor_id' => 404 ),
			),
			array( 7 => array( 'is_female' => true, 'is_over55' => false ) )
		);

		foreach ( $rows as $row ) {
			$this->assertFalse( $row['is_female'] );
			$this->assertFalse( $row['is_over55'] );
		}
	}

	public function test_merging_leaves_the_rest_of_the_row_alone(): void {
		$rows = Categories::apply(
			array( array( 'competitor_id' => 7, 'score' => 940, 'penalty' => 12 ) ),
			array( 7 => array( 'is_female' => true, 'is_over55' => false ) )
		);

		$this->assertSame( 940, $rows[0]['score'] );
		$this->assertSame( 12, $rows[0]['penalty'] );
	}

	public function test_positions_read_every_ranking_a_row_holds(): void {
		$positions = Categories::positions_of(
			array(
				'position'           => 3,
				'ladies_position'    => 1,
				'o55_women_position' => null,
			)
		);

		$this->assertSame( 3, $positions['overall'] );
		$this->assertSame( 1, $positions['ladies'] );
		// Absent and null read the same: not in that category.
		$this->assertNull( $positions['o55_men'] );
		$this->assertNull( $positions['o55_women'] );
	}
}
