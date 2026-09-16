<?php
/**
 * Tests that the published event table has as many cells as it has headings.
 *
 * The headings come from Event_Presenter::columns(); the body cells are written
 * out in the template. Adding a column to one and not the other does not fail
 * anywhere — it publishes a table where every figure sits under the wrong
 * heading, which reads as plausible and is wrong. That is the whole risk of
 * having added three columns, so it is asserted here by rendering the template
 * rather than by reading it.
 *
 * The template's WordPress helpers are stubbed to their plain-PHP equivalents.
 * That is enough to render it: escaping is WordPress's business and is tested
 * by WordPress, whereas the shape of the table is this plugin's.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Categories;
use MVOC\StreetO\Domain\Event_Presenter;
use MVOC\StreetO\Domain\Scoring_Engine;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}

	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}

	function esc_html_e( $text, $domain = '' ) {
		echo esc_html( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	function esc_attr_e( $text, $domain = '' ) {
		echo esc_attr( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	function esc_html__( $text, $domain = '' ) {
		return esc_html( $text );
	}
}

/**
 * @coversNothing
 */
class EventTableTemplateTest extends TestCase {

	/**
	 * Render the published template for a field covering every case.
	 *
	 * @return string
	 */
	private function render(): string {
		$scored = ( new Scoring_Engine() )->score_event(
			array(
				array( 'display_name' => 'Overall Winner', 'club' => 'MVOC', 'course_label' => '60', 'score' => 1180, 'penalty' => 0 ),
				array( 'display_name' => 'Leading Lady', 'club' => '', 'course_label' => '60', 'score' => 960, 'penalty' => 0, 'is_female' => true ),
				// Short course, so the scaling footnote renders too.
				array( 'display_name' => 'Vet Lady', 'club' => 'MVOC', 'course_label' => '40', 'score' => 600, 'penalty' => 0, 'is_female' => true, 'is_over55' => true ),
				array( 'display_name' => 'Vet Man', 'club' => '', 'course_label' => '60', 'score' => 780, 'penalty' => 30, 'is_over55' => true ),
				array( 'display_name' => 'Unconfirmed', 'club' => '', 'course_label' => '60', 'score' => 500, 'penalty' => 0 ),
			)
		);

		$model = ( new Event_Presenter() )->present(
			$scored,
			array( array( 'display_name' => 'The Organiser', 'club' => 'MVOC' ) )
		);
		$event = array( 'label' => 'Event 1 — Epsom Downs', 'event_number' => 1, 'is_published' => true );

		ob_start();
		require MVOC_STREETO_DIR . 'public/templates/event-table.php';

		return (string) ob_get_clean();
	}

	public function test_every_row_has_a_cell_for_every_heading(): void {
		$html = $this->render();

		$this->assertSame( 1, preg_match( '#<thead>(.*?)</thead>#s', $html, $head ) );
		$headings = substr_count( $head[1], '<th ' );

		$this->assertSame(
			count( ( new Event_Presenter() )->columns() ),
			$headings,
			'the template renders its headings from the presenter, so these cannot differ'
		);

		$this->assertSame( 1, preg_match( '#<tbody>(.*?)</tbody>#s', $html, $body ) );

		// Five runners and the organiser.
		$this->assertSame( 6, preg_match_all( '#<tr[^>]*>.*?</tr>#s', $body[1], $rows ) );

		foreach ( $rows[0] as $index => $row ) {
			$this->assertSame(
				$headings,
				substr_count( $row, '<td' ) + substr_count( $row, '<th ' ),
				sprintf( 'row %d does not line up with the headings', $index )
			);
		}
	}

	public function test_the_table_is_introduced_by_a_numbered_heading(): void {
		// The shortcode lands under the organiser's report, so the block has to
		// announce itself rather than starting at the header row.
		$html = $this->render();

		$this->assertStringContainsString(
			'<h3 class="mvoc-streeto-event-heading">',
			$html
		);
		$this->assertStringContainsString( 'Event 1 results', $html );
	}

	public function test_the_category_headings_are_the_shared_ones(): void {
		$html = $this->render();

		foreach ( Categories::columns() as $label ) {
			// Classed like the cells beneath them: the narrow-screen rule hides
			// heading and cell together, so a row cannot slide under the wrong
			// headings.
			$this->assertStringContainsString(
				'<th scope="col" class="mvoc-streeto-category-col">' . $label . '</th>',
				$html
			);
		}
	}

	public function test_every_category_heading_has_a_matching_cell_class(): void {
		// The count that matters is per row: as many classed headings as classed
		// cells, or hiding the class at one breakpoint misaligns the table.
		$html = $this->render();

		$this->assertSame( 1, preg_match( '#<thead>(.*?)</thead>#s', $html, $head ) );
		$this->assertSame( 1, preg_match( '#<tbody>.*?<tr[^>]*>(.*?)</tr>#s', $html, $row ) );

		$this->assertSame(
			substr_count( $head[1], 'mvoc-streeto-category-col' ),
			substr_count( $row[1], 'mvoc-streeto-category-col' ),
			'heading and cell counts for the category columns have drifted apart'
		);
	}

	public function test_no_club_reaches_the_published_table(): void {
		// Every runner in the fixture above has a club or an empty one, so if a
		// Club cell came back the value would be in the HTML. It is not
		// published at all: MapRun takes it as free text and it arrives spelt
		// several ways for the same club.
		$html = $this->render();

		$this->assertStringNotContainsString( 'MVOC', $html );
		$this->assertStringNotContainsString( 'Club', $html );
	}

	public function test_a_category_a_runner_is_not_in_leaves_an_empty_cell(): void {
		// Not a dash, which reads as "no position yet".
		$html = $this->render();

		$this->assertStringContainsString( '<td class="mvoc-streeto-category-col"></td>', $html );
		$this->assertStringContainsString( '<td class="mvoc-streeto-category-col">1</td>', $html );
	}

	public function test_the_category_cells_are_keyed_by_category_not_by_label(): void {
		// The template iterates the category keys to look each runner's
		// position up. Iterating the labels instead still renders three cells,
		// every one of them blank — a table that looks right and says nobody
		// placed in any category.
		$html = $this->render();

		// Two women, so Ladies ranks 1 and 2; one M55 and one W55, each 1st.
		$this->assertSame(
			4,
			preg_match_all( '#<td class="mvoc-streeto-category-col">[1-9]#', $html ),
			'a category ranking went missing'
		);
	}
}
