<?php
/**
 * Fixed-order scoring: controls must be visited in a set order.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Scores the controls matched, in order, along a fixed sequence.
 */
class Scheme_Sequence implements Scoring_Scheme {

	public function id(): string {
		return 'sequence';
	}

	public function label(): string {
		return 'Fixed order';
	}

	public function describe(): string {
		return 'Controls must be visited in the order given by params.sequence (a list of control numbers). '
			. 'A punch scores only when it is the next control expected; any other punch is ignored and the '
			. 'run carries on, so a runner who stops part-way keeps what they completed. Values come from '
			. '"controls" and "default_control_rule".';
	}

	public function example_params(): array {
		return array( 'sequence' => array( '31', '32', '33', '34', '35' ) );
	}

	public function validate_params( array $params ): array {
		$errors = array();

		foreach ( array_keys( $params ) as $key ) {
			if ( 'sequence' !== $key ) {
				$errors[] = sprintf( 'params.%s: unknown key.', $key );
			}
		}

		if ( ! Scheme_Support::is_control_list( $params['sequence'] ?? null ) ) {
			$errors[] = 'params.sequence: a non-empty list of control numbers is required.';
		}

		return $errors;
	}

	public function score( array $punches, Scoring_Rule_Set $rules ): Scheme_Result {
		$result   = new Scheme_Result();
		$sequence = array_map( 'strval', array_values( $rules->params['sequence'] ?? array() ) );
		$next     = 0;

		foreach ( Scheme_Support::controls_in_order( $punches ) as $control ) {
			if ( isset( $sequence[ $next ] ) && $sequence[ $next ] === $control ) {
				$result->add(
					Scheme_Result::COUNTED,
					$control,
					Scheme_Support::points_for( $rules, $control ),
					sprintf( 'number %d in the sequence', $next + 1 )
				);
				++$next;
				continue;
			}

			$result->add(
				Scheme_Result::IGNORED,
				$control,
				0,
				isset( $sequence[ $next ] ) ? 'out of sequence, expected ' . $sequence[ $next ] : 'sequence already finished'
			);
		}

		return $result;
	}
}
