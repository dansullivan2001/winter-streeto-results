<?php
/**
 * The four competitions run off one set of results.
 *
 * Overall, Ladies, Over-55 Men and Over-55 Women are the same ranking applied
 * to different subsets, and three separate places used to say so: the league
 * ranked them, the league table labelled them, and nothing else knew about
 * them at all. Now the event table ranks them too, so the definition lives
 * here once — who is in a category, what its ranking column is called, and
 * which field carries the position — and adding a fifth still costs one entry.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the competitions and who belongs to each.
 */
class Categories {

	/**
	 * The category everybody is in, ranked in the plain `position` field.
	 */
	public const OVERALL = 'overall';

	/**
	 * Category => the row field carrying that category's position.
	 *
	 * @return array<string,string>
	 */
	public static function fields(): array {
		return array(
			self::OVERALL => 'position',
			'ladies'      => 'ladies_position',
			'o55_men'     => 'o55_men_position',
			'o55_women'   => 'o55_women_position',
		);
	}

	/**
	 * Category => who qualifies for it.
	 *
	 * Predicates rather than flag names because the Over-55 titles are awarded
	 * separately to a man and a woman, which no single flag can express.
	 *
	 * @return array<string,callable(array<string,mixed>):bool>
	 */
	public static function predicates(): array {
		return array(
			self::OVERALL => static fn( array $row ): bool => true,
			'ladies'      => static fn( array $row ): bool => ! empty( $row['is_female'] ),
			'o55_men'     => static fn( array $row ): bool => ! empty( $row['is_over55'] ) && empty( $row['is_female'] ),
			'o55_women'   => static fn( array $row ): bool => ! empty( $row['is_over55'] ) && ! empty( $row['is_female'] ),
		);
	}

	/**
	 * Full names, for a heading that says which competition is being shown.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			self::OVERALL => 'Overall',
			'ladies'      => 'Ladies',
			'o55_men'     => 'Over 55 Men',
			'o55_women'   => 'Over 55 Women',
		);
	}

	/**
	 * Short headings for the ranking columns shown beside the overall position.
	 *
	 * Overall is absent: its column is headed "Pos" and comes first.
	 *
	 * @return array<string,string>
	 */
	public static function columns(): array {
		return array(
			'ladies'    => 'Ladies',
			'o55_men'   => 'M55',
			'o55_women' => 'W55',
		);
	}

	/**
	 * Whether a category name is one of these four.
	 *
	 * @param string $category Category key.
	 */
	public static function exists( string $category ): bool {
		return isset( self::fields()[ $category ] );
	}

	/**
	 * Every category key, in display order.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array_keys( self::fields() );
	}

	/**
	 * Every category ranking held by one row, keyed by category.
	 *
	 * Null where the row is not in that category — which is what lets a table
	 * leave the cell blank rather than imply a position nobody holds.
	 *
	 * @param array<string,mixed> $row A row carrying the position fields.
	 * @return array<string,int|null>
	 */
	public static function positions_of( array $row ): array {
		$positions = array();

		foreach ( self::fields() as $category => $field ) {
			$value = $row[ $field ] ?? null;

			$positions[ $category ] = null === $value ? null : (int) $value;
		}

		return $positions;
	}

	/**
	 * Merge each competitor's category flags onto the rows that belong to them.
	 *
	 * Results arrive from the results table, which records what MapRun said
	 * about a runner at one event; the categories are a fact about the person
	 * and their season, held against the competitor. A row with nobody
	 * confirmed against it gets neither flag, so it is ranked overall and in no
	 * category — the same treatment it already gets in the league, where an
	 * unconfirmed name is absent from the standings entirely.
	 *
	 * @param array<int,array<string,mixed>>                               $rows  Result rows carrying `competitor_id`.
	 * @param array<int,array{is_female:bool,is_over55:bool}>              $flags Competitor id => their flags.
	 * @return array<int,array<string,mixed>>
	 */
	public static function apply( array $rows, array $flags ): array {
		return array_map(
			static function ( array $row ) use ( $flags ): array {
				$id      = $row['competitor_id'] ?? null;
				$theirs  = null === $id ? array() : ( $flags[ (int) $id ] ?? array() );

				$row['is_female'] = ! empty( $theirs['is_female'] );
				$row['is_over55'] = ! empty( $theirs['is_over55'] );

				return $row;
			},
			$rows
		);
	}
}
