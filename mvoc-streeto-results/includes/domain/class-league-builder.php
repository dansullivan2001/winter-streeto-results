<?php
/**
 * Builds the league table from each competitor's event league points.
 *
 * Mirrors the club's 2019/20 workbook, whose own computed standings are
 * committed as a test fixture.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Produces league standings and the three category rankings.
 */
class League_Builder {

	/**
	 * The categories the league is ranked in.
	 *
	 * Each is the same ranking over a different subset, which is why they are
	 * declared as predicates rather than written out three times.
	 *
	 * key => [ output field, competitor flag or null for everyone ]
	 */
	private const CATEGORIES = array(
		'overall' => array( 'position', null ),
		'ladies'  => array( 'ladies_position', 'is_female' ),
		'over55'  => array( 'over55_position', 'is_over55' ),
	);

	private Scoring_Config $config;

	/**
	 * @param Scoring_Config|null $config Series scoring rules; defaults to the workbook's.
	 */
	public function __construct( ?Scoring_Config $config = null ) {
		$this->config = $config ?? new Scoring_Config();
	}

	/**
	 * Build the standings.
	 *
	 * Each input competitor may carry:
	 *   name          string
	 *   is_female     bool
	 *   is_over55     bool
	 *   event_points  array  league points per event, null where they did not run
	 *   organised     mixed  truthy if they organised an event this series
	 *
	 * Returned rows keep every input key and gain `organiser_points`,
	 * `events_entered`, `total`, `position`, `ladies_position` and
	 * `over55_position`. Category positions are null for competitors outside
	 * that category.
	 *
	 * @param array<int,array<string,mixed>> $competitors League entrants.
	 * @return array<int,array<string,mixed>> Standings, best first.
	 */
	public function build( array $competitors ): array {
		$rows = array();

		foreach ( $competitors as $competitor ) {
			$points = $this->numeric_points( $competitor['event_points'] ?? array() );

			$competitor['organiser_points'] = empty( $competitor['organised'] )
				? null
				: $this->organiser_bonus( $points );
			$competitor['events_entered']   = count( array_filter( $points, static fn( $p ) => $p > 0 ) );
			$competitor['total']            = $this->total( $points, $competitor['organiser_points'] );

			$rows[] = $competitor;
		}

		$this->assign_positions( $rows );

		usort( $rows, static fn( array $a, array $b ): int => $b['total'] <=> $a['total'] );

		return $rows;
	}

	/**
	 * Keep only the numeric event scores.
	 *
	 * Event cells are null where a competitor did not run, and the organiser's
	 * own event shows a dash rather than a score.
	 *
	 * @param array<int,mixed> $event_points Raw per-event values.
	 * @return array<int,int>
	 */
	private function numeric_points( array $event_points ): array {
		$points = array();

		foreach ( $event_points as $value ) {
			if ( is_numeric( $value ) ) {
				$points[] = (int) $value;
			}
		}

		return $points;
	}

	/**
	 * The organiser's bonus: their best league points across the series.
	 *
	 * @param array<int,int> $points Their event scores.
	 */
	private function organiser_bonus( array $points ): int {
		return $points ? max( $points ) : 0;
	}

	/**
	 * League total: the best N of the available scores.
	 *
	 * By default the organiser bonus competes for one of those counting slots
	 * rather than being added on top, which is what the workbook does — so an
	 * organiser has nine candidate values, not eight.
	 *
	 * @param array<int,int> $points           Event scores.
	 * @param int|null       $organiser_points Organiser bonus, if any.
	 */
	private function total( array $points, ?int $organiser_points ): int {
		$added = 0;

		if ( null !== $organiser_points ) {
			if ( Scoring_Config::BONUS_ADDED === $this->config->organiser_bonus_mode ) {
				$added = $organiser_points;
			} else {
				$points[] = $organiser_points;
			}
		}

		rsort( $points );

		return array_sum( array_slice( $points, 0, $this->config->counting_events ) ) + $added;
	}

	/**
	 * Assign the overall and category positions in place.
	 *
	 * Every ranking is the same competition ranking — count how many in the
	 * relevant group scored strictly more, add one — so ties share a position
	 * and the next competitor skips. Applied to everyone for the overall
	 * standings, and to a filtered subset for each category.
	 *
	 * @param array<int,array<string,mixed>> $rows Standings, modified in place.
	 */
	private function assign_positions( array &$rows ): void {
		foreach ( self::CATEGORIES as $category ) {
			list( $field, $flag ) = $category;

			$totals = array();
			foreach ( $rows as $row ) {
				if ( null === $flag || ! empty( $row[ $flag ] ) ) {
					$totals[] = $row['total'];
				}
			}

			foreach ( $rows as $index => $row ) {
				if ( null !== $flag && empty( $row[ $flag ] ) ) {
					$rows[ $index ][ $field ] = null;
					continue;
				}

				$better = 0;
				foreach ( $totals as $total ) {
					if ( $total > $row['total'] ) {
						++$better;
					}
				}

				$rows[ $index ][ $field ] = $better + 1;
			}
		}
	}
}
