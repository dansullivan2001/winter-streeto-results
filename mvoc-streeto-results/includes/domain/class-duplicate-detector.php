<?php
/**
 * Finds result rows that are the same run recorded more than once.
 *
 * MapRun regularly returns duplicates. Two causes appear in the real data:
 *
 *   Failed uploads - a `--` row with no time, score or punches, sitting
 *   alongside the runner's real result.
 *
 *   Course revisions - the same run scored against two versions of the course,
 *   distinguished only by a "(RevNN)" suffix on the surname, and carrying
 *   *different scores*. In a real response one runner's two rows shared a
 *   start of 18:35:41, a finish of 19:29:04 and 3203 seconds exactly, but
 *   scored 760 and 730.
 *
 * The detector clusters on start, finish and elapsed time rather than on the
 * name suffix: those three matching exactly is far stronger evidence of one run
 * than punctuation in a surname, and it also catches duplicates MapRun has not
 * labelled. Nothing is auto-selected — which score is authoritative is a
 * judgement the co-ordinator makes, not one to guess.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Groups parsed result rows into duplicate clusters.
 */
class Duplicate_Detector {

	/**
	 * Find clusters of rows that look like one run recorded twice.
	 *
	 * Failed uploads are ignored: they are excluded from scoring anyway, so
	 * including them would manufacture decisions the co-ordinator does not need
	 * to make. In the real 64-row event that alone reduces six apparent
	 * duplicates to a single genuine one.
	 *
	 * @param array<int,array<string,mixed>> $rows Parsed rows from the Parser.
	 * @return array<int,array<int,array<string,mixed>>> One inner array per cluster of 2+.
	 */
	public function find( array $rows ): array {
		$groups = array();

		foreach ( $rows as $row ) {
			if ( ! empty( $row['is_failed'] ) ) {
				continue;
			}

			$key = self::signature( $row );
			if ( null === $key ) {
				continue;
			}

			$groups[ $key ][] = $row;
		}

		$clusters = array();
		foreach ( $groups as $group ) {
			if ( count( $group ) > 1 ) {
				$clusters[] = $group;
			}
		}

		return $clusters;
	}

	/**
	 * The identity of a run: who, started when, finished when, over how long.
	 *
	 * Returns null where there is not enough to compare — a row with no elapsed
	 * time cannot be matched this way, and guessing from the name alone would
	 * be worse than leaving it to the review screen.
	 *
	 * @param array<string,mixed> $row Parsed row.
	 */
	private static function signature( array $row ): ?string {
		$time = $row['time_secs'] ?? null;
		if ( ! is_int( $time ) || $time <= 0 ) {
			return null;
		}

		$start  = trim( (string) ( $row['start_local'] ?? '' ) );
		$finish = trim( (string) ( $row['finish_local'] ?? '' ) );
		if ( '' === $start || '' === $finish ) {
			return null;
		}

		// The name is part of the signature so that two runners who genuinely
		// started and finished together - which happens at every event, since
		// people run in pairs - are never merged into one result.
		$name = self::normalise_name( $row );

		return $name . '|' . $start . '|' . $finish . '|' . $time;
	}

	/**
	 * Casefolded first name and surname, with the revision suffix already gone.
	 *
	 * @param array<string,mixed> $row Parsed row.
	 */
	private static function normalise_name( array $row ): string {
		$name = trim( (string) ( $row['first_name'] ?? '' ) ) . ' ' . trim( (string) ( $row['surname'] ?? '' ) );

		return strtolower( (string) preg_replace( '/\s+/', ' ', trim( $name ) ) );
	}

	/**
	 * Describe a cluster for the review screen.
	 *
	 * `options` is what the screen renders, so it carries the result id the
	 * co-ordinator's choice is submitted as. Rendering the cluster directly
	 * instead would silently drop the ordering below.
	 *
	 * @param array<int,array<string,mixed>> $cluster One duplicate cluster.
	 * @return array{name:string,time_display:string,options:array<int,array<string,mixed>>}
	 */
	public function describe( array $cluster ): array {
		$first = $cluster[0] ?? array();

		$options = array();
		foreach ( $cluster as $row ) {
			$options[] = array(
				'result_id'   => $row['result_id'] ?? null,
				'maprun_id'   => $row['maprun_id'] ?? '',
				'revision'    => $row['course_revision'] ?? null,
				'score'       => $row['score'] ?? null,
				'penalty'     => $row['penalty'] ?? 0,
				// MapRun's own net score where a parsed row carries one;
				// otherwise the same subtraction the event table publishes.
				'net_score'   => $row['net_score'] ?? self::net_of( $row ),
				'is_excluded' => ! empty( $row['is_excluded'] ),
			);
		}

		// Highest revision first: the co-ordinator still chooses, but a later
		// course revision is the likelier candidate and is worth showing first.
		usort(
			$options,
			static fn( array $a, array $b ): int => ( $b['revision'] ?? -1 ) <=> ( $a['revision'] ?? -1 )
		);

		return array(
			'name'         => (string) ( $first['display_name'] ?? '' ),
			'time_display' => (string) ( $first['time_display'] ?? '' ),
			'options'      => $options,
			'is_decided'   => self::is_decided( $options ),
		);
	}

	/**
	 * Whether a cluster has already been answered.
	 *
	 * Answered means at most one option still counts: the co-ordinator kept one
	 * and excluded the rest, or judged the whole cluster junk and excluded all
	 * of it. Untouched, every option is still in, which is the one state that
	 * genuinely needs a decision.
	 *
	 * @param array<int,array<string,mixed>> $options Options from describe().
	 */
	private static function is_decided( array $options ): bool {
		$excluded = 0;
		foreach ( $options as $option ) {
			if ( ! empty( $option['is_excluded'] ) ) {
				++$excluded;
			}
		}

		return $excluded > 0 && ( count( $options ) - $excluded ) <= 1;
	}

	/**
	 * Turn the co-ordinator's picks into which rows count and which do not.
	 *
	 * Kept ids are returned as well as excluded ones, and both matter: a choice
	 * is a statement about the whole cluster, so keeping a row has to be able to
	 * *clear* an exclusion, not only add one. Without that, changing your mind
	 * excludes the newly rejected row while the newly kept one stays excluded
	 * from the previous decision — and the runner disappears from the event
	 * altogether.
	 *
	 * A pick that matches no cluster is ignored rather than trusted: the ids
	 * arrive from a form and only the clusters say which are real.
	 *
	 * @param array<int,array<int,array<string,mixed>>> $clusters Clusters from find().
	 * @param int[]                                     $chosen   Result ids the co-ordinator picked.
	 * @return array{kept:int[],excluded:int[]}
	 */
	public static function resolve( array $clusters, array $chosen ): array {
		$kept     = array();
		$excluded = array();

		foreach ( $clusters as $cluster ) {
			$ids  = array_map( 'intval', array_column( $cluster, 'result_id' ) );
			$pick = array_values( array_intersect( $ids, $chosen ) );

			// Only act on a cluster the co-ordinator actually answered. An
			// unanswered one leaves both rows to the Exclude boxes, which is
			// how a cluster that is junk all through gets excluded entirely.
			if ( ! $pick ) {
				continue;
			}

			$kept     = array_merge( $kept, $pick );
			$excluded = array_merge( $excluded, array_values( array_diff( $ids, $pick ) ) );
		}

		return array(
			'kept'     => $kept,
			'excluded' => $excluded,
		);
	}

	/**
	 * Whether a row ends up excluded, given a cluster decision and a checkbox.
	 *
	 * The two can contradict each other, and which wins is the whole of the
	 * bug this exists to prevent. A row excluded by an earlier decision renders
	 * with its Exclude box ticked; when the co-ordinator then picks *that* row
	 * as the one to keep, the stale tick comes back with the form. Reading the
	 * two as "excluded if either says so" leaves both rows of the cluster
	 * excluded and the runner absent from the event.
	 *
	 * So a cluster decision settles every row in that cluster, and the checkbox
	 * governs only the rows no decision covers.
	 *
	 * @param int                              $result_id Row being saved.
	 * @param bool                             $ticked    Whether its Exclude box came back ticked.
	 * @param array{kept:int[],excluded:int[]} $resolved  Output of resolve().
	 */
	public static function is_excluded( int $result_id, bool $ticked, array $resolved ): bool {
		if ( in_array( $result_id, $resolved['kept'] ?? array(), true ) ) {
			return false;
		}

		if ( in_array( $result_id, $resolved['excluded'] ?? array(), true ) ) {
			return true;
		}

		return $ticked;
	}

	/**
	 * Score less penalty, or null where there is no score to work from.
	 *
	 * @param array<string,mixed> $row Result row.
	 */
	private static function net_of( array $row ): ?int {
		if ( ! isset( $row['score'] ) || ! is_numeric( $row['score'] ) ) {
			return null;
		}

		return (int) $row['score'] - (int) ( $row['penalty'] ?? 0 );
	}
}
