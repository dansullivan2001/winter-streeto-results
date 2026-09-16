<?php
/**
 * Turns scored event rows into the table the club publishes.
 *
 * Columns were chosen with the club: Position, Name, Course, Score, Time
 * Penalty, Total, League points — with the Ladies, M55 and W55 rankings beside
 * the overall position, in the same place and the same order as the league
 * table, so the four competitions read the same way on the night as they do
 * over the season.
 *
 * Elapsed time is deliberately absent. The league's tie-break ignores it — two
 * equal totals with equal penalties finish equal however fast either runner was
 * — so publishing a time column would invite "why am I below someone slower?"
 * when the answer is simply that the rule does not look at time.
 *
 * Club is deliberately absent too, and was once published. It is free text at
 * MapRun's end, so one club arrives spelt four different ways and a published
 * column prints the inconsistency rather than resolving it. Nothing is thrown
 * away: the imported club stays on the result row and on the competitor, where
 * it earns its place telling two same-named runners apart, and the admin
 * screens that do that work still show it.
 *
 * Deliberately free of WordPress dependencies: this builds the table model and
 * the template renders it, so the output shape is unit-testable and escaping
 * stays where it belongs.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the published event results table.
 */
class Event_Presenter {

	private Scoring_Config $config;

	/**
	 * @param Scoring_Config|null $config Series scoring rules.
	 */
	public function __construct( ?Scoring_Config $config = null ) {
		$this->config = $config ?? new Scoring_Config();
	}

	/**
	 * Column headings, in order.
	 *
	 * @return string[]
	 */
	public function columns(): array {
		return array_merge(
			array( 'Pos' ),
			array_values( Categories::columns() ),
			array( 'Name', 'Course', 'Score', 'Penalty', 'Total', 'League pts' )
		);
	}

	/**
	 * Build the table model.
	 *
	 * @param array<int,array<string,mixed>> $scored     Rows from Scoring_Engine.
	 * @param array<int,array<string,mixed>> $organisers Organiser competitors, usually zero or one.
	 * @return array{columns:string[],rows:array<int,array<string,mixed>>,has_short_course:bool,scaled_courses:array<int,array{label:string,percent:int}>}
	 */
	public function present( array $scored, array $organisers = array() ): array {
		$rows             = array();
		$has_short_course = false;
		// Which courses were actually scaled, and by how much, so the footnote
		// states this series' own rule instead of a sentence that happens to
		// match the default. Keyed by label to report each course once.
		$scaled_courses = array();

		foreach ( $scored as $row ) {
			// Excluded and withdrawn rows never reach the public table: test
			// runs, course setters, failed uploads and discarded duplicates.
			if ( ! empty( $row['is_excluded'] ) || ! empty( $row['is_withdrawn'] ) ) {
				continue;
			}

			$course = (string) ( $row['course_label'] ?? '' );
			$factor = '' === $course ? 1.0 : $this->config->factor_for_course( $course );

			if ( 1.0 !== $factor ) {
				$has_short_course          = true;
				$scaled_courses[ $course ] = array(
					'label'   => $course,
					'percent' => (int) round( $factor * 100 ),
				);
			}

			$rows[] = array(
				'position'       => $row['position'],
				'position_label' => $row['position_label'],
				'name'           => (string) ( $row['display_name'] ?? $row['name'] ?? '' ),
				'course'         => $course,
				'score'          => $row['score'] ?? null,
				'penalty'        => (int) ( $row['penalty'] ?? 0 ),
				'total'          => $row['total'],
				'league_points'  => $row['league_points'],
				'is_scaled'      => 1.0 !== $factor,
				// Every ranking this row holds, the way the league table carries
				// them, so the template renders one set of columns either side.
				'positions'      => Categories::positions_of( $row ),
			);
		}

		foreach ( $organisers as $organiser ) {
			// Organisers are listed but not ranked, exactly as the workbook
			// did — they take their bonus in the league, not on the night.
			// Usually one row, occasionally more where an event is run jointly.
			$rows[] = array(
				'position'       => null,
				'position_label' => '',
				'name'           => (string) ( $organiser['display_name'] ?? '' ),
				'course'         => '',
				'score'          => null,
				'penalty'        => 0,
				'total'          => null,
				'league_points'  => null,
				'is_organiser'   => true,
				'is_scaled'      => false,
				// Unranked, so no ranking anywhere — not even in a category they
				// belong to. Their reward for the night is the league bonus.
				'positions'      => Categories::positions_of( array() ),
			);
		}

		return array(
			'columns'          => $this->columns(),
			'rows'             => $rows,
			'has_short_course' => $has_short_course,
			'scaled_courses'   => array_values( $scaled_courses ),
		);
	}

	/**
	 * The model carries `has_short_course` so the template can decide whether
	 * to print the pro-rata footnote. The wording lives in the template, since
	 * translation is a WordPress concern and this class stays free of it — and
	 * the footnote is noise on an event where nobody ran the short course.
	 */
}
