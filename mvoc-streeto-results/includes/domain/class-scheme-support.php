<?php
/**
 * Helpers shared by the scoring schemes, so each stays short.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Control values and punch handling common to every scheme.
 */
class Scheme_Support {

	/**
	 * The control ids in a punch list, in the order given.
	 *
	 * Blank ids are dropped. Order is whatever the parser delivered, which is
	 * chronological where MapRun supplied times.
	 *
	 * @param array<int,array<string,mixed>> $punches Punches.
	 * @return string[]
	 */
	public static function controls_in_order( array $punches ): array {
		$controls = array();

		foreach ( $punches as $punch ) {
			$control = trim( (string) ( $punch['control'] ?? '' ) );

			if ( '' !== $control ) {
				$controls[] = $control;
			}
		}

		return $controls;
	}

	/**
	 * What a control is worth under a rule set.
	 *
	 * An explicit entry in `controls` wins; otherwise the default rule decides.
	 * `tens` is the club's standard, `none` makes anything unlisted worthless.
	 *
	 * @param Scoring_Rule_Set $rules   Rule set.
	 * @param string           $control Control id.
	 */
	public static function points_for( Scoring_Rule_Set $rules, string $control ): int {
		if ( isset( $rules->controls[ $control ] ) ) {
			return (int) $rules->controls[ $control ];
		}

		if ( Scoring_Rule_Set::DEFAULT_TENS === $rules->default_control_rule ) {
			return Punch_Scorer::points_for( $control );
		}

		return 0;
	}

	/**
	 * Whether a value is a list of non-empty control ids.
	 *
	 * @param mixed $value Candidate.
	 */
	public static function is_control_list( $value ): bool {
		if ( ! is_array( $value ) || ! $value ) {
			return false;
		}

		foreach ( $value as $control ) {
			if ( ! is_scalar( $control ) || '' === trim( (string) $control ) ) {
				return false;
			}
		}

		return true;
	}
}
