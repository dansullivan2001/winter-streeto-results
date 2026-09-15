<?php
/**
 * Finds a runner who has more than one result scoring at one event.
 *
 * Not the same question as Duplicate_Detector's, and the difference is why
 * this exists. That one asks "is this one run recorded twice?" and answers it
 * from an exact match on name, start, finish and elapsed time — deliberately
 * strict, because merging two runners who set off together would be worse than
 * missing a duplicate. This one asks "is this one runner scoring twice?", which
 * is true in cases that signature cannot reach:
 *
 *   A real run and a stray. A phone left recording, a course opened and
 *   abandoned, a DNF: a second row with a different start and a tiny or zero
 *   time, carrying a numeric score of 0. No two fields match, so the signature
 *   never groups them — and a score of 0 is still a score, so the row ranks.
 *
 *   Two real runs seconds apart. A course scored against two revisions can
 *   finish at different times: one real event had 830 and 730 for the same
 *   runner, fourteen seconds apart on the same start. The strict signature
 *   requires the elapsed time to match exactly, so it saw nothing.
 *
 * Both rank. Both take a position. Both reach the published table, and the
 * league quietly keeps the better of the two — a silent decision nobody made,
 * on a table that shows the runner twice.
 *
 * Nothing is auto-excluded. Which row is the real run is a judgement from the
 * night: the highest score is the obvious guess and the wrong one often enough
 * — a failed second attempt can outscore a completed first — that guessing
 * would be worse than pointing.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Groups scoring rows by the runner they belong to.
 */
class Repeat_Entry_Detector {

	/**
	 * Find runners carrying more than one scoring row.
	 *
	 * @param array<int,array<string,mixed>> $rows Effective result rows.
	 * @return array<int,array<int,array<string,mixed>>> One inner array per runner with 2+.
	 */
	public function find( array $rows ): array {
		return array_values(
			array_filter( self::counting_by_runner( $rows ), static fn( array $group ): bool => count( $group ) > 1 )
		);
	}

	/**
	 * For every row, the runner's *other* rows that are scoring.
	 *
	 * Answers the question the co-ordinator is actually asking when they look
	 * at an excluded row: is this person already in the table with a result
	 * that counts? Fifty rows in, that cannot be held in the head, and the
	 * consequence of guessing wrong is un-excluding a row that then scores
	 * twice — which is how the excluded list gets undone one plausible-looking
	 * row at a time.
	 *
	 * Covers every row, not just the scoring ones, because an excluded row is
	 * precisely where the answer is needed. A row is never its own company:
	 * its own id is removed, so an empty list means what it says.
	 *
	 * @param array<int,array<string,mixed>> $rows Effective result rows.
	 * @return array<int,array<int,array<string,mixed>>> Result id => that runner's other scoring rows.
	 */
	public function elsewhere( array $rows ): array {
		$counting = self::counting_by_runner( $rows );
		$map      = array();

		foreach ( $rows as $row ) {
			$id  = (int) $row['result_id'];
			$key = self::identity( $row );

			$map[ $id ] = array_values(
				array_filter(
					null === $key ? array() : ( $counting[ $key ] ?? array() ),
					static fn( array $other ): bool => (int) $other['result_id'] !== $id
				)
			);
		}

		return $map;
	}

	/**
	 * The scoring rows, grouped by the runner they belong to.
	 *
	 * @param array<int,array<string,mixed>> $rows Effective result rows.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function counting_by_runner( array $rows ): array {
		$groups = array();

		foreach ( $rows as $row ) {
			if ( ! self::counts( $row ) ) {
				continue;
			}

			$key = self::identity( $row );

			if ( null === $key ) {
				continue;
			}

			$groups[ $key ][] = $row;
		}

		return $groups;
	}

	/**
	 * The result ids caught up in a clash, for the screen to mark.
	 *
	 * @param array<int,array<string,mixed>> $rows Effective result rows.
	 * @return array<int,bool> Result id => true.
	 */
	public function clashing_ids( array $rows ): array {
		$ids = array();

		foreach ( $this->find( $rows ) as $group ) {
			foreach ( $group as $row ) {
				$ids[ (int) $row['result_id'] ] = true;
			}
		}

		return $ids;
	}

	/**
	 * Whether this row is one of the ones competing for the runner's place.
	 *
	 * An excluded row is the answer to this warning, not a case of it, and a
	 * withdrawn row is not in the latest import at all. A row with no numeric
	 * score cannot rank, so two of them are not a clash however many there are.
	 *
	 * @param array<string,mixed> $row Effective result row.
	 */
	private static function counts( array $row ): bool {
		if ( ! empty( $row['is_excluded'] ) || ! empty( $row['is_withdrawn'] ) ) {
			return false;
		}

		return isset( $row['score'] ) && is_numeric( $row['score'] );
	}

	/**
	 * Who a row belongs to: the confirmed competitor, or failing that the name.
	 *
	 * The competitor is the certain answer and is used wherever there is one.
	 * The name is the fallback, and it earns its place at exactly the moment
	 * the warning is most useful: on a fresh import nothing is linked yet, so a
	 * competitor-only rule would stay silent through the whole of the screen
	 * where the confusion actually happens — confirming a name for someone who
	 * turns out to have three rows.
	 *
	 * Two runners who genuinely share a name are grouped until they are linked,
	 * and then never again: two rows with different competitor ids can no
	 * longer collide, whatever they are called. The false positive corrects
	 * itself by the co-ordinator doing the thing the screen was asking for.
	 *
	 * @param array<string,mixed> $row Effective result row.
	 */
	private static function identity( array $row ): ?string {
		$competitor = (int) ( $row['competitor_id'] ?? 0 );

		if ( $competitor > 0 ) {
			return 'competitor:' . $competitor;
		}

		$name = strtolower(
			(string) preg_replace(
				'/\s+/',
				' ',
				trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['surname'] ?? '' ) )
			)
		);

		return '' === $name ? null : 'name:' . $name;
	}

	/**
	 * Describe a clash for the review screen: the runner, and how many rows.
	 *
	 * @param array<int,array<string,mixed>> $group One runner's scoring rows.
	 * @return array{name:string,count:int,result_ids:int[]}
	 */
	public static function describe( array $group ): array {
		return array(
			'name'       => (string) ( $group[0]['display_name'] ?? '' ),
			'count'      => count( $group ),
			'result_ids' => array_map( static fn( array $row ): int => (int) $row['result_id'], $group ),
		);
	}
}
