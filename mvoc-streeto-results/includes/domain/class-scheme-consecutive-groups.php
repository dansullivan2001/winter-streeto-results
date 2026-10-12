<?php
/**
 * Consecutive-group scoring: a bonus for finishing a group in one unbroken run.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Controls score as in any-order, plus a bonus per group completed consecutively.
 *
 * A group is complete when every one of its controls was punched in a single
 * unbroken stretch of the run — any order within the group, and no control
 * from another group in between. Controls that belong to no group neither
 * count towards a group nor break a run.
 *
 * Rules that the definition does not state are settled here, and the choices
 * are the narrowest reading of "consecutive": a repeat punch of a control
 * already in the current run does not break it (the rule only forbids other
 * groups' controls); a broken run can be completed on a later clean run; a
 * group earns its bonus once; and with several completed, the bonus follows
 * the order they were completed in.
 */
class Scheme_Consecutive_Groups implements Scoring_Scheme {

	public function id(): string {
		return 'consecutive_groups';
	}

	public function label(): string {
		return 'Consecutive groups';
	}

	public function describe(): string {
		return 'Controls score as in any order (values from "controls" and "default_control_rule"). On top of that, '
			. 'a group earns a bonus when all its controls are visited consecutively: any order within the group, '
			. 'but no control from another group in between. Groups come from params.groups (a map of group name '
			. 'to control list) or from params.group_by "last_digit" with params.control_range [first, last]. '
			. 'params.bonus is either a list of points for the 1st, 2nd, 3rd... group completed (the last value '
			. 'repeats), or {"start": n, "step": n} for a bonus that rises with each group completed. A group '
			. 'earns its bonus once; a broken attempt can be completed later.';
	}

	public function example_params(): array {
		return array(
			'group_by'      => 'last_digit',
			'control_range' => array( 10, 59 ),
			'bonus'         => array(
				'start' => 20,
				'step'  => 10,
			),
		);
	}

	public function validate_params( array $params ): array {
		$errors = array();
		$known  = array( 'groups', 'group_by', 'control_range', 'bonus' );

		foreach ( array_keys( $params ) as $key ) {
			if ( ! in_array( $key, $known, true ) ) {
				$errors[] = sprintf( 'params.%s: unknown key.', $key );
			}
		}

		$has_groups = isset( $params['groups'] );
		$has_digit  = isset( $params['group_by'] );

		if ( $has_groups === $has_digit ) {
			$errors[] = 'params: give either "groups" or "group_by", not both and not neither.';
		}

		if ( $has_groups ) {
			$errors = array_merge( $errors, $this->validate_groups( $params['groups'] ) );
		}

		if ( $has_digit ) {
			$errors = array_merge( $errors, $this->validate_digit_groups( $params ) );
		}

		return array_merge( $errors, $this->validate_bonus( $params['bonus'] ?? null ) );
	}

	/**
	 * @param mixed $groups Candidate params.groups.
	 * @return string[]
	 */
	private function validate_groups( $groups ): array {
		if ( ! is_array( $groups ) || ! $groups ) {
			return array( 'params.groups: a non-empty map of group name to control list is required.' );
		}

		$errors = array();
		$owner  = array();

		foreach ( $groups as $name => $controls ) {
			if ( ! Scheme_Support::is_control_list( $controls ) ) {
				$errors[] = sprintf( 'params.groups.%s: a non-empty list of control numbers is required.', $name );
				continue;
			}

			foreach ( $controls as $control ) {
				$control = trim( (string) $control );

				if ( isset( $owner[ $control ] ) && (string) $name !== $owner[ $control ] ) {
					$errors[] = sprintf( 'params.groups: control %s is in both "%s" and "%s".', $control, $owner[ $control ], $name );
				}

				$owner[ $control ] = (string) $name;
			}
		}

		return $errors;
	}

	/**
	 * @param array<string,mixed> $params Scheme params.
	 * @return string[]
	 */
	private function validate_digit_groups( array $params ): array {
		$errors = array();

		if ( 'last_digit' !== $params['group_by'] ) {
			$errors[] = 'params.group_by: only "last_digit" is supported.';
		}

		$range = $params['control_range'] ?? null;

		if ( ! is_array( $range ) || 2 !== count( $range ) || ! is_int( $range[0] ?? null ) || ! is_int( $range[1] ?? null ) || $range[0] > $range[1] ) {
			$errors[] = 'params.control_range: [first, last] whole numbers, first not above last, is required with group_by.';
		}

		return $errors;
	}

	/**
	 * @param mixed $bonus Candidate params.bonus.
	 * @return string[]
	 */
	private function validate_bonus( $bonus ): array {
		if ( ! is_array( $bonus ) || ! $bonus ) {
			return array( 'params.bonus: a list of points, or {"start", "step"}, is required.' );
		}

		if ( isset( $bonus['start'] ) || isset( $bonus['step'] ) ) {
			$valid = 2 === count( $bonus ) && is_int( $bonus['start'] ?? null ) && is_int( $bonus['step'] ?? null );

			return $valid ? array() : array( 'params.bonus: {"start", "step"} must hold exactly those two whole numbers.' );
		}

		foreach ( $bonus as $points ) {
			if ( ! is_int( $points ) ) {
				return array( 'params.bonus: every entry in the list must be a whole number.' );
			}
		}

		return array();
	}

	public function score( array $punches, Scoring_Rule_Set $rules ): Scheme_Result {
		$result = ( new Scheme_Any_Order() )->score( $punches, $rules );
		$groups = $this->groups( $rules->params );
		$owner  = array();

		foreach ( $groups as $name => $controls ) {
			foreach ( $controls as $control ) {
				$owner[ $control ] = $name;
			}
		}

		$completed = array();
		$run_group = null;
		$run       = array();

		foreach ( Scheme_Support::controls_in_order( $punches ) as $control ) {
			if ( ! isset( $owner[ $control ] ) ) {
				continue;
			}

			$group = $owner[ $control ];

			if ( $group !== $run_group ) {
				$run_group = $group;
				$run       = array();
			}

			$run[ $control ] = true;

			if ( isset( $completed[ $group ] ) || count( $run ) < count( $groups[ $group ] ) ) {
				continue;
			}

			$completed[ $group ] = true;
			$result->add(
				Scheme_Result::BONUS,
				(string) $group,
				$this->bonus_for( count( $completed ), $rules->params['bonus'] ),
				sprintf( 'group %s completed consecutively (group number %d completed)', $group, count( $completed ) )
			);
		}

		return $result;
	}

	/**
	 * The bonus for the nth group completed.
	 *
	 * @param int                $nth   1-based count of groups completed so far.
	 * @param array<int|string,int> $bonus params.bonus, as validated.
	 */
	private function bonus_for( int $nth, array $bonus ): int {
		if ( isset( $bonus['start'] ) ) {
			return (int) $bonus['start'] + ( $nth - 1 ) * (int) $bonus['step'];
		}

		$list = array_values( $bonus );

		return (int) ( $list[ $nth - 1 ] ?? end( $list ) );
	}

	/**
	 * Group name => its control ids, from either way of declaring them.
	 *
	 * @param array<string,mixed> $params Scheme params.
	 * @return array<string,string[]>
	 */
	private function groups( array $params ): array {
		if ( isset( $params['groups'] ) ) {
			$groups = array();

			foreach ( $params['groups'] as $name => $controls ) {
				$groups[ (string) $name ] = array_map(
					static fn( $control ): string => trim( (string) $control ),
					array_values( $controls )
				);
			}

			return $groups;
		}

		$groups = array();

		for ( $number = (int) $params['control_range'][0]; $number <= (int) $params['control_range'][1]; $number++ ) {
			$groups[ (string) ( $number % 10 ) ][] = (string) $number;
		}

		return $groups;
	}
}
