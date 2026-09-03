<?php
/**
 * Turns scored event rows into the table the club publishes.
 *
 * Columns were chosen with the club: Position, Name, Club, Course, Score, Time
 * Penalty, Total, League points.
 *
 * Elapsed time is deliberately absent. The league's tie-break ignores it — two
 * equal totals with equal penalties finish equal however fast either runner was
 * — so publishing a time column would invite "why am I below someone slower?"
 * when the answer is simply that the rule does not look at time.
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
		return array( 'Pos', 'Name', 'Club', 'Course', 'Score', 'Penalty', 'Total', 'League pts' );
	}

	/**
	 * Build the table model.
	 *
	 * @param array<int,array<string,mixed>> $scored    Rows from Scoring_Engine.
	 * @param array<string,mixed>            $organiser Optional organiser competitor.
	 * @return array{columns:string[],rows:array<int,array<string,mixed>>,has_short_course:bool}
	 */
	public function present( array $scored, array $organiser = array() ): array {
		$rows             = array();
		$has_short_course = false;

		foreach ( $scored as $row ) {
			// Excluded and withdrawn rows never reach the public table: test
			// runs, course setters, failed uploads and discarded duplicates.
			if ( ! empty( $row['is_excluded'] ) || ! empty( $row['is_withdrawn'] ) ) {
				continue;
			}

			$course = (string) ( $row['course_label'] ?? '' );
			if ( '' !== $course && 1.0 !== $this->config->factor_for_course( $course ) ) {
				$has_short_course = true;
			}

			$rows[] = array(
				'position'       => $row['position'],
				'position_label' => $row['position_label'],
				'name'           => (string) ( $row['display_name'] ?? $row['name'] ?? '' ),
				'club'           => (string) ( $row['club'] ?? '' ),
				'course'         => $course,
				'score'          => $row['score'] ?? null,
				'penalty'        => (int) ( $row['penalty'] ?? 0 ),
				'total'          => $row['total'],
				'league_points'  => $row['league_points'],
				'is_scaled'      => '' !== $course && 1.0 !== $this->config->factor_for_course( $course ),
			);
		}

		if ( $organiser ) {
			// The organiser is listed but not ranked, exactly as the workbook
			// did — they take their bonus in the league, not on the night.
			$rows[] = array(
				'position'       => null,
				'position_label' => '',
				'name'           => (string) ( $organiser['display_name'] ?? '' ),
				'club'           => (string) ( $organiser['club'] ?? '' ),
				'course'         => '',
				'score'          => null,
				'penalty'        => 0,
				'total'          => null,
				'league_points'  => null,
				'is_organiser'   => true,
				'is_scaled'      => false,
			);
		}

		return array(
			'columns'          => $this->columns(),
			'rows'             => $rows,
			'has_short_course' => $has_short_course,
		);
	}

	/**
	 * The model carries `has_short_course` so the template can decide whether
	 * to print the pro-rata footnote. The wording lives in the template, since
	 * translation is a WordPress concern and this class stays free of it — and
	 * the footnote is noise on an event where nobody ran the short course.
	 */
}
