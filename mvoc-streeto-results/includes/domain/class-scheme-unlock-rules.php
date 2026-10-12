<?php
/**
 * Unlock scoring: a control scores only once enough other controls have.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Tournament-style scoring, where later rounds are gated by earlier ones.
 *
 * A control with an unlock rule scores only if, at the moment it is punched,
 * at least `at_least` of the controls it names in `after` have already
 * *scored*. A punch that arrives too early is ignored but not lost: the
 * control stays unscored, so punching it again once it is unlocked counts.
 * Rules can chain, so a semi-final can require quarter-finals that themselves
 * required group controls. A control with no rule scores whenever it is
 * punched, including after a later round has been completed.
 *
 * "Scored" is deliberate: a quarter-final punched too early has not scored,
 * so it does not help unlock the semi-final.
 */
class Scheme_Unlock_Rules implements Scoring_Scheme {

	public function id(): string {
		return 'unlock_rules';
	}

	public function label(): string {
		return 'Unlock rules';
	}

	public function describe(): string {
		return 'Each control scores once, but a control listed in params.unlocks only scores once enough other '
			. 'controls have scored. params.unlocks is a map of control number to {"after": [control numbers], '
			. '"at_least": n}: the control scores only if at least n of the "after" controls have already scored '
			. 'when it is punched. A punch made too early does not score, but the control scores if punched again '
			. 'after it is unlocked. Rules can chain (a semi-final needing two quarter-finals that each need a '
			. 'group). Controls with no rule score whenever they are punched. Values come from "controls" and '
			. '"default_control_rule".';
	}

	public function example_params(): array {
		return array(
			'unlocks' => array(
				'19' => array(
					'after'    => array( '11', '12', '13', '14', '15', '16' ),
					'at_least' => 5,
				),
				'29' => array(
					'after'    => array( '21', '22', '23', '24', '25', '26' ),
					'at_least' => 5,
				),
				'79' => array(
					'after'    => array( '19', '29' ),
					'at_least' => 2,
				),
			),
		);
	}

	public function validate_params( array $params ): array {
		$errors = array();

		foreach ( array_keys( $params ) as $key ) {
			if ( 'unlocks' !== $key ) {
				$errors[] = sprintf( 'params.%s: unknown key.', $key );
			}
		}

		$unlocks = $params['unlocks'] ?? null;

		if ( ! is_array( $unlocks ) || ! $unlocks ) {
			$errors[] = 'params.unlocks: a non-empty map of control number to {"after", "at_least"} is required.';

			return $errors;
		}

		foreach ( $unlocks as $control => $rule ) {
			$errors = array_merge( $errors, $this->validate_rule( (string) $control, $rule ) );
		}

		return $errors ? $errors : $this->cycle_errors( $unlocks );
	}

	/**
	 * @param string $control Control the rule is for.
	 * @param mixed  $rule    Candidate rule.
	 * @return string[]
	 */
	private function validate_rule( string $control, $rule ): array {
		$path = 'params.unlocks.' . $control;

		if ( ! is_array( $rule ) ) {
			return array( $path . ': must be {"after": [...], "at_least": n}.' );
		}

		$errors = array();

		foreach ( array_keys( $rule ) as $key ) {
			if ( ! in_array( $key, array( 'after', 'at_least' ), true ) ) {
				$errors[] = sprintf( '%s.%s: unknown key.', $path, $key );
			}
		}

		if ( ! Scheme_Support::is_control_list( $rule['after'] ?? null ) ) {
			$errors[] = $path . '.after: a non-empty list of control numbers is required.';

			return $errors;
		}

		$after = array_map( 'strval', array_values( $rule['after'] ) );

		if ( in_array( $control, $after, true ) ) {
			$errors[] = $path . '.after: a control cannot be unlocked by itself.';
		}

		$needed = $rule['at_least'] ?? null;

		if ( ! is_int( $needed ) || $needed < 1 || $needed > count( array_unique( $after ) ) ) {
			$errors[] = $path . '.at_least: a whole number from 1 to the number of "after" controls is required.';
		}

		return $errors;
	}

	/**
	 * Rules that wait on each other can never unlock, which is always a mistake.
	 *
	 * @param array<string,array<string,mixed>> $unlocks Validated rules.
	 * @return string[]
	 */
	private function cycle_errors( array $unlocks ): array {
		$graph = array();

		foreach ( $unlocks as $control => $rule ) {
			$graph[ (string) $control ] = array_map( 'strval', array_values( $rule['after'] ) );
		}

		$state = array();

		foreach ( array_keys( $graph ) as $start ) {
			if ( $this->has_cycle( $start, $graph, $state ) ) {
				return array( sprintf( 'params.unlocks: control %s is part of a loop of controls that wait on each other.', $start ) );
			}
		}

		return array();
	}

	/**
	 * Depth-first search; state 1 is in progress, 2 is finished.
	 *
	 * @param string                  $node  Control.
	 * @param array<string,string[]>  $graph Control => controls it waits on.
	 * @param array<string,int>       $state Visit state, modified in place.
	 */
	private function has_cycle( string $node, array $graph, array &$state ): bool {
		if ( ( $state[ $node ] ?? 0 ) === 1 ) {
			return true;
		}

		if ( ( $state[ $node ] ?? 0 ) === 2 || ! isset( $graph[ $node ] ) ) {
			return false;
		}

		$state[ $node ] = 1;

		foreach ( $graph[ $node ] as $next ) {
			if ( $this->has_cycle( $next, $graph, $state ) ) {
				return true;
			}
		}

		$state[ $node ] = 2;

		return false;
	}

	public function score( array $punches, Scoring_Rule_Set $rules ): Scheme_Result {
		$result  = new Scheme_Result();
		$unlocks = $rules->params['unlocks'] ?? array();
		$scored  = array();

		foreach ( Scheme_Support::controls_in_order( $punches ) as $control ) {
			if ( isset( $scored[ $control ] ) ) {
				$result->add( Scheme_Result::IGNORED, $control, 0, 'already scored' );
				continue;
			}

			if ( isset( $unlocks[ $control ] ) ) {
				$after = array_map( 'strval', array_values( $unlocks[ $control ]['after'] ) );
				$have  = count( array_intersect( array_unique( $after ), array_keys( $scored ) ) );
				$need  = (int) $unlocks[ $control ]['at_least'];

				if ( $have < $need ) {
					$result->add(
						Scheme_Result::IGNORED,
						$control,
						0,
						sprintf( 'locked: %d of %d needed from %s have scored', $have, $need, implode( ', ', $after ) )
					);
					continue;
				}
			}

			$scored[ $control ] = true;
			$result->add( Scheme_Result::COUNTED, $control, Scheme_Support::points_for( $rules, $control ), '' );
		}

		return $result;
	}
}
