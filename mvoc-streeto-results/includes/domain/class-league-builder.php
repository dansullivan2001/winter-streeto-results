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
	 * Key marking the organiser bonus in a counting-slot list.
	 *
	 * A string rather than an integer so it can never collide with an event
	 * index, and so a caller reading the list can tell the two apart without
	 * knowing how many events the series has.
	 */
	public const ORGANISER_SLOT = 'organiser';

	/**
	 * The categories the league is ranked in.
	 *
	 * Every one is the same competition ranking over a different subset, so
	 * they are declared as predicates and the ranking is written once. The
	 * Over-55 titles are awarded separately to a man and a woman, which is why
	 * the filter has to be a predicate rather than a single flag name — and
	 * why adding a fifth category later costs one line.
	 *
	 * @return array<string,callable(array<string,mixed>):bool>
	 */
	private static function categories(): array {
		return array(
			'position'            => static fn( array $c ): bool => true,
			'ladies_position'     => static fn( array $c ): bool => ! empty( $c['is_female'] ),
			'o55_men_position'    => static fn( array $c ): bool => ! empty( $c['is_over55'] ) && empty( $c['is_female'] ),
			'o55_women_position'  => static fn( array $c ): bool => ! empty( $c['is_over55'] ) && ! empty( $c['is_female'] ),
		);
	}

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
	 * `events_entered`, `counting`, `total`, `position`, `ladies_position`,
	 * `o55_men_position` and `o55_women_position`. Category positions are null
	 * for competitors outside that category, and `counting` lists which scores
	 * the total is made of.
	 *
	 * @param array<int,array<string,mixed>> $competitors League entrants.
	 * @return array<int,array<string,mixed>> Standings, best first.
	 */
	public function build( array $competitors ): array {
		$rows = array();

		foreach ( $competitors as $competitor ) {
			// Re-keyed to 0..n so a counting slot below is an index into the
			// event list as it is displayed, whatever the caller handed in.
			$competitor['event_points'] = array_values( $competitor['event_points'] ?? array() );

			$points = $this->numeric_points( $competitor['event_points'] );

			$competitor['organiser_points'] = empty( $competitor['organised'] )
				? null
				: $this->organiser_bonus( $points );
			$competitor['events_entered']   = count( array_filter( $points, static fn( $p ) => $p > 0 ) );
			$competitor['counting']         = $this->counting_slots( $points, $competitor['organiser_points'] );
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
	 * Keys are preserved, so a score can be traced back to the event it came
	 * from once the list has been sorted by value.
	 *
	 * @param array<int,mixed> $event_points Raw per-event values.
	 * @return array<int,int>
	 */
	private function numeric_points( array $event_points ): array {
		$points = array();

		foreach ( $event_points as $index => $value ) {
			if ( is_numeric( $value ) ) {
				$points[ $index ] = (int) $value;
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
	 * Everything competing for a counting slot, keyed by where it came from.
	 *
	 * By default the organiser bonus competes for one of those slots rather
	 * than being added on top, which is what the workbook does — so an
	 * organiser has nine candidate values, not eight.
	 *
	 * @param array<int,int> $points           Event scores, keyed by event index.
	 * @param int|null       $organiser_points Organiser bonus, if any.
	 * @return array<int|string,int>
	 */
	private function candidates( array $points, ?int $organiser_points ): array {
		if ( null !== $organiser_points && Scoring_Config::BONUS_COMPETES === $this->config->organiser_bonus_mode ) {
			$points[ self::ORGANISER_SLOT ] = $organiser_points;
		}

		return $points;
	}

	/**
	 * Which scores make up the total: event indexes, plus ORGANISER_SLOT where
	 * the bonus takes one of the counting places.
	 *
	 * The total is computed from this same list, so "which five count" can
	 * never drift from the arithmetic that produced the figure. The league
	 * preview marks them, which is what turns a wall of numbers into an
	 * answer to "would publishing this event change anything?" — a score
	 * below someone's counting five moves them not at all.
	 *
	 * Equal scores at the cut are interchangeable by definition: the total is
	 * the same whichever is marked.
	 *
	 * @param array<int,int> $points           Event scores, keyed by event index.
	 * @param int|null       $organiser_points Organiser bonus, if any.
	 * @return array<int,int|string> Slot keys, best first.
	 */
	public function counting_slots( array $points, ?int $organiser_points ): array {
		$candidates = $this->candidates( $points, $organiser_points );

		arsort( $candidates );

		return array_slice( array_keys( $candidates ), 0, $this->config->counting_events );
	}

	/**
	 * League total: the best N of the available scores.
	 *
	 * @param array<int,int> $points           Event scores, keyed by event index.
	 * @param int|null       $organiser_points Organiser bonus, if any.
	 */
	private function total( array $points, ?int $organiser_points ): int {
		$candidates = $this->candidates( $points, $organiser_points );
		$total      = 0;

		foreach ( $this->counting_slots( $points, $organiser_points ) as $slot ) {
			$total += $candidates[ $slot ];
		}

		// The other mode: the bonus never competed above, so it is added on top.
		if ( null !== $organiser_points && Scoring_Config::BONUS_ADDED === $this->config->organiser_bonus_mode ) {
			$total += $organiser_points;
		}

		return $total;
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
		foreach ( self::categories() as $field => $qualifies ) {
			$totals = array();
			foreach ( $rows as $row ) {
				if ( $qualifies( $row ) ) {
					$totals[] = $row['total'];
				}
			}

			foreach ( $rows as $index => $row ) {
				if ( ! $qualifies( $row ) ) {
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
