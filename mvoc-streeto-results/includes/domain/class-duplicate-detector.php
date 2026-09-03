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
	 * @param array<int,array<string,mixed>> $cluster One duplicate cluster.
	 * @return array{name:string,time_display:string,options:array<int,array<string,mixed>>}
	 */
	public function describe( array $cluster ): array {
		$first = $cluster[0] ?? array();

		$options = array();
		foreach ( $cluster as $row ) {
			$options[] = array(
				'maprun_id' => $row['maprun_id'] ?? '',
				'revision'  => $row['course_revision'] ?? null,
				'score'     => $row['score'] ?? null,
				'penalty'   => $row['penalty'] ?? 0,
				'net_score' => $row['net_score'] ?? null,
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
		);
	}
}
