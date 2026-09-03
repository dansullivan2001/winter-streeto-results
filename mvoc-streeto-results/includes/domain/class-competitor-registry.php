<?php
/**
 * Resolves parsed MapRun rows to canonical competitors.
 *
 * The registry is what replaces the spreadsheet's "League Check" column, where
 * the co-ordinator counted each name's occurrences by eye to spot mismatches.
 * Here, a name that has been confirmed once is stored as an alias and resolves
 * silently ever after; a name that has not is queued with ranked suggestions.
 *
 * Nothing is ever auto-merged on a guess. A wrong merge hands one runner
 * another's league points, which is worse than asking — and asking costs one
 * click, once per spelling, per season.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Turns parsed rows into resolved rows plus a queue of names to confirm.
 */
class Competitor_Registry {

	private Name_Matcher $matcher;

	private Scoring_Config $config;

	/**
	 * @param Name_Matcher|null   $matcher Name matcher.
	 * @param Scoring_Config|null $config  Series scoring rules, for category derivation.
	 */
	public function __construct( ?Name_Matcher $matcher = null, ?Scoring_Config $config = null ) {
		$this->matcher = $matcher ?? new Name_Matcher();
		$this->config  = $config ?? new Scoring_Config();
	}

	/**
	 * Resolve a set of parsed rows against the known competitors.
	 *
	 * Aliases map a normalised "first surname" key to a competitor id, and are
	 * written only when a human confirms a match.
	 *
	 * @param array<int,array<string,mixed>> $rows        Parsed MapRun rows.
	 * @param array<int,array<string,mixed>> $competitors Known competitors, each with an `id`.
	 * @param array<string,int>              $aliases     Normalised name => competitor id.
	 * @return array{rows:array<int,array<string,mixed>>,unmatched:array<int,array<string,mixed>>}
	 */
	public function resolve( array $rows, array $competitors, array $aliases ): array {
		$by_id     = array_column( $competitors, null, 'id' );
		$resolved  = array();
		$unmatched = array();

		foreach ( $rows as $row ) {
			$key = Name_Matcher::alias_key(
				(string) ( $row['first_name'] ?? '' ),
				(string) ( $row['surname'] ?? '' )
			);

			$competitor_id = $aliases[ $key ] ?? null;

			$row['alias_key']     = $key;
			$row['competitor_id'] = null;
			$row['competitor']    = null;

			if ( null !== $competitor_id && isset( $by_id[ $competitor_id ] ) ) {
				$row['competitor_id'] = $competitor_id;
				$row['competitor']    = $by_id[ $competitor_id ];
				$resolved[]           = $row;
				continue;
			}

			$resolved[]  = $row;
			$unmatched[] = $this->queue_entry( $row, $competitors );
		}

		return array(
			'rows'      => $resolved,
			'unmatched' => self::deduplicate_queue( $unmatched ),
		);
	}

	/**
	 * Build one entry for the unmatched-names queue.
	 *
	 * @param array<int|string,mixed>        $row         Parsed row.
	 * @param array<int,array<string,mixed>> $competitors Known competitors.
	 * @return array<string,mixed>
	 */
	private function queue_entry( array $row, array $competitors ): array {
		$suggestions = $this->matcher->rank( $row, $competitors );

		return array(
			'alias_key'    => $row['alias_key'],
			'first_name'   => (string) ( $row['first_name'] ?? '' ),
			'surname'      => (string) ( $row['surname'] ?? '' ),
			'display_name' => (string) ( $row['display_name'] ?? '' ),
			'club'         => (string) ( $row['club'] ?? '' ),
			'gender'       => (string) ( $row['gender'] ?? '' ),
			'is_over55'    => $this->derive_over55( $row ),
			'suggestions'  => $suggestions,
			'has_strong_suggestion' => (bool) array_filter(
				$suggestions,
				static fn( array $s ): bool => $s['score'] >= Name_Matcher::STRONG_THRESHOLD
			),
			'proposed'     => $this->propose_competitor( $row ),
		);
	}

	/**
	 * The competitor record that would be created if this name is new.
	 *
	 * Both category flags come from MapRun — Ladies from Gender, Over-55 from
	 * YearOfBirth — so the co-ordinator confirms rather than classifies. They
	 * stay editable, because MapRun's values are self-declared and occasionally
	 * wrong.
	 *
	 * @param array<string,mixed> $row Parsed row.
	 * @return array<string,mixed>
	 */
	public function propose_competitor( array $row ): array {
		return array(
			'first_name'   => (string) ( $row['first_name'] ?? '' ),
			'surname'      => (string) ( $row['surname'] ?? '' ),
			'display_name' => (string) ( $row['display_name'] ?? '' ),
			'club'         => (string) ( $row['club'] ?? '' ),
			'is_female'    => 'F' === ( $row['gender'] ?? '' ),
			// Per season, not on the competitor: everybody's age changes every
			// year, so a single flag would reclassify past seasons.
			'is_over55'    => $this->derive_over55( $row ),
		);
	}

	/**
	 * Whether a row implies Over-55, from whichever source is available.
	 *
	 * A freshly parsed row still carries MapRun's year of birth, which is used
	 * here and then discarded. A row read back from the database carries only
	 * the flag that was derived when it was imported, because the year is never
	 * stored.
	 *
	 * @param array<string,mixed> $row Parsed or stored row.
	 */
	private function derive_over55( array $row ): bool {
		if ( array_key_exists( 'is_over55', $row ) && null !== $row['is_over55'] ) {
			return (bool) $row['is_over55'];
		}

		$year = $row['year_of_birth'] ?? null;

		return $this->config->is_over55( is_numeric( $year ) ? (int) $year : null );
	}

	/**
	 * Collapse repeated appearances of the same name into one queue entry.
	 *
	 * A runner with two uploads should not be two decisions.
	 *
	 * @param array<int,array<string,mixed>> $queue Raw queue entries.
	 * @return array<int,array<string,mixed>>
	 */
	private static function deduplicate_queue( array $queue ): array {
		$seen = array();

		foreach ( $queue as $entry ) {
			$key = $entry['alias_key'];

			// Keep the richest version: a later row may carry a club or year of
			// birth that an earlier one left blank.
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = $entry;
				continue;
			}

			foreach ( array( 'club', 'gender', 'is_over55' ) as $field ) {
				if ( empty( $seen[ $key ][ $field ] ) && ! empty( $entry[ $field ] ) ) {
					$seen[ $key ][ $field ] = $entry[ $field ];
				}
			}
		}

		return array_values( $seen );
	}

	/**
	 * Category flags for an existing competitor, refreshed from a MapRun row.
	 *
	 * Returns only the flags that would change, so the caller can show the
	 * co-ordinator what MapRun now disagrees with rather than overwriting a
	 * deliberate correction.
	 *
	 * @param array<string,mixed> $competitor Stored competitor.
	 * @param array<string,mixed> $row        Parsed row.
	 * @return array<string,mixed> Changed fields, empty when nothing differs.
	 */
	public function category_drift( array $competitor, array $row ): array {
		$drift = array();

		$row_female = 'F' === ( $row['gender'] ?? '' );
		if ( '' !== ( $row['gender'] ?? '' ) && (bool) ( $competitor['is_female'] ?? false ) !== $row_female ) {
			$drift['is_female'] = $row_female;
		}

		$has_age = array_key_exists( 'is_over55', $row ) && null !== $row['is_over55'];
		$has_age = $has_age || is_numeric( $row['year_of_birth'] ?? null );

		if ( $has_age ) {
			$row_over55 = $this->derive_over55( $row );

			if ( (bool) ( $competitor['is_over55'] ?? false ) !== $row_over55 ) {
				$drift['is_over55'] = $row_over55;
			}
		}

		return $drift;
	}
}
