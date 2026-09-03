<?php
/**
 * Turns a set of event results into totals, positions and league points.
 *
 * The rules come from the club's 2019/20 workbook, whose formulas were read
 * directly rather than inferred, and whose own computed answers are committed
 * as test fixtures.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Scores one event.
 */
class Scoring_Engine {

	private Scoring_Config $config;

	/**
	 * @param Scoring_Config|null $config Series scoring rules; defaults to the workbook's.
	 */
	public function __construct( ?Scoring_Config $config = null ) {
		$this->config = $config ?? new Scoring_Config();
	}

	/**
	 * Score an event.
	 *
	 * Each input row may carry:
	 *   name          string
	 *   score         int|null   raw points from MapRun
	 *   penalty       int        time penalty, defaults to 0
	 *   course_label  string     '60' or '45', defaults to '60'
	 *   is_organiser  bool       organiser: listed, but not ranked
	 *   is_excluded   bool       test run, course setter, duplicate
	 *
	 * Returned rows keep every input key and gain `total`, `position`,
	 * `position_label` and `league_points`. Rows that are not ranked get null
	 * for all four rather than being dropped: the co-ordinator still needs to
	 * see them, and the organiser still appears on the published table.
	 *
	 * @param array<int,array<string,mixed>> $rows Result rows.
	 * @return array<int,array<string,mixed>> Scored rows, ranked first.
	 */
	public function score_event( array $rows ): array {
		$scored = array();

		foreach ( $rows as $row ) {
			$row['total']          = null;
			$row['position']       = null;
			$row['position_label'] = null;
			$row['league_points']  = null;

			if ( $this->is_ranked( $row ) ) {
				$row['total'] = $this->total_for( $row );
			}

			$scored[] = $row;
		}

		$this->assign_positions( $scored );

		// Ranked rows first, in finishing order; unranked rows keep their given
		// order at the end, which is where the workbook puts the organiser.
		usort(
			$scored,
			static function ( array $a, array $b ): int {
				if ( null === $a['position'] && null === $b['position'] ) {
					return 0;
				}
				if ( null === $a['position'] ) {
					return 1;
				}
				if ( null === $b['position'] ) {
					return -1;
				}

				return $a['position'] <=> $b['position'];
			}
		);

		return $scored;
	}

	/**
	 * Whether a row takes part in the ranking.
	 *
	 * @param array<string,mixed> $row Result row.
	 */
	private function is_ranked( array $row ): bool {
		if ( ! empty( $row['is_organiser'] ) || ! empty( $row['is_excluded'] ) ) {
			return false;
		}

		return isset( $row['score'] ) && is_numeric( $row['score'] );
	}

	/**
	 * Adjusted total for a row, on the common 60-minute scale.
	 *
	 * @param array<string,mixed> $row Result row.
	 */
	private function total_for( array $row ): int {
		$score   = (int) $row['score'];
		$penalty = (int) ( $row['penalty'] ?? 0 );
		$factor  = $this->config->factor_for_course( (string) ( $row['course_label'] ?? '60' ) );

		if ( $this->config->penalty_before_scaling ) {
			return $this->config->round_score( ( $score - $penalty ) * $factor );
		}

		return $this->config->round_score( ( $score * $factor ) - $penalty );
	}

	/**
	 * The penalty used for tie-breaking.
	 *
	 * @param array<string,mixed> $row Result row.
	 */
	private function tiebreak_penalty( array $row ): float {
		$penalty = (float) ( $row['penalty'] ?? 0 );

		if ( $this->config->tiebreak_on_raw_penalty ) {
			return $penalty;
		}

		return $penalty * $this->config->factor_for_course( (string) ( $row['course_label'] ?? '60' ) );
	}

	/**
	 * Assign positions and league points in place.
	 *
	 * This is the club's own formula, kept in its original shape:
	 *
	 *   position = count(total > mine)
	 *            + 1
	 *            + count(total == mine AND penalty < mine)
	 *
	 * Equal totals finish equal and are separated only by time penalty — never
	 * by finishing time, which is unusual but is what the rules say. Whoever
	 * carries the larger penalty is demoted below an equal total with a
	 * cleaner run.
	 *
	 * Quadratic in the number of runners, which for a field of sixty is
	 * irrelevant, and being able to read it against the spreadsheet formula is
	 * worth more here than an asymptotically better version.
	 *
	 * @param array<int,array<string,mixed>> $rows Scored rows, modified in place.
	 */
	private function assign_positions( array &$rows ): void {
		$ranked = array();
		foreach ( $rows as $index => $row ) {
			if ( null !== $row['total'] ) {
				$ranked[ $index ] = array(
					'total'   => $row['total'],
					'penalty' => $this->tiebreak_penalty( $row ),
				);
			}
		}

		foreach ( $ranked as $index => $mine ) {
			$better = 0;
			$ahead  = 0;

			foreach ( $ranked as $other ) {
				if ( $other['total'] > $mine['total'] ) {
					++$better;
				} elseif ( $other['total'] === $mine['total'] && $other['penalty'] < $mine['penalty'] ) {
					++$ahead;
				}
			}

			$position = $better + 1 + $ahead;

			$rows[ $index ]['position']       = $position;
			$rows[ $index ]['position_label'] = self::ordinal( $position );
			$rows[ $index ]['league_points']  = $this->config->points_for_position( $position );
		}
	}

	/**
	 * Render a position as an English ordinal: 1st, 2nd, 3rd, 4th, 11th, 21st.
	 *
	 * @param int $number Position.
	 */
	public static function ordinal( int $number ): string {
		$absolute = abs( $number );
		$last_two = $absolute % 100;

		if ( $last_two > 10 && $last_two < 14 ) {
			return $number . 'th';
		}

		switch ( $absolute % 10 ) {
			case 1:
				return $number . 'st';
			case 2:
				return $number . 'nd';
			case 3:
				return $number . 'rd';
			default:
				return $number . 'th';
		}
	}
}
