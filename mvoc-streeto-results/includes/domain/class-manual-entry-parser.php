<?php
/**
 * Reads hand-entered results.
 *
 * Two situations need this. A single runner whose phone failed, whose result is
 * known on paper. And an event MapRun cannot score at all — a different scoring
 * scheme, or a course it was never set up for — where the whole field has to be
 * keyed in.
 *
 * The second is why this accepts a pasted block rather than only a form. Forty
 * runners entered one at a time is an evening's work; pasting a column out of a
 * spreadsheet is a moment. So tabs are accepted alongside commas, because tabs
 * are what a spreadsheet actually puts on the clipboard.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Parses pasted result lines into rows.
 */
class Manual_Entry_Parser {

	/**
	 * Words that mark a line as a spreadsheet header rather than a runner.
	 *
	 * @var string[]
	 */
	private const HEADER_WORDS = array( 'name', 'runner', 'competitor' );

	/**
	 * Parse a pasted block.
	 *
	 * Every line is `Name` followed by optional `Score`, `Penalty` and
	 * `Course`, separated by tabs or commas. A line that cannot be read is
	 * reported with its number rather than silently dropped — a result quietly
	 * missing is far worse than one the co-ordinator is told about.
	 *
	 * @param string $text          Pasted text.
	 * @param string $default_course Course to assume when a line omits it.
	 * @return array{rows:array<int,array<string,mixed>>,errors:string[]}
	 */
	public function parse( string $text, string $default_course = '60' ): array {
		$rows   = array();
		$errors = array();

		foreach ( preg_split( '/\R/', $text ) ?: array() as $index => $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$fields = self::split( $line );
			$name   = trim( array_shift( $fields ) ?? '' );

			if ( '' === $name ) {
				continue;
			}

			if ( 0 === $index && self::looks_like_a_header( $name ) ) {
				continue;
			}

			$score = trim( (string) ( $fields[0] ?? '' ) );

			if ( '' !== $score && ! is_numeric( $score ) ) {
				$errors[] = sprintf(
					'Line %d: "%s" is not a number, so %s was skipped.',
					$index + 1,
					$score,
					$name
				);
				continue;
			}

			$penalty = trim( (string) ( $fields[1] ?? '' ) );
			$course  = trim( (string) ( $fields[2] ?? '' ) );
			$course  = preg_replace( '/[^0-9]/', '', $course );

			$rows[] = self::row(
				$name,
				'' === $score ? null : (int) $score,
				is_numeric( $penalty ) ? (int) $penalty : 0,
				'' !== $course ? $course : $default_course
			);
		}

		return array(
			'rows'   => $rows,
			'errors' => $errors,
		);
	}

	/**
	 * Build a single row from its parts.
	 *
	 * @param string   $name    Full name.
	 * @param int|null $score   Score, or null if not known.
	 * @param int      $penalty Time penalty.
	 * @param string   $course  Course label.
	 * @return array<string,mixed>
	 */
	public static function row( string $name, ?int $score, int $penalty, string $course ): array {
		list( $first, $surname ) = self::split_name( $name );

		return array(
			'first_name'   => $first,
			'surname'      => $surname,
			'display_name' => trim( $name ),
			'score'        => $score,
			'penalty'      => max( 0, $penalty ),
			'course_label' => '' !== $course ? $course : '60',
		);
	}

	/**
	 * Split a line on tabs or commas.
	 *
	 * Tabs win where both appear: a name like "Smith, Dave" pasted from a
	 * spreadsheet would otherwise be read as a name and a score.
	 *
	 * @param string $line One line.
	 * @return string[]
	 */
	private static function split( string $line ): array {
		if ( false !== strpos( $line, "\t" ) ) {
			return explode( "\t", $line );
		}

		return explode( ',', $line );
	}

	/**
	 * Split a full name into a first name and a surname.
	 *
	 * Everything after the first space is the surname, so double-barrelled and
	 * multi-word surnames survive intact — hyphenated and apostrophed surnames
	 * both appear in the club's real data.
	 *
	 * @param string $name Full name.
	 * @return array{0:string,1:string}
	 */
	public static function split_name( string $name ): array {
		$parts = preg_split( '/\s+/', trim( $name ), 2 );

		return array( $parts[0] ?? '', $parts[1] ?? '' );
	}

	/**
	 * Whether the first line is a spreadsheet header rather than a runner.
	 *
	 * @param string $first_field The line's first field.
	 */
	private static function looks_like_a_header( string $first_field ): bool {
		return in_array( strtolower( trim( $first_field ) ), self::HEADER_WORDS, true );
	}
}
