<?php
/**
 * Persistence for competitors and their confirmed name aliases.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Repo;

use MVOC\StreetO\Domain\Name_Matcher;
use MVOC\StreetO\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the competitors and aliases tables.
 */
class Competitors_Repo {

	/**
	 * The columns this repo writes, in the order the format specifiers expect.
	 *
	 * Declared rather than inlined so SchemaConsistencyTest can check every one
	 * of them exists in the table. That test was added after this repo spent a
	 * milestone writing a `year_of_birth` column the schema did not have.
	 *
	 * @var string[]
	 */
	public const COLUMNS = array(
		'first_name',
		'surname',
		'display_name',
		'club',
		'is_female',
	);

	/**
	 * Format specifiers matching COLUMNS, for $wpdb.
	 *
	 * @var string[]
	 */
	private const FORMATS = array( '%s', '%s', '%s', '%s', '%d' );

	/**
	 * Every competitor, as plain arrays for the domain classes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		global $wpdb;

		$table = Schema::table( 'competitors' );
		// Table name comes from a hard-coded constant, not from user input.
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY surname, first_name", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * Confirmed aliases as a normalised-name => competitor id map.
	 *
	 * @return array<string,int>
	 */
	public function aliases(): array {
		global $wpdb;

		$table = Schema::table( 'aliases' );
		$rows  = $wpdb->get_results( "SELECT normalised_name, competitor_id FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$map = array();
		foreach ( $rows ?: array() as $row ) {
			$map[ (string) $row['normalised_name'] ] = (int) $row['competitor_id'];
		}

		return $map;
	}

	/**
	 * Cast a database row to the types the domain classes expect.
	 *
	 * $wpdb returns everything as strings, and the matcher compares years and
	 * flags strictly, so casting here keeps that from becoming a subtle bug.
	 *
	 * @param array<string,mixed> $row Raw database row.
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		$row['id']        = (int) $row['id'];
		$row['is_female'] = (bool) $row['is_female'];

		// Over-55 belongs to a season, not to a person, so it is merged in by
		// whoever knows which season they are asking about.
		$row['is_over55'] = false;

		return $row;
	}

	/**
	 * Create a competitor and return its id.
	 *
	 * @param array<string,mixed> $competitor Competitor fields.
	 */
	public function create( array $competitor ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'competitors' ),
			$this->to_columns( $competitor ),
			self::FORMATS
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing competitor.
	 *
	 * @param int                 $id         Competitor id.
	 * @param array<string,mixed> $competitor Fields to write.
	 */
	public function update( int $id, array $competitor ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'competitors' ),
			$this->to_columns( $competitor ),
			array( 'id' => $id ),
			self::FORMATS,
			array( '%d' )
		);
	}

	/**
	 * Map a competitor array onto its database columns.
	 *
	 * @param array<string,mixed> $competitor Competitor fields.
	 * @return array<string,mixed>
	 */
	private function to_columns( array $competitor ): array {
		$first   = (string) ( $competitor['first_name'] ?? '' );
		$surname = (string) ( $competitor['surname'] ?? '' );

		$values = array(
			'first_name'   => $first,
			'surname'      => $surname,
			'display_name' => (string) ( $competitor['display_name'] ?? trim( $first . ' ' . $surname ) ),
			'club'         => (string) ( $competitor['club'] ?? '' ),
			'is_female'    => ! empty( $competitor['is_female'] ) ? 1 : 0,
		);

		// Keyed by COLUMNS so the declared contract and what is actually
		// written cannot drift apart.
		return array_replace( array_fill_keys( self::COLUMNS, null ), $values );
	}

	/**
	 * Everyone marked Over-55 for a season, as competitor id => true.
	 *
	 * @param int $series_id Series id.
	 * @return array<int,bool>
	 */
	public function over55_for_series( int $series_id ): array {
		global $wpdb;

		$table = Schema::table( 'series_competitors' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT competitor_id, is_over55 FROM `{$table}` WHERE series_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$series_id
			),
			ARRAY_A
		);

		$flags = array();
		foreach ( $rows ?: array() as $row ) {
			$flags[ (int) $row['competitor_id'] ] = (bool) $row['is_over55'];
		}

		return $flags;
	}

	/**
	 * Set a competitor's category for one season.
	 *
	 * @param int  $series_id     Series id.
	 * @param int  $competitor_id Competitor id.
	 * @param bool $is_over55     Whether they are Over-55 that season.
	 */
	public function set_over55( int $series_id, int $competitor_id, bool $is_over55 ): void {
		global $wpdb;

		$table = Schema::table( 'series_competitors' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO `{$table}` (series_id, competitor_id, is_over55) VALUES (%d, %d, %d)
				 ON DUPLICATE KEY UPDATE is_over55 = VALUES(is_over55)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$series_id,
				$competitor_id,
				$is_over55 ? 1 : 0
			)
		);
	}

	/**
	 * Competitors with a season's category flags merged in.
	 *
	 * @param int $series_id Series id.
	 * @return array<int,array<string,mixed>>
	 */
	public function all_for_series( int $series_id ): array {
		$flags = $this->over55_for_series( $series_id );

		return array_map(
			static function ( array $competitor ) use ( $flags ): array {
				$competitor['is_over55'] = $flags[ $competitor['id'] ] ?? false;

				return $competitor;
			},
			$this->all()
		);
	}

	/**
	 * Record a confirmed name spelling against a competitor.
	 *
	 * Aliases are unique on the normalised name, so re-confirming the same
	 * spelling is harmless. Re-pointing one at a different competitor is how a
	 * mistaken confirmation gets corrected.
	 *
	 * @param string $alias_key     Normalised "first surname".
	 * @param int    $competitor_id Competitor id.
	 */
	public function link_alias( string $alias_key, int $competitor_id ): void {
		global $wpdb;

		$table = Schema::table( 'aliases' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO `{$table}` (competitor_id, normalised_name) VALUES (%d, %s)
				 ON DUPLICATE KEY UPDATE competitor_id = VALUES(competitor_id)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$competitor_id,
				$alias_key
			)
		);
	}

	/**
	 * Create a competitor from a queue entry and link the name that produced it.
	 *
	 * @param array<string,mixed> $competitor Competitor fields.
	 * @param string              $alias_key  Normalised name to link.
	 */
	public function create_with_alias( array $competitor, string $alias_key ): int {
		$id = $this->create( $competitor );
		$this->link_alias( $alias_key, $id );

		return $id;
	}

	/**
	 * A competitor id for a typed name: an existing match, or a new record.
	 *
	 * Used wherever a co-ordinator types a name rather than picking from a
	 * list — an organiser, most often, since they rarely exist as a competitor
	 * before the first event has been imported. Reuses an existing competitor
	 * with that name rather than making a second one, which would split their
	 * league points; the alias recorded here is what makes them match
	 * automatically once they next appear in MapRun results.
	 *
	 * @param string $typed Name as typed, already sanitized.
	 */
	public function resolve_or_create_by_name( string $typed ): int {
		$parts   = preg_split( '/\s+/', trim( $typed ), 2 );
		$first   = $parts[0] ?? '';
		$surname = $parts[1] ?? '';

		$alias_key = Name_Matcher::alias_key( $first, $surname );

		$aliases = $this->aliases();
		if ( isset( $aliases[ $alias_key ] ) ) {
			return (int) $aliases[ $alias_key ];
		}

		return $this->create_with_alias(
			array(
				'first_name'   => $first,
				'surname'      => $surname,
				'display_name' => $typed,
			),
			$alias_key
		);
	}

	/**
	 * Merge one competitor into another, moving aliases and results across.
	 *
	 * Used when the co-ordinator spots that two records are the same person —
	 * for example after an earlier confirmation went to the wrong entry.
	 *
	 * @param int $from_id Competitor to absorb.
	 * @param int $into_id Competitor to keep.
	 */
	public function merge( int $from_id, int $into_id ): void {
		global $wpdb;

		if ( $from_id === $into_id ) {
			return;
		}

		// All three tables reference a competitor by the same column name.
		$tables = array(
			Schema::table( 'aliases' ),
			Schema::table( 'results' ),
			Schema::table( 'result_competitors' ),
		);

		// The absorbed competitor's per-season categories go first: the unique
		// key on (series, competitor) would otherwise collide where both
		// records have a row for the same season.
		$series_competitors = Schema::table( 'series_competitors' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM `{$series_competitors}` WHERE competitor_id = %d
				 AND series_id IN ( SELECT series_id FROM ( SELECT series_id FROM `{$series_competitors}` WHERE competitor_id = %d ) AS keep )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from_id,
				$into_id
			)
		);
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$series_competitors,
			array( 'competitor_id' => $into_id ),
			array( 'competitor_id' => $from_id ),
			array( '%d' ),
			array( '%d' )
		);

		foreach ( $tables as $table ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE `{$table}` SET competitor_id = %d WHERE competitor_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$into_id,
					$from_id
				)
			);
		}

		$wpdb->delete( Schema::table( 'competitors' ), array( 'id' => $from_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Normalised alias key for a competitor's own name.
	 *
	 * @param array<string,mixed> $competitor Competitor fields.
	 */
	public static function alias_key_for( array $competitor ): string {
		return Name_Matcher::alias_key(
			(string) ( $competitor['first_name'] ?? '' ),
			(string) ( $competitor['surname'] ?? '' )
		);
	}
}
