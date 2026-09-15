<?php
/**
 * Decides what a re-import should do to each stored result row.
 *
 * This is the design's central promise: the co-ordinator imports an event,
 * spends twenty minutes correcting it, then re-imports because a late upload
 * appeared — and every correction is still there afterwards.
 *
 * Three rules make that hold:
 *
 *   Rows are matched on MapRun's own `Id`, not on a name. Names change spelling
 *   between uploads; the id does not. Note it identifies a *result*, not a
 *   person — one runner's three uploads carry three different ids.
 *
 *   Rows are never deleted. One that has vanished from MapRun is marked
 *   withdrawn instead. MapRun dropping a result is far likelier to be a glitch
 *   than a fact, and a delete would silently take the co-ordinator's correction
 *   with it.
 *
 *   Manually added rows are untouchable. They exist precisely because MapRun
 *   does not know about them — a runner whose phone died — so an import must
 *   never reconsider them.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit. Only the resulting SQL lives in the repo.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Produces insert/update/withdraw actions for an import.
 */
class Import_Reconciler {

	public const INSERT   = 'insert';
	public const UPDATE   = 'update';
	public const WITHDRAW = 'withdraw';
	public const RESTORE  = 'restore';

	/**
	 * Work out what to do with each row.
	 *
	 * @param array<int,array<string,mixed>> $stored   Existing result rows for this source.
	 * @param array<int,array<string,mixed>> $incoming Freshly parsed MapRun rows.
	 * @return array<int,array{action:string,result_id:int|null,row:array<string,mixed>|null}>
	 */
	public function reconcile( array $stored, array $incoming ): array {
		$by_maprun_id = array();
		foreach ( $stored as $row ) {
			$maprun_id = (string) ( $row['maprun_id'] ?? '' );

			// Manual rows carry no MapRun id and are excluded from matching
			// entirely, so nothing an import does can reach them.
			if ( '' !== $maprun_id && empty( $row['is_manual'] ) ) {
				$by_maprun_id[ $maprun_id ] = $row;
			}
		}

		$actions = array();
		$seen    = array();

		foreach ( $incoming as $row ) {
			$maprun_id = (string) ( $row['maprun_id'] ?? '' );

			// A row MapRun gave us with no id cannot be tracked across fetches.
			// Inserting it every time would duplicate the field on each import,
			// so it is skipped and reported rather than silently multiplied.
			if ( '' === $maprun_id ) {
				continue;
			}

			$seen[ $maprun_id ] = true;

			if ( ! isset( $by_maprun_id[ $maprun_id ] ) ) {
				$actions[] = array(
					'action'    => self::INSERT,
					'result_id' => null,
					'row'       => $row,
				);
				continue;
			}

			$existing = $by_maprun_id[ $maprun_id ];

			$actions[] = array(
				// A row that had been withdrawn and is back gets un-withdrawn,
				// keeping its id and therefore its corrections.
				'action'    => empty( $existing['is_withdrawn'] ) ? self::UPDATE : self::RESTORE,
				'result_id' => (int) $existing['id'],
				'row'       => $row,
				'recheck'   => self::evidence_changed( $existing, $row ),
			);
		}

		foreach ( $by_maprun_id as $maprun_id => $existing ) {
			if ( isset( $seen[ $maprun_id ] ) || ! empty( $existing['is_withdrawn'] ) ) {
				continue;
			}

			$actions[] = array(
				'action'    => self::WITHDRAW,
				'result_id' => (int) $existing['id'],
				'row'       => null,
			);
		}

		return $actions;
	}

	/**
	 * Summarise a set of actions, for the co-ordinator's confirmation message.
	 *
	 * @param array<int,array<string,mixed>> $actions Reconciliation actions.
	 * @return array<string,int>
	 */
	public static function summarise( array $actions ): array {
		$summary = array(
			self::INSERT   => 0,
			self::UPDATE   => 0,
			self::WITHDRAW => 0,
			self::RESTORE  => 0,
		);

		foreach ( $actions as $action ) {
			++$summary[ $action['action'] ];
		}

		return $summary;
	}

	/**
	 * The figures a co-ordinator was looking at when they accepted a row.
	 *
	 * The two warnings are read from the classifier, the score, the elapsed
	 * time and the track start; the penalty is here because it is the fourth
	 * number on the review row and, on a zero-time row, the one that decides
	 * the published total, MapRun's own figure standing unrecomputed once there
	 * is no time to recompute from.
	 *
	 * Not every raw column, and the difference is the point: a runner adding
	 * their club, or MapRun correcting a spelling, says nothing about whether
	 * the row is a broken upload or a run done on the wrong night, and must not
	 * throw away a decision someone made about it.
	 */
	private const EVIDENCE = array(
		'classifier',
		'raw_score',
		'raw_penalty',
		'raw_time_secs',
		'raw_track_start_utc',
	);

	/**
	 * Whether an import changes what the co-ordinator would have been looking at.
	 *
	 * Answers one question for apply_action(): does this row still deserve the
	 * "checked" it was given? Every import issues an UPDATE for every matched
	 * row whether or not anything moved, so un-checking on the action alone
	 * would wipe a season's decisions on a re-fetch that changed nothing —
	 * which is the normal case on the night, when the co-ordinator imports
	 * two or three times as late uploads arrive.
	 *
	 * Compared strictly, which is safe because both sides are normalised:
	 * stored rows arrive through Results_Repo::hydrate() and incoming ones
	 * through raw_columns(), and both cast the score and elapsed time to
	 * int-or-null.
	 *
	 * @param array<string,mixed> $stored   The row as it is held now.
	 * @param array<string,mixed> $incoming The freshly parsed MapRun row.
	 */
	public static function evidence_changed( array $stored, array $incoming ): bool {
		$fresh = self::raw_columns( $incoming );

		foreach ( self::EVIDENCE as $column ) {
			if ( ( $stored[ $column ] ?? null ) !== $fresh[ $column ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Raw columns an import may write, with the resolved ones left alone.
	 *
	 * Splitting this out is what keeps a correction safe: an import refreshes
	 * only what MapRun is authoritative about, and never touches the resolved_*
	 * columns that carry the co-ordinator's decisions.
	 *
	 * @param array<string,mixed> $row Parsed MapRun row.
	 * @return array<string,mixed>
	 */
	public static function raw_columns( array $row ): array {
		return array(
			'maprun_id'           => (string) ( $row['maprun_id'] ?? '' ),
			'raw_first_name'      => (string) ( $row['first_name'] ?? '' ),
			'raw_surname'         => (string) ( $row['surname'] ?? '' ),
			'raw_club'            => (string) ( $row['club'] ?? '' ),
			'raw_gender'          => (string) ( $row['gender'] ?? '' ),
			// The flag MapRun's year implied, not the year itself, so a
			// competitor created weeks after the import still lands in the
			// right category without a date of birth being kept.
			'raw_is_over55'       => array_key_exists( 'is_over55', $row ) ? ( $row['is_over55'] ? 1 : 0 ) : null,
			'classifier'          => (string) ( $row['classifier'] ?? '' ),
			'course_label'        => (string) ( $row['course_label'] ?? '' ),
			'raw_score'           => isset( $row['score'] ) ? (int) $row['score'] : null,
			// Where that score came from: a MapRun field, or the punch record
			// when MapRun reported none. Stored rather than re-derived because
			// the payload it was derived from is a snapshot the co-ordinator
			// can replace, and a published score should be able to say where it
			// came from without one.
			'score_source'        => (string) ( $row['score_field'] ?? '' ),
			'raw_penalty'         => (int) ( $row['penalty'] ?? 0 ),
			'raw_time_secs'       => isset( $row['time_secs'] ) ? (int) $row['time_secs'] : null,
			// The only date MapRun sends. Kept raw, exactly as it arrived and
			// under its own misleading name, because the ten-hour correction
			// that turns it into a local date is an observation about MapRun's
			// server rather than a fact — storing the derived date instead
			// would bake today's reading of it into every row.
			'raw_track_start_utc' => (string) ( $row['track_start_utc'] ?? '' ),
			// What identifies one run: the duplicate detector recognises the
			// same run recorded twice by its start, finish and elapsed time,
			// and cannot do it from the elapsed time alone. The revision goes
			// with them because it is what distinguishes two scorings of one
			// run once they have been found.
			'raw_start_local'     => (string) ( $row['start_local'] ?? '' ),
			'raw_finish_local'    => (string) ( $row['finish_local'] ?? '' ),
			'raw_course_revision' => isset( $row['course_revision'] ) && null !== $row['course_revision']
				? (int) $row['course_revision']
				: null,
		);
	}
}
