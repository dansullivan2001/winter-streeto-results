<?php
/**
 * Any-order scoring: each control scores once, whenever it was visited.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The club's standard format, with control values that can be set per event.
 */
class Scheme_Any_Order implements Scoring_Scheme {

	public function id(): string {
		return 'any_order';
	}

	public function label(): string {
		return 'Any order';
	}

	public function describe(): string {
		return 'Controls can be visited in any order and each scores once. Values come from "controls" '
			. '(control number to points), with unlisted controls worth what "default_control_rule" says. '
			. 'Takes no params.';
	}

	public function example_params(): array {
		return array();
	}

	public function validate_params( array $params ): array {
		return $params ? array( 'params: this scheme takes no parameters.' ) : array();
	}

	public function score( array $punches, Scoring_Rule_Set $rules ): Scheme_Result {
		$result = new Scheme_Result();
		$seen   = array();

		foreach ( Scheme_Support::controls_in_order( $punches ) as $control ) {
			if ( isset( $seen[ $control ] ) ) {
				$result->add( Scheme_Result::IGNORED, $control, 0, 'already scored' );
				continue;
			}

			$seen[ $control ] = true;
			$points           = Scheme_Support::points_for( $rules, $control );
			$result->add( Scheme_Result::COUNTED, $control, $points, '' );
		}

		return $result;
	}
}
