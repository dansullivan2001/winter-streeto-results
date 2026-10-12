<?php
/**
 * Persistence for the library of custom scoring definitions.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Repo;

use MVOC\StreetO\Domain\Scoring_Rule_Set;
use MVOC\StreetO\League_Cache;
use MVOC\StreetO\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes scoring_rule_sets.
 */
class Rule_Sets_Repo {

	/**
	 * Every saved definition, by name.
	 *
	 * @return array<int,array{id:int,name:string,definition:string}>
	 */
	public function all(): array {
		global $wpdb;

		$table = Schema::table( 'scoring_rule_sets' );
		$rows  = $wpdb->get_results( "SELECT id, name, definition FROM `{$table}` ORDER BY name", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		return array_map(
			static fn( array $row ): array => array(
				'id'         => (int) $row['id'],
				'name'       => (string) $row['name'],
				'definition' => (string) $row['definition'],
			),
			$rows ?: array()
		);
	}

	/**
	 * One definition's row, or null.
	 *
	 * @param int $id Rule set id.
	 * @return array{id:int,name:string,definition:string}|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'scoring_rule_sets' );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT id, name, definition FROM `{$table}` WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row
			? array(
				'id'         => (int) $row['id'],
				'name'       => (string) $row['name'],
				'definition' => (string) $row['definition'],
			)
			: null;
	}

	/**
	 * Save a validated definition, creating it or replacing the one with this id.
	 *
	 * The name is taken from the definition, so the two cannot disagree.
	 * Dropping the league cache is not optional: editing a shared definition
	 * rescores every unpublished event that uses it.
	 *
	 * @param Scoring_Rule_Set $rules Validated definition.
	 * @param int              $id    Existing id to replace, or 0 to create.
	 * @return int The id.
	 */
	public function save( Scoring_Rule_Set $rules, int $id = 0 ): int {
		global $wpdb;

		$table  = Schema::table( 'scoring_rule_sets' );
		$values = array(
			'name'       => '' !== $rules->name ? $rules->name : 'Untitled scoring',
			'definition' => $rules->to_json(),
			'updated_at' => current_time( 'mysql' ),
		);

		if ( $id > 0 && $this->find( $id ) ) {
			$wpdb->update( $table, $values, array( 'id' => $id ), array( '%s', '%s', '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			League_Cache::bump();

			return $id;
		}

		$wpdb->insert( $table, $values, array( '%s', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		League_Cache::bump();

		return (int) $wpdb->insert_id;
	}

	/**
	 * Events using a definition, published or not.
	 *
	 * @param int $id Rule set id.
	 */
	public function usage_count( int $id ): int {
		global $wpdb;

		$events = Schema::table( 'events' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$events}` WHERE scoring_rule_set_id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Delete a definition nothing uses.
	 *
	 * @param int $id Rule set id.
	 * @return bool Whether it was deleted; false while an event still uses it.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		if ( $this->usage_count( $id ) > 0 ) {
			return false;
		}

		$wpdb->delete( Schema::table( 'scoring_rule_sets' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return true;
	}
}
