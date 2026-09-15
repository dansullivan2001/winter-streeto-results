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
use MVOC\StreetO\Domain\Scoring_Config;
use MVOC\StreetO\League_Cache;
use MVOC\StreetO\MapRun\Parser;
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
		// Not a figure, but a decision about one, and it belongs in the same
		// trail: "this row scores with no time and I am publishing it anyway"
		// is exactly the call someone will want explained in March.
		'checked'     => 'is_checked',
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

		foreach ( array( 'is_excluded', 'is_manual', 'is_withdrawn', 'is_checked' ) as $flag ) {
			// Coalesced rather than read straight: maybe_upgrade() runs on
			// plugins_loaded, so a column added in a new version is missing for
			// exactly as long as it takes that hook to fire, and an unchecked
			// row is the right reading of a row that has no such column yet.
			$row[ $flag ] = (bool) ( $row[ $flag ] ?? false );
		}

		$nullables = array(
			'competitor_id',
			'raw_score',
			'resolved_score',
			'resolved_penalty',
			'raw_is_over55',
			'raw_time_secs',
			'resolved_time_secs',
			'raw_course_revision',
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

			League_Cache::bump();

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

			League_Cache::bump();

			return;
		}

		// UPDATE or RESTORE: refresh the raw columns only. Nothing here touches
		// a resolved_* column, is_excluded or competitor_id, so the
		// co-ordinator's corrections are left exactly as they were.
		if ( Import_Reconciler::RESTORE === $action['action'] ) {
			$columns['is_withdrawn'] = 0;
		}

		// is_checked is the exception, and deliberately so. It records that a
		// person looked at this row's time, date and score and accepted them;
		// once MapRun sends different ones, it is a judgement about a row that
		// no longer exists. Left standing, it would silence a warning nobody
		// had read — the precise failure the flag was added to prevent. Only a
		// changed row is un-checked: a re-import that brings the same figures
		// back, which is most of them, leaves the decision alone.
		if ( ! empty( $action['recheck'] ) ) {
			$columns['is_checked'] = 0;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			$columns,
			array( 'id' => (int) $action['result_id'] ),
			null,
			array( '%d' )
		);

		League_Cache::bump();
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

		League_Cache::bump();
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
				'classifier'            => Parser::CLASSIFIER_MANUAL,
				'course_label'          => (string) ( $row['course_label'] ?? '60' ),
				'raw_score'             => $score,
				'raw_penalty'           => $penalty,
				'resolved_score'        => $score,
				'resolved_penalty'      => $penalty,
				'resolved_course_label' => (string) ( $row['course_label'] ?? '60' ),
				'is_manual'             => 1,
			)
		);

		League_Cache::bump();

		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a manually added row.
	 *
	 * Only manual rows can be deleted: a MapRun row is excluded rather than
	 * removed, so its raw record and audit trail survive.
	 *
	 * Scoped to an event as well as an id. The id arrives from a form, and the
	 * screen that submits it is only ever looking at one event — so a stale or
	 * edited one should match nothing rather than reach across to a row the
	 * co-ordinator cannot currently see.
	 *
	 * @param int $result_id Result id.
	 * @param int $event_id  Event the row must belong to.
	 */
	public function delete_manual( int $result_id, int $event_id ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'results' ),
			array(
				'id'        => $result_id,
				'event_id'  => $event_id,
				'is_manual' => 1,
			),
			array( '%d', '%d', '%d' )
		);

		League_Cache::bump();
	}

	/**
	 * Merge raw and resolved values into what the scoring engine should use.
	 *
	 * A resolved column that is null means "no correction here", so the raw
	 * value stands. That is what makes a correction targeted rather than a
	 * wholesale replacement of the row.
	 *
	 * The late penalty has a third layer between those two. MapRun charges 30
	 * points per *started* minute, where the club charges 1 point per 2
	 * seconds — the same rate, but MapRun rounds a 47-second overrun up to a
	 * full minute and takes 30 points for it instead of 24. So the penalty is
	 * recomputed here from the elapsed time, which is stored raw alongside
	 * everything else and therefore applies to events already imported without
	 * re-fetching them. `raw_penalty` keeps what MapRun said, unedited, and is
	 * returned as `maprun_penalty` so the difference can be shown rather than
	 * silently applied. A correction still wins over both.
	 *
	 * @param array<string,mixed>      $row    Result row.
	 * @param Scoring_Config|null      $config Series scoring rules; MapRun's penalty stands without them.
	 * @return array<string,mixed>
	 */
	public static function effective( array $row, ?Scoring_Config $config = null ): array {
		$course = '' !== ( $row['resolved_course_label'] ?? '' )
			? $row['resolved_course_label']
			: $row['course_label'];

		$time_secs = $row['resolved_time_secs'] ?? $row['raw_time_secs'];
		$maprun    = (int) $row['raw_penalty'];

		// Null means the club's rule could not be applied — no elapsed time, or
		// a course with no known limit — and MapRun's figure is then the only
		// one there is. Not zero: a missing time is not proof nobody was late.
		$recomputed = $config
			? $config->late_penalty( null === $time_secs ? null : (int) $time_secs, (string) $course )
			: null;

		$first_name = (string) ( $row['raw_first_name'] ?? '' );
		$surname    = (string) ( $row['raw_surname'] ?? '' );

		return array(
			'result_id'       => $row['id'],
			'competitor_id'   => $row['competitor_id'],
			'maprun_id'       => $row['maprun_id'],
			// Both the joined name and its parts. Duplicate_Detector matches on
			// the parts, and rebuilding them by splitting a display name on a
			// space would get every double-barrelled surname wrong.
			'first_name'      => $first_name,
			'surname'         => $surname,
			'display_name'    => trim( $first_name . ' ' . $surname ),
			'club'            => $row['raw_club'] ?? '',
			'classifier'      => $row['classifier'],
			'course_label'    => $course,
			'score'           => $row['resolved_score'] ?? $row['raw_score'],
			// Where the score being published came from. A correction is the
			// co-ordinator's own figure, so it answers for itself and the
			// import's provenance no longer describes what is on the row —
			// which is why this is emptied rather than carried over.
			'score_source'    => null === ( $row['resolved_score'] ?? null )
				? (string) ( $row['score_source'] ?? '' )
				: '',
			// ?? not ?: - a penalty corrected to zero is a real correction, and
			// must not fall through to the recomputed or raw value.
			'penalty'         => $row['resolved_penalty'] ?? $recomputed ?? $maprun,
			'maprun_penalty'  => $maprun,
			'time_secs'       => $time_secs,
			'time_display'    => Parser::format_hhmmss( null === $time_secs ? null : (int) $time_secs ),
			// What identifies one run, for the duplicate detector. Raw, never
			// resolved: these are evidence about what MapRun recorded, not
			// figures the co-ordinator publishes or corrects.
			'start_local'     => (string) ( $row['raw_start_local'] ?? '' ),
			'finish_local'    => (string) ( $row['raw_finish_local'] ?? '' ),
			'course_revision' => $row['raw_course_revision'] ?? null,
			'track_start_utc' => (string) ( $row['raw_track_start_utc'] ?? '' ),
			// Derived on read, not stored: the ten-hour correction is a reading
			// of MapRun's server rather than a fact about the run, so a better
			// reading later must not need a re-import to take effect.
			'run_date'        => Parser::local_date_of( (string) ( $row['raw_track_start_utc'] ?? '' ) ),
			// One predicate, shared with the parser, so a stored row and a
			// freshly parsed one agree about what is not a performance. They
			// did not while this was spelled out here: the parser learned about
			// DNF and this did not, which would have left the duplicate
			// detector reading DNF rows it is meant to skip.
			'is_failed'       => Parser::is_unfinished(
				(string) $row['classifier'],
				null === $time_secs ? null : (int) $time_secs
			),
			// Rebuilt from the stored classifier, time and score rather than
			// stored in a column of its own: every input is already here, so an
			// already-imported season carries the flag without a migration or a
			// re-fetch, and a score correction is reflected at once.
			'is_zero_time'    => Parser::is_zero_time(
				(string) $row['classifier'],
				null === $time_secs ? null : (int) $time_secs,
				$row['resolved_score'] ?? $row['raw_score']
			),
			'is_excluded'     => (bool) $row['is_excluded'],
			'is_withdrawn'    => (bool) $row['is_withdrawn'],
			'is_manual'       => (bool) $row['is_manual'],
			// The co-ordinator's answer to is_zero_time and is_off_date: they
			// have looked at this row against the night and it stands. It
			// silences the warning and nothing else — the row was already
			// scoring, and a check that changed the score would be a
			// correction, made in the fields above.
			'is_checked'      => (bool) ( $row['is_checked'] ?? false ),
		);
	}

	/**
	 * Resolve a whole set of rows under one scoring config.
	 *
	 * Exists because `effective()` is used as an array_map callback in half a
	 * dozen places, and a callback cannot carry the config that the penalty
	 * rule needs.
	 *
	 * @param array<int,array<string,mixed>> $rows   Result rows.
	 * @param Scoring_Config|null            $config Series scoring rules.
	 * @return array<int,array<string,mixed>>
	 */
	public static function effective_rows( array $rows, ?Scoring_Config $config = null ): array {
		return array_map(
			static fn( array $row ): array => self::effective( $row, $config ),
			$rows
		);
	}
}
