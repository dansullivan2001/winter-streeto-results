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
 *              Classifier, StartPunchTimeLocal, FinishPunchTimeLocal,
 *              TotalTimehhmmss, TotalTimeSecs, GrossScore, NetScore, Distance,
 *              Pacemmss, PaceMins, MapRunVersion, punchControlIds[],
 *              punchTimeAfterStartSecs[]
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
			'course_label'    => $course_label,
			'start_local'     => trim( (string) ( $row['StartPunchTimeLocal'] ?? '' ) ),
			'finish_local'    => trim( (string) ( $row['FinishPunchTimeLocal'] ?? '' ) ),
			'time_display'    => trim( (string) ( $row['TotalTimehhmmss'] ?? '' ) ),
			'time_secs'       => $this->extract_time_secs( $row ),
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
	 * Year of birth, used to derive the Over-55 category.
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
