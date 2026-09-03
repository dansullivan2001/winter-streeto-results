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

	/**
	 * Category => the standings field carrying that category's position.
	 *
	 * @var array<string,string>
	 */
	private const CATEGORY_FIELDS = array(
		'overall'   => 'position',
		'ladies'    => 'ladies_position',
		'o55_men'   => 'o55_men_position',
		'o55_women' => 'o55_women_position',
	);

	/**
	 * Human labels for each category.
	 *
	 * @var array<string,string>
	 */
	private const CATEGORY_LABELS = array(
		'overall'   => 'Overall',
		'ladies'    => 'Ladies',
		'o55_men'   => 'Over 55 Men',
		'o55_women' => 'Over 55 Women',
	);

	/**
	 * Whether a category name is one this presenter knows.
	 *
	 * @param string $category Category key.
	 */
	public static function is_category( string $category ): bool {
		return isset( self::CATEGORY_FIELDS[ $category ] );
	}

	/**
	 * Every category key, for rendering all four.
	 *
	 * @return string[]
	 */
	public static function categories(): array {
		return array_keys( self::CATEGORY_FIELDS );
	}

	/**
	 * Build the table model for one category.
	 *
	 * @param array<int,array<string,mixed>> $standings Rows from League_Builder.
	 * @param array<int,string>              $events    Event labels, in series order.
	 * @param string                         $category  One of the category keys.
	 * @return array{category:string,label:string,events:array<int,string>,rows:array<int,array<string,mixed>>}
	 */
	public function present( array $standings, array $events, string $category = 'overall' ): array {
		if ( ! self::is_category( $category ) ) {
			$category = 'overall';
		}

		$field = self::CATEGORY_FIELDS[ $category ];
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
				'event_points'     => self::event_detail( $row, $events ),
				'overall_position' => $row['position'] ?? null,
			);
		}

		usort( $rows, static fn( array $a, array $b ): int => $a['position'] <=> $b['position'] );

		return array(
			'category' => $category,
			'label'    => self::CATEGORY_LABELS[ $category ],
			'events'   => $events,
			'rows'     => $rows,
		);
	}

	/**
	 * Per-event points for the expander, aligned to the event list.
	 *
	 * Events a competitor missed are kept as nulls rather than dropped, so the
	 * detail lines up column-for-column with the series regardless of who ran
	 * what.
	 *
	 * @param array<string,mixed> $row    Standings row.
	 * @param array<int,string>   $events Event labels.
	 * @return array<int,array{label:string,points:int|null}>
	 */
	private static function event_detail( array $row, array $events ): array {
		$points = is_array( $row['event_points'] ?? null ) ? array_values( $row['event_points'] ) : array();
		$detail = array();

		foreach ( array_values( $events ) as $index => $label ) {
			$value = $points[ $index ] ?? null;

			$detail[] = array(
				'label'  => $label,
				'points' => is_numeric( $value ) ? (int) $value : null,
			);
		}

		return $detail;
	}
}
