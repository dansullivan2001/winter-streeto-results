<?php
/**
 * Persistence for result rows, their corrections and the resolved values.
 *
 * The three-layer split lives here in practice: raw columns are what MapRun
 * said, `overrides` is an append-only record of what the co-ordinator changed,
 * and the resolved columns are the two combined. An import touches only the raw
 * columns, so a correction survives every later fetch.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Repo;

use MVOC\StreetO\Domain\Import_Reconciler;
use MVOC\StreetO\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes results and overrides.
 */
class Results_Repo {

	/**
	 * Fields the co-ordinator may override, and how each is stored.
	 *
	 * @var array<string,string>
	 */
	public const OVERRIDABLE = array(
		'score'       => 'resolved_score',
		'penalty'     => 'resolved_penalty',
		'course'      => 'resolved_course_label',
		'excluded'    => 'is_excluded',
		'competitor'  => 'competitor_id',
	);

	/**
	 * Every result row for an event, best first.
	 *
	 * @param int $event_id Event id.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_event( int $event_id ): array {
		global $wpdb;

		$table = Schema::table( 'results' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE event_id = %d ORDER BY id", $event_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * Result rows for one MapRun source, used when reconciling an import.
	 *
	 * @param int $event_source_id Source id.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_source( int $event_source_id ): array {
		global $wpdb;

		$table = Schema::table( 'results' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE event_source_id = %d", $event_source_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * Cast a row to the types the domain classes compare strictly against.
	 *
	 * @param array<string,mixed> $row Raw database row.
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		foreach ( array( 'id', 'event_id', 'event_source_id', 'raw_penalty' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}

		foreach ( array( 'is_excluded', 'is_manual', 'is_withdrawn' ) as $flag ) {
			$row[ $flag ] = (bool) $row[ $flag ];
		}

		$nullables = array(
			'competitor_id',
			'raw_score',
			'resolved_score',
			'resolved_penalty',
			'raw_year_of_birth',
			'raw_time_secs',
			'resolved_time_secs',
		);

		foreach ( $nullables as $nullable ) {
			$row[ $nullable ] = null === $row[ $nullable ] ? null : (int) $row[ $nullable ];
		}

		return $row;
	}

	/**
	 * Apply one reconciliation action.
	 *
	 * @param array<string,mixed> $action          Action from Import_Reconciler.
	 * @param int                 $event_id        Event id.
	 * @param int                 $event_source_id Source id.
	 * @param int                 $fetch_id        Snapshot id.
	 */
	public function apply_action( array $action, int $event_id, int $event_source_id, int $fetch_id ): void {
		global $wpdb;

		$table = Schema::table( 'results' );

		if ( Import_Reconciler::WITHDRAW === $action['action'] ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array( 'is_withdrawn' => 1 ),
				array( 'id' => (int) $action['result_id'] ),
				array( '%d' ),
				array( '%d' )
			);

			return;
		}

		$columns             = Import_Reconciler::raw_columns( $action['row'] );
		$columns['fetch_id'] = $fetch_id;

		if ( Import_Reconciler::INSERT === $action['action'] ) {
			$columns['event_id']        = $event_id;
			$columns['event_source_id'] = $event_source_id;

			// A failed upload starts excluded. It stays visible on the review
			// screen, but must never rank, and asking the co-ordinator to
			// exclude a dozen of them by hand each month would be busywork.
			$columns['is_excluded'] = ! empty( $action['row']['is_failed'] ) ? 1 : 0;

			$wpdb->insert( $table, $columns ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			return;
		}

		// UPDATE or RESTORE: refresh the raw columns only. Nothing here touches
		// a resolved_* column, is_excluded or competitor_id, so the
		// co-ordinator's corrections are left exactly as they were.
		if ( Import_Reconciler::RESTORE === $action['action'] ) {
			$columns['is_withdrawn'] = 0;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			$columns,
			array( 'id' => (int) $action['result_id'] ),
			null,
			array( '%d' )
		);
	}

	/**
	 * Record a correction and materialise it onto the row.
	 *
	 * @param int    $result_id Result id.
	 * @param string $field     One of the OVERRIDABLE keys.
	 * @param mixed  $value     New value.
	 * @param string $reason    Why, for the audit trail.
	 */
	public function override( int $result_id, string $field, $value, string $reason = '' ): void {
		if ( ! isset( self::OVERRIDABLE[ $field ] ) ) {
			return;
		}

		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'overrides' ),
			array(
				'result_id' => $result_id,
				'field'     => $field,
				'new_value' => null === $value ? null : (string) $value,
				'reason'    => $reason,
				'author_id' => get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%s', '%d' )
		);

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'results' ),
			array( self::OVERRIDABLE[ $field ] => $value ),
			array( 'id' => $result_id ),
			null,
			array( '%d' )
		);
	}

	/**
	 * The corrections made to an event's rows, newest first.
	 *
	 * @param int $event_id Event id.
	 * @return array<int,array<string,mixed>>
	 */
	public function overrides_for_event( int $event_id ): array {
		global $wpdb;

		$overrides = Schema::table( 'overrides' );
		$results   = Schema::table( 'results' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT o.* FROM `{$overrides}` o
				 INNER JOIN `{$results}` r ON r.id = o.result_id
				 WHERE r.event_id = %d
				 ORDER BY o.created_at DESC, o.id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$event_id
			),
			ARRAY_A
		);

		return $rows ?: array();
	}

	/**
	 * Add a runner MapRun never saw — a phone that failed entirely.
	 *
	 * Manual rows carry no MapRun id, which is exactly what keeps a later
	 * import from reconsidering them.
	 *
	 * @param int                 $event_id        Event id.
	 * @param int                 $event_source_id Source the runner belongs to.
	 * @param array<string,mixed> $row             Name, score, penalty, course.
	 */
	public function add_manual( int $event_id, int $event_source_id, array $row ): int {
		// An event MapRun cannot score has no source at all, so zero is a
		// legitimate value here rather than a missing one.
		global $wpdb;

		$score   = isset( $row['score'] ) ? (int) $row['score'] : null;
		$penalty = (int) ( $row['penalty'] ?? 0 );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'results' ),
			array(
				'event_id'              => $event_id,
				'event_source_id'       => $event_source_id,
				'fetch_id'              => 0,
				'maprun_id'             => '',
				'competitor_id'         => ( $row['competitor_id'] ?? 0 ) ?: null,
				'raw_first_name'        => (string) ( $row['first_name'] ?? '' ),
				'raw_surname'           => (string) ( $row['surname'] ?? '' ),
				'classifier'            => 'MANUAL',
				'course_label'          => (string) ( $row['course_label'] ?? '60' ),
				'raw_score'             => $score,
				'raw_penalty'           => $penalty,
				'resolved_score'        => $score,
				'resolved_penalty'      => $penalty,
				'resolved_course_label' => (string) ( $row['course_label'] ?? '60' ),
				'is_manual'             => 1,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a manually added row.
	 *
	 * Only manual rows can be deleted: a MapRun row is excluded rather than
	 * removed, so its raw record and audit trail survive.
	 *
	 * @param int $result_id Result id.
	 */
	public function delete_manual( int $result_id ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'results' ),
			array(
				'id'        => $result_id,
				'is_manual' => 1,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Merge raw and resolved values into what the scoring engine should use.
	 *
	 * A resolved column that is null means "no correction here", so the raw
	 * value stands. That is what makes a correction targeted rather than a
	 * wholesale replacement of the row.
	 *
	 * @param array<string,mixed> $row Result row.
	 * @return array<string,mixed>
	 */
	public static function effective( array $row ): array {
		return array(
			'result_id'     => $row['id'],
			'competitor_id' => $row['competitor_id'],
			'maprun_id'     => $row['maprun_id'],
			'display_name'  => trim( $row['raw_first_name'] . ' ' . $row['raw_surname'] ),
			'club'          => $row['raw_club'] ?? '',
			'classifier'    => $row['classifier'],
			'course_label'  => '' !== ( $row['resolved_course_label'] ?? '' )
				? $row['resolved_course_label']
				: $row['course_label'],
			'score'         => $row['resolved_score'] ?? $row['raw_score'],
			// ?? not ?: - a penalty corrected to zero is a real correction, and
			// must not fall through to the raw value.
			'penalty'       => $row['resolved_penalty'] ?? $row['raw_penalty'],
			'time_secs'     => $row['resolved_time_secs'] ?? $row['raw_time_secs'],
			'is_excluded'   => (bool) $row['is_excluded'],
			'is_withdrawn'  => (bool) $row['is_withdrawn'],
			'is_manual'     => (bool) $row['is_manual'],
		);
	}
}
