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
		$row['id']            = (int) $row['id'];
		$row['is_female']     = (bool) $row['is_female'];
		$row['is_over55']     = (bool) $row['is_over55'];
		$row['year_of_birth'] = $row['year_of_birth'] ? (int) $row['year_of_birth'] : null;

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
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
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
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d' ),
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

		return array(
			'first_name'    => $first,
			'surname'       => $surname,
			'display_name'  => (string) ( $competitor['display_name'] ?? trim( $first . ' ' . $surname ) ),
			'club'          => (string) ( $competitor['club'] ?? '' ),
			'year_of_birth' => (int) ( $competitor['year_of_birth'] ?? 0 ),
			'is_female'     => ! empty( $competitor['is_female'] ) ? 1 : 0,
			'is_over55'     => ! empty( $competitor['is_over55'] ) ? 1 : 0,
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
