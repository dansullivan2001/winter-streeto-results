<?php
/**
 * Adapter from a raw MapRun API response to normalised result rows.
 *
 * The field names are pinned against a real StreetO response — MVOC's
 * "Worcester Park Apr26 PXAS ScoreQ60", committed as a test fixture:
 *
 *   envelope : { errorFlag, statusMessage, warningFlag, warningMessage,
 *                results: [ ... ] }
 *   row      : Id, Firstname, Surname, Gender, YearOfBirth, ClubName,
 *              Classifier, TrackStartDateTimeUTC, StartPunchTimeLocal,
 *              FinishPunchTimeLocal, TotalTimehhmmss, TotalTimeSecs,
 *              GrossScore, NetScore, Distance, Pacemmss, PaceMins,
 *              MapRunVersion, punchControlIds[], punchTimeAfterStartSecs[]
 *
 * TrackStartDateTimeUTC is the only date in a row — every other moment is a
 * time of day — and it was dropped by the fixture this list was first pinned
 * against, so the parser went a season without knowing what day a run was on.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\MapRun;

defined( 'ABSPATH' ) || exit;

/**
 * Parses MapRun responses into rows the rest of the plugin understands.
 */
class Parser {

	/**
	 * Classifier value MapRun uses for a run that uploaded but did not record.
	 *
	 * Every such row in the real response carried zero score, zero elapsed time
	 * and no punches: a failed upload rather than a DNF.
	 */
	public const CLASSIFIER_FAILED = '--';

	/**
	 * Classifier the plugin writes on a row the co-ordinator added by hand.
	 *
	 * Never supplied by MapRun. It is here so that checks about what MapRun
	 * recorded can tell a hand-added row apart from a parsed one — a manual
	 * row legitimately carries a score and no elapsed time.
	 */
	public const CLASSIFIER_MANUAL = 'MANUAL';

	/**
	 * Hours to add to `TrackStartDateTimeUTC` to get back to UK local time.
	 *
	 * The field is not UTC, whatever it is called. Measured against the first
	 * punch of the same row, across two real responses either side of the
	 * daylight-saving boundary, 65 phone and Strava uploads between them:
	 *
	 *   December (GMT)   40 of 41 within 55 seconds of local minus 10h
	 *   September (BST)  24 of 24 within  9 seconds of local minus 10h
	 *
	 * A genuine UTC field would differ by a whole hour between those two. This
	 * one does not move at all, so it is not a conversion: MapRun takes the
	 * local wall-clock time and subtracts ten hours — Australian Eastern
	 * Standard Time, which their server runs on and which never observes
	 * daylight saving either — then labels the result UTC. Adding the ten hours
	 * back is therefore right in both seasons, which no timezone-aware reading
	 * of this field would be.
	 *
	 * Two kinds of row sit further out, neither near an hour. MapRunG watch
	 * uploads scatter by up to 1h 33m, because there the field records when the
	 * GPS track started rather than when the runner punched. The single phone
	 * row outside a minute was 14m 20s out, on a much later app version than
	 * anything else in either response.
	 *
	 * So only the date is taken from this, and the time of day is left to
	 * StartPunchTimeLocal. Across all 109 rows the recovered date was right
	 * every time, including the one row that really was run on another day.
	 */
	public const TRACK_START_OFFSET_HOURS = 10;

	/**
	 * Pull the results array out of a decoded response envelope.
	 *
	 * @param mixed $decoded Decoded JSON.
	 * @return array<int,array<string,mixed>>
	 * @throws \RuntimeException If the envelope is malformed or reports an error.
	 */
	public static function unwrap( $decoded ): array {
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( 'MapRun response was not a JSON object.' );
		}

		if ( self::is_flag_set( $decoded['errorFlag'] ?? false ) ) {
			$message = (string) ( $decoded['statusMessage'] ?? 'unknown error' );
			throw new \RuntimeException( 'MapRun API error: ' . $message );
		}

		if ( ! isset( $decoded['results'] ) || ! is_array( $decoded['results'] ) ) {
			throw new \RuntimeException( 'MapRun response contained no results array.' );
		}

		return array_values( array_filter( $decoded['results'], 'is_array' ) );
	}

	/**
	 * Any warning the response carried, or null.
	 *
	 * The real Worcester Park response came back with warningFlag set and
	 * "Multiple events found ... There should be only one." — which is exactly
	 * what produced its duplicate rows. A warning is not an error and must not
	 * stop the import, but swallowing it would hide the cause of a real data
	 * problem, so it is surfaced on the review screen.
	 *
	 * @param mixed $decoded Decoded JSON.
	 */
	public static function warning( $decoded ): ?string {
		if ( ! is_array( $decoded ) || ! self::is_flag_set( $decoded['warningFlag'] ?? false ) ) {
			return null;
		}

		$message = trim( (string) ( $decoded['warningMessage'] ?? '' ) );

		return '' === $message ? 'MapRun returned an unspecified warning.' : $message;
	}

	/**
	 * Whether a MapRun boolean flag is set.
	 *
	 * These have been seen as both booleans and 0/1, so neither false nor 0 nor
	 * an empty string may be mistaken for a raised flag.
	 *
	 * @param mixed $value Raw flag value.
	 */
	private static function is_flag_set( $value ): bool {
		return ! in_array( $value, array( false, 0, '0', '', null ), true );
	}

	/**
	 * Normalise every row in a results array.
	 *
	 * @param array<int,array<string,mixed>> $rows         Raw MapRun rows.
	 * @param string                         $course_label Course this source feeds, e.g. '60'.
	 * @return array<int,array<string,mixed>>
	 */
	public function parse( array $rows, string $course_label = '60' ): array {
		$parsed = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$parsed[] = $this->parse_row( $row, $course_label );
			}
		}

		return $parsed;
	}

	/**
	 * Normalise a single MapRun row.
	 *
	 * @param array<string,mixed> $row          Raw MapRun row.
	 * @param string              $course_label Course this source feeds.
	 * @return array<string,mixed>
	 */
	public function parse_row( array $row, string $course_label = '60' ): array {
		$first       = trim( (string) ( $row['Firstname'] ?? '' ) );
		$surname_raw = trim( (string) ( $row['Surname'] ?? '' ) );
		$revision    = self::split_revision( $surname_raw );
		$scores      = $this->extract_scores( $row );
		$classifier  = trim( (string) ( $row['Classifier'] ?? '' ) );
		$time_secs   = $this->extract_time_secs( $row );

		return array(
			'maprun_id'       => trim( (string) ( $row['Id'] ?? '' ) ),
			'first_name'      => $first,
			'surname'         => $revision['surname'],
			'surname_raw'     => $surname_raw,
			'course_revision' => $revision['revision'],
			'display_name'    => trim( $first . ' ' . $revision['surname'] ),
			'club'            => trim( (string) ( $row['ClubName'] ?? '' ) ),
			'gender'          => $this->normalise_gender( $row['Gender'] ?? '' ),
			'year_of_birth'   => $this->extract_year_of_birth( $row ),
			'classifier'      => $classifier,
			'is_failed'       => $this->is_failed_upload( $classifier, $row ),
			'is_zero_time'    => self::is_zero_time( $classifier, $time_secs, $scores['score'] ),
			'course_label'    => $course_label,
			'track_start_utc' => trim( (string) ( $row['TrackStartDateTimeUTC'] ?? '' ) ),
			'start_local'     => trim( (string) ( $row['StartPunchTimeLocal'] ?? '' ) ),
			'finish_local'    => trim( (string) ( $row['FinishPunchTimeLocal'] ?? '' ) ),
			'time_display'    => trim( (string) ( $row['TotalTimehhmmss'] ?? '' ) ),
			'time_secs'       => $time_secs,
			'score'           => $scores['score'],
			'net_score'       => $scores['net'],
			'penalty'         => $scores['penalty'],
			'punches'         => $this->ordered_punches( $row ),
		);
	}

	/**
	 * Split a "(RevNN)" course-revision suffix off a surname.
	 *
	 * MapRun appends this when a result was scored against a particular course
	 * revision. It is not part of the person's name and would break matching
	 * across events, so it is stripped — but it is *not* a duplicate marker
	 * either: in the real response, two runners carried a suffix while
	 * appearing exactly once. Recording the revision keeps it
	 * available without letting it pollute identity.
	 *
	 * @param string $surname Raw surname as MapRun supplied it.
	 * @return array{surname:string,revision:int|null}
	 */
	public static function split_revision( string $surname ): array {
		if ( preg_match( '/^(.*?)\s*\(Rev\s*(\d+)\)\s*$/i', $surname, $matches ) ) {
			return array(
				'surname'  => trim( $matches[1] ),
				'revision' => (int) $matches[2],
			);
		}

		return array(
			'surname'  => $surname,
			'revision' => null,
		);
	}

	/**
	 * Read the score and derive the time penalty.
	 *
	 * MapRun reports `GrossScore` (points collected) and `NetScore` (after the
	 * time penalty). These map onto the club's spreadsheet as Score, Total and
	 * the penalty between them — so the penalty is simply the difference, and
	 * the engine's `Score - Penalty` reduces back to `NetScore`.
	 *
	 * @param array<string,mixed> $row Raw MapRun row.
	 * @return array{score:int|null,net:int|null,penalty:int}
	 */
	private function extract_scores( array $row ): array {
		$gross = isset( $row['GrossScore'] ) && is_numeric( $row['GrossScore'] ) ? (int) $row['GrossScore'] : null;
		$net   = isset( $row['NetScore'] ) && is_numeric( $row['NetScore'] ) ? (int) $row['NetScore'] : null;

		if ( null === $gross && null === $net ) {
			return array(
				'score'   => null,
				'net'     => null,
				'penalty' => 0,
			);
		}

		// If only one is present, treat it as both: a missing counterpart means
		// no penalty information, not a penalty of the whole score.
		$gross = $gross ?? $net;
		$net   = $net ?? $gross;

		return array(
			'score'   => $gross,
			'net'     => $net,
			'penalty' => max( 0, $gross - $net ),
		);
	}

	/**
	 * Whether this row is a failed upload rather than a performance.
	 *
	 * MapRun marks these `--`, and every one in the real response also had zero
	 * elapsed time. Both conditions are checked so that a `--` row carrying a
	 * genuine run would still be surfaced rather than silently discarded.
	 *
	 * @param string              $classifier Classifier value.
	 * @param array<string,mixed> $row        Raw MapRun row.
	 */
	private function is_failed_upload( string $classifier, array $row ): bool {
		if ( self::CLASSIFIER_FAILED !== $classifier ) {
			return false;
		}

		return 0 === (int) ( $row['TotalTimeSecs'] ?? 0 );
	}

	/**
	 * Whether a row claims a score but recorded no run.
	 *
	 * The mirror of is_failed_upload(), and the case it does not cover. That
	 * one asks whether a row MapRun marked `--` really is a failed upload; this
	 * asks whether a row MapRun did *not* mark is one anyway. A real response
	 * contained exactly that: `Classifier: "OK"`, twenty controls, a score of
	 * 660 — and zero elapsed time, zero distance, every punch at zero seconds.
	 * Nothing about it is a performance, but it is scored like one.
	 *
	 * It is only a flag. The row still ranks until the co-ordinator excludes
	 * it, because the plugin cannot tell a broken upload from a genuine run
	 * whose timing MapRun lost, and guessing would silently drop a real result.
	 *
	 * Two classifiers are never flagged. A `--` row with no time is already a
	 * failed upload and excluded on import, so flagging it again would ask for
	 * a decision that has been made. A `MANUAL` row is the co-ordinator's own:
	 * a score with no elapsed time is exactly what hand entry produces.
	 *
	 * @param string   $classifier Classifier value, as MapRun supplied it.
	 * @param int|null $time_secs  Elapsed time in seconds, or null where absent.
	 * @param mixed    $score      Score the row carries.
	 */
	public static function is_zero_time( string $classifier, ?int $time_secs, $score ): bool {
		$classifier = trim( $classifier );

		if ( self::CLASSIFIER_FAILED === $classifier || self::CLASSIFIER_MANUAL === $classifier ) {
			return false;
		}

		// No score means nothing that would rank, so nothing to warn about.
		if ( ! is_numeric( $score ) ) {
			return false;
		}

		return null === $time_secs || $time_secs <= 0;
	}

	/**
	 * The local calendar date a run was recorded on, as `Y-m-d`.
	 *
	 * The only date MapRun gives at all. Everything else in a result row is a
	 * time of day, which says nothing about *which* day — so without this a run
	 * uploaded months after the event is indistinguishable from one done on the
	 * night, and scores in the league exactly the same.
	 *
	 * The date alone is taken, never the time: see TRACK_START_OFFSET_HOURS for
	 * why the time of day in this field cannot be trusted on a watch upload
	 * while the date can.
	 *
	 * Returns null where there is nothing to read rather than guessing at a
	 * date. An older MapRun version that never sent the field, or a value that
	 * will not parse, must leave a row unjudged rather than wrongly flagged.
	 *
	 * @param string $track_start Raw TrackStartDateTimeUTC value.
	 */
	public static function local_date_of( string $track_start ): ?string {
		$track_start = trim( $track_start );

		if ( '' === $track_start ) {
			return null;
		}

		try {
			// The explicit UTC fallback applies only when the value carries no
			// zone of its own. Real responses end in `Z` and ignore it, but
			// without it a value that ever arrived bare would be read in the
			// server's timezone, making the recovered date depend on where
			// WordPress is hosted.
			$moment = new \DateTimeImmutable( $track_start, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return null;
		}

		return $moment
			->add( new \DateInterval( 'PT' . self::TRACK_START_OFFSET_HOURS . 'H' ) )
			->format( 'Y-m-d' );
	}

	/**
	 * Whether a run was recorded on a different day from the event.
	 *
	 * MapRun courses stay live after the event, so anyone with the app can run
	 * one later and their result lands in the same response. A real December
	 * event carried a row from the following April, scored and ranked like any
	 * other. The duplicate detector was never going to catch it — it is not a
	 * duplicate of anything, just a lone run on the wrong day.
	 *
	 * False unless both dates are known and they differ. A missing event date
	 * or an unreadable track start is an absence of evidence, and flagging a
	 * whole field on the strength of one would train the co-ordinator to
	 * dismiss the warning.
	 *
	 * @param string|null $run_date   Local date of the run, from local_date_of().
	 * @param string|null $event_date Date the event was held, as `Y-m-d`.
	 */
	public static function is_off_date( ?string $run_date, ?string $event_date ): bool {
		$run_date   = trim( (string) $run_date );
		$event_date = trim( (string) $event_date );

		if ( '' === $run_date || '' === $event_date ) {
			return false;
		}

		return $run_date !== $event_date;
	}

	/**
	 * Year of birth, used to derive the Over-55 category and then discarded.
	 *
	 * It is deliberately never persisted: the flag it produces is all the
	 * league needs, and holding every member's date of birth to work out one
	 * boolean is not a fair trade.
	 *
	 * @param array<string,mixed> $row Raw MapRun row.
	 */
	private function extract_year_of_birth( array $row ): ?int {
		if ( ! isset( $row['YearOfBirth'] ) || ! is_numeric( $row['YearOfBirth'] ) ) {
			return null;
		}

		$year = (int) $row['YearOfBirth'];

		// MapRun stores 0 where the runner did not supply one.
		return $year > 1900 ? $year : null;
	}

	/**
	 * Elapsed time in seconds.
	 *
	 * @param array<string,mixed> $row Raw MapRun row.
	 */
	private function extract_time_secs( array $row ): ?int {
		if ( isset( $row['TotalTimeSecs'] ) && is_numeric( $row['TotalTimeSecs'] ) ) {
			return (int) $row['TotalTimeSecs'];
		}

		return self::parse_hhmmss( (string) ( $row['TotalTimehhmmss'] ?? '' ) );
	}

	/**
	 * Convert "h:mm:ss" or "mm:ss" to seconds.
	 *
	 * @param string $value Time string.
	 */
	public static function parse_hhmmss( string $value ): ?int {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		$parts = explode( ':', $value );
		foreach ( $parts as $part ) {
			if ( '' === trim( $part ) || ! is_numeric( trim( $part ) ) ) {
				return null;
			}
		}

		$parts   = array_map( 'intval', $parts );
		$seconds = 0;
		foreach ( $parts as $part ) {
			$seconds = ( $seconds * 60 ) + $part;
		}

		return $seconds;
	}

	/**
	 * Map each result id in a stored payload to what identifies its run.
	 *
	 * The duplicate detector recognises one run recorded twice by its start,
	 * finish and elapsed time; the course revision then tells the co-ordinator
	 * which of two scorings is which. Elapsed time was always stored, the other
	 * three were not, so this reads them back out of the snapshot the import
	 * kept — which is what lets an already-imported season be repaired without
	 * fetching it again.
	 *
	 * A payload that no longer parses yields nothing rather than throwing: a
	 * bad snapshot should leave the columns empty, not stop an upgrade.
	 *
	 * @param string $payload Stored MapRun response.
	 * @return array<string,array{start_local:string,finish_local:string,course_revision:int|null,track_start_utc:string}>
	 */
	public static function identities( string $payload ): array {
		try {
			$rows = self::unwrap( json_decode( $payload, true ) );
		} catch ( \RuntimeException $e ) {
			return array();
		}

		$identities = array();

		foreach ( ( new self() )->parse( $rows ) as $row ) {
			$id = (string) $row['maprun_id'];

			if ( '' === $id ) {
				continue;
			}

			$identities[ $id ] = array(
				'start_local'     => (string) $row['start_local'],
				'finish_local'    => (string) $row['finish_local'],
				'course_revision' => $row['course_revision'],
				'track_start_utc' => (string) $row['track_start_utc'],
			);
		}

		return $identities;
	}

	/**
	 * Render seconds back as MapRun writes them: "mm:ss", or "h:mm:ss" past the hour.
	 *
	 * The inverse of parse_hhmmss(), and kept beside it so the two cannot drift.
	 * MapRun's own TotalTimehhmmss drops the leading hour rather than padding to
	 * "00:59:53", and matching that keeps a time read off the review screen
	 * comparable with the same time read off MapRun.
	 *
	 * @param int|null $seconds Elapsed time.
	 */
	public static function format_hhmmss( ?int $seconds ): string {
		if ( null === $seconds || $seconds < 0 ) {
			return '';
		}

		$hours   = intdiv( $seconds, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );

		return $hours > 0
			? sprintf( '%d:%02d:%02d', $hours, $minutes, $seconds % 60 )
			: sprintf( '%02d:%02d', $minutes, $seconds % 60 );
	}

	/**
	 * Control punches, re-sorted into true chronological order.
	 *
	 * MapRun appends repeat ("Extra") punches to the end of punchControlIds
	 * regardless of when they actually happened, which silently breaks any
	 * order-sensitive logic. Confirmed in a real response, where a row ended
	 * "35 (Extra)", "32 (Extra)" at 725 and 1162 seconds, after preceding
	 * punches at 3085 and 3291.
	 *
	 * @param array<string,mixed> $row Raw MapRun row.
	 * @return array<int,array{control:string,time_secs:int|null,is_extra:bool}>
	 */
	private function ordered_punches( array $row ): array {
		$controls = is_array( $row['punchControlIds'] ?? null ) ? array_values( $row['punchControlIds'] ) : array();
		$times    = is_array( $row['punchTimeAfterStartSecs'] ?? null ) ? array_values( $row['punchTimeAfterStartSecs'] ) : array();

		// Only trust the times when there is exactly one per control; a length
		// mismatch means we cannot pair them, so keep MapRun's given order.
		$sortable = count( $controls ) === count( $times );

		$punches = array();
		foreach ( $controls as $index => $control ) {
			$control = (string) $control;
			$extra   = (bool) preg_match( '/\s*\(Extra\)\s*$/i', $control );

			$punches[] = array(
				'control'   => trim( (string) preg_replace( '/\s*\(Extra\)\s*$/i', '', $control ) ),
				'time_secs' => $sortable && is_numeric( $times[ $index ] ) ? (int) $times[ $index ] : null,
				'is_extra'  => $extra,
			);
		}

		return $sortable ? self::sort_by_time( $punches ) : $punches;
	}

	/**
	 * Stable sort of punches by time, leaving untimed punches in place.
	 *
	 * @param array<int,array<string,mixed>> $punches Punch rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sort_by_time( array $punches ): array {
		$indexed = array();
		foreach ( $punches as $index => $punch ) {
			$indexed[] = array( $index, $punch );
		}

		usort(
			$indexed,
			static function ( array $a, array $b ): int {
				$time_a = $a[1]['time_secs'];
				$time_b = $b[1]['time_secs'];

				if ( null === $time_a || null === $time_b ) {
					return $a[0] <=> $b[0];
				}

				return ( $time_a <=> $time_b ) ?: ( $a[0] <=> $b[0] );
			}
		);

		return array_column( $indexed, 1 );
	}

	/**
	 * Reduce MapRun's gender value to 'F', 'M' or ''.
	 *
	 * @param mixed $value Raw gender value.
	 */
	private function normalise_gender( $value ): string {
		$value = strtoupper( trim( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}

		$initial = $value[0];

		return in_array( $initial, array( 'F', 'M' ), true ) ? $initial : '';
	}

	/**
	 * Inventory every field present across a set of rows, with a sample value.
	 *
	 * Feeds the admin raw-response viewer, so an unfamiliar response can be
	 * inspected without reading raw JSON.
	 *
	 * @param array<int,array<string,mixed>> $rows Raw MapRun rows.
	 * @return array<string,array{count:int,sample:string,type:string}>
	 */
	public static function field_inventory( array $rows ): array {
		$fields = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			foreach ( $row as $key => $value ) {
				$key = (string) $key;

				if ( ! isset( $fields[ $key ] ) ) {
					$fields[ $key ] = array(
						'count'  => 0,
						'sample' => self::describe_value( $value ),
						'type'   => self::describe_type( $value ),
					);
				}

				++$fields[ $key ]['count'];

				// Prefer a non-empty sample over the first one seen.
				if ( '' === $fields[ $key ]['sample'] ) {
					$fields[ $key ]['sample'] = self::describe_value( $value );
				}
			}
		}

		ksort( $fields );

		return $fields;
	}

	/**
	 * Name a value's type for the field inventory.
	 *
	 * gettype() rather than get_debug_type(), which is PHP 8.0+ — the plugin
	 * declares support for 7.4 because club hosting is often behind, and a
	 * diagnostic screen is not worth a fatal error on an older server.
	 *
	 * @param mixed $value Any value.
	 */
	private static function describe_type( $value ): string {
		$type = gettype( $value );

		$names = array(
			'integer' => 'int',
			'double'  => 'float',
			'boolean' => 'bool',
			'NULL'    => 'null',
		);

		return $names[ $type ] ?? $type;
	}

	/**
	 * Render a value compactly for the field inventory.
	 *
	 * @param mixed $value Any value.
	 */
	private static function describe_value( $value ): string {
		if ( is_array( $value ) ) {
			return 'array(' . count( $value ) . ')';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return '';
		}

		$string = (string) $value;

		return strlen( $string ) > 40 ? substr( $string, 0, 40 ) . '...' : $string;
	}
}
