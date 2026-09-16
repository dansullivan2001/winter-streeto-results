<?php
/**
 * Turns league standings into a table that works on a phone.
 *
 * The full league is about fifteen columns — four position columns, eight
 * events, the organiser bonus, events entered and the total. StreetO results
 * get read on phones, standing in the dark outside a village hall, so the
 * published table shows Pos, Name and Total, with the per-event breakdown
 * behind an expander.
 *
 * The detail is always present in the markup; the expander only collapses it.
 * That keeps it readable with JavaScript off and searchable by the browser's
 * find-in-page, which matters when a runner is looking for their own name.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the published league table.
 */
class League_Presenter {

	private Scoring_Config $config;

	/**
	 * @param Scoring_Config|null $config Series scoring rules; defaults to the workbook's.
	 */
	public function __construct( ?Scoring_Config $config = null ) {
		$this->config = $config ?? new Scoring_Config();
	}

	/**
	 * Whether a category name is one this presenter knows.
	 *
	 * @param string $category Category key.
	 */
	public static function is_category( string $category ): bool {
		return Categories::exists( $category );
	}

	/**
	 * Every category key, for rendering all four.
	 *
	 * @return string[]
	 */
	public static function categories(): array {
		return Categories::keys();
	}

	/**
	 * Build the table model for one category.
	 *
	 * @param array<int,array<string,mixed>> $standings Rows from League_Builder.
	 * @param array<int,string>              $events    Event labels, in series order.
	 * @param string                         $category  One of the category keys.
	 * @return array{category:string,label:string,events:array<int,string>,rows:array<int,array<string,mixed>>,counting_events:int}
	 */
	public function present( array $standings, array $events, string $category = 'overall' ): array {
		if ( ! self::is_category( $category ) ) {
			$category = 'overall';
		}

		$field = Categories::fields()[ $category ];
		$rows  = array();

		foreach ( $standings as $row ) {
			$position = $row[ $field ] ?? null;

			// A competitor outside this category has no position in it, and is
			// simply not part of this table.
			if ( null === $position ) {
				continue;
			}

			$rows[] = array(
				'position'         => (int) $position,
				'name'             => (string) ( $row['display_name'] ?? $row['name'] ?? '' ),
				'total'            => (int) ( $row['total'] ?? 0 ),
				'events_entered'   => (int) ( $row['events_entered'] ?? 0 ),
				'organiser_points' => $row['organiser_points'] ?? null,
				'organised'        => $row['organised'] ?? null,
				// Whether the organiser bonus is one of the scores the total is
				// actually made of, rather than a candidate that lost out.
				'organiser_counts' => in_array( League_Builder::ORGANISER_SLOT, (array) ( $row['counting'] ?? array() ), true ),
				'event_points'     => self::event_detail( $row, $events ),
				// Every ranking on every row, so one table can show them all
				// side by side the way the club's spreadsheet did.
				'positions'        => Categories::positions_of( $row ),
				'overall_position' => $row['position'] ?? null,
			);
		}

		usort( $rows, static fn( array $a, array $b ): int => $a['position'] <=> $b['position'] );

		return array(
			'category'        => $category,
			'label'           => Categories::labels()[ $category ],
			'events'          => $events,
			'rows'            => $rows,
			// Carried so the published footnote states this series' rule rather
			// than a number typed into the template, which would start lying
			// the first time a season counts a different many.
			'counting_events' => $this->config->counting_events,
		);
	}

	/**
	 * Column headings for the category rankings, in display order.
	 *
	 * @return array<string,string>
	 */
	public static function category_columns(): array {
		return Categories::columns();
	}

	/**
	 * Per-event points for the expander, aligned to the event list.
	 *
	 * Events a competitor missed are kept as nulls rather than dropped, so the
	 * detail lines up column-for-column with the series regardless of who ran
	 * what.
	 *
	 * `counts` says whether that score is one of the best N making up the
	 * total. The published table ignores it; the league preview marks it, so
	 * the co-ordinator can see which results are carrying a total before an
	 * event goes public.
	 *
	 * @param array<string,mixed> $row    Standings row.
	 * @param array<int,string>   $events Event labels.
	 * @return array<int,array{label:string,points:int|null,counts:bool}>
	 */
	private static function event_detail( array $row, array $events ): array {
		$points   = is_array( $row['event_points'] ?? null ) ? array_values( $row['event_points'] ) : array();
		$counting = (array) ( $row['counting'] ?? array() );
		$detail   = array();

		foreach ( array_values( $events ) as $index => $label ) {
			$value = $points[ $index ] ?? null;

			$detail[] = array(
				'label'  => $label,
				'points' => is_numeric( $value ) ? (int) $value : null,
				'counts' => in_array( $index, $counting, true ),
			);
		}

		return $detail;
	}
}
