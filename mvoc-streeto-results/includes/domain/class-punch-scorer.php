<?php
/**
 * Works out what a run scored from the controls it punched.
 *
 * Normally nothing needs this: MapRun scores a StreetO event itself and the
 * plugin takes its word, reading GrossScore off each row. But MapRun only
 * scores an event it recognises as a score event, and whether it does comes
 * down to the course name. Burpham, September 2026, was set up as
 * "Score Q60" with a space in it. MapRun did not recognise that as a score
 * course, marked all 44 finishers MP, and returned GrossScore 0 for every one
 * of them — while recording every punch, in order, with its split time.
 *
 * So the scores were never lost, only unreported, and a co-ordinator faced
 * keying in forty-four totals by hand from punch lists. This reconstructs them
 * instead.
 *
 * The rule is the club's own and is a property of how StreetO controls are
 * numbered rather than of any event: a control is worth its first digit times
 * ten, so 13 scores 10, 27 scores 20 and 55 scores 50. Burpham's fifty
 * controls were numbered 10 to 59, ten in each band, which is what that
 * numbering is for.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Scores a set of punches under the club's control-numbering rule.
 */
class Punch_Scorer {

	/**
	 * Points a control is worth for each ten in its number.
	 */
	public const POINTS_PER_BAND = 10;

	/**
	 * Size of a numbering band: controls 20-29 are all worth the same.
	 */
	public const BAND_SIZE = 10;

	/**
	 * What a run scored, or null if there is nothing to score from.
	 *
	 * Null rather than zero for an empty punch list, and the difference
	 * matters: a failed upload records no punches at all, and calling that nil
	 * points would turn a phone that never uploaded into a runner who scored
	 * nothing. Zero is returned only where there really were punches and none
	 * of them was worth anything.
	 *
	 * @param array<int,array<string,mixed>> $punches Punches, as Parser returns them.
	 */
	public function score( array $punches ): ?int {
		if ( ! $punches ) {
			return null;
		}

		$score = 0;

		foreach ( self::distinct_controls( $punches ) as $control ) {
			$score += self::points_for( $control );
		}

		return $score;
	}

	/**
	 * Every control a run visited, each counted once.
	 *
	 * A control scores once however many times it is punched. MapRun marks the
	 * repeats "(Extra)" and the parser strips that into an `is_extra` flag, but
	 * the flag is not what is trusted here: the control number is. A repeat
	 * that arrived without the marker — or one the parser has already re-sorted
	 * into time order, which moves the marked punch away from the end — would
	 * otherwise score twice.
	 *
	 * @param array<int,array<string,mixed>> $punches Punches, as Parser returns them.
	 * @return string[]
	 */
	private static function distinct_controls( array $punches ): array {
		$seen = array();

		foreach ( $punches as $punch ) {
			$control = trim( (string) ( $punch['control'] ?? '' ) );

			if ( '' !== $control ) {
				$seen[ $control ] = true;
			}
		}

		return array_keys( $seen );
	}

	/**
	 * What one control is worth.
	 *
	 * Anything that is not a plain number scores nothing rather than guessing:
	 * a start or finish punch carries no points, and a control id the club
	 * never used is not evidence of anything. Below ten is zero by the same
	 * arithmetic the rest of the rule uses, which is the right answer for the
	 * start control some courses number 1.
	 *
	 * @param string $control Control id, as MapRun sends it.
	 */
	public static function points_for( string $control ): int {
		$control = trim( $control );

		if ( ! preg_match( '/^\d+$/', $control ) ) {
			return 0;
		}

		return intdiv( (int) $control, self::BAND_SIZE ) * self::POINTS_PER_BAND;
	}
}
