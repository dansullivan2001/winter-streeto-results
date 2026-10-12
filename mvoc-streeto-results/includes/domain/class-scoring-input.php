<?php
/**
 * Reads a scoring definition pasted by the co-ordinator, as JSON or as CSV.
 *
 * JSON is the full format. CSV exists for the common simple case, because a
 * column of control values is what a spreadsheet produces: `control,points`
 * for any-order scoring, or `control,points,order` when the controls must be
 * visited in order. Tabs are accepted as well as commas, for the same reason
 * Manual_Entry_Parser accepts them — they are what a spreadsheet puts on the
 * clipboard.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Turns pasted text into a validated Scoring_Rule_Set.
 */
class Scoring_Input {

	/**
	 * Parse pasted text.
	 *
	 * @param string $text Pasted JSON or CSV.
	 * @param string $name Name to give a CSV definition, which has nowhere to carry one.
	 * @return array{rules:Scoring_Rule_Set|null,errors:string[]}
	 */
	public static function parse( string $text, string $name = '' ): array {
		$text = trim( $text );

		if ( '' === $text ) {
			return array(
				'rules'  => null,
				'errors' => array( 'Nothing was pasted.' ),
			);
		}

		if ( '{' === $text[0] ) {
			return Scoring_Rule_Set::parse( $text );
		}

		return self::from_csv( $text, $name );
	}

	/**
	 * Read `control,points[,order]` lines.
	 *
	 * @param string $text Pasted CSV.
	 * @param string $name Definition name.
	 * @return array{rules:Scoring_Rule_Set|null,errors:string[]}
	 */
	private static function from_csv( string $text, string $name ): array {
		$controls = array();
		$order    = array();
		$errors   = array();

		foreach ( preg_split( '/\R/', $text ) ?: array() as $index => $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$cells = array_map( 'trim', preg_split( '/[\t,]/', $line ) ?: array() );

			// A header row is recognised by its first cell not being a number.
			if ( 0 === $index && ! ctype_digit( $cells[0] ) ) {
				continue;
			}

			if ( ! ctype_digit( $cells[0] ) || ! isset( $cells[1] ) || ! preg_match( '/^-?\d+$/', $cells[1] ) ) {
				$errors[] = sprintf( 'Line %d: expected control number then whole points, got "%s".', $index + 1, $line );
				continue;
			}

			if ( isset( $controls[ $cells[0] ] ) ) {
				$errors[] = sprintf( 'Line %d: control %s appears twice.', $index + 1, $cells[0] );
				continue;
			}

			$controls[ $cells[0] ] = (int) $cells[1];

			if ( isset( $cells[2] ) && '' !== $cells[2] ) {
				if ( ! ctype_digit( $cells[2] ) ) {
					$errors[] = sprintf( 'Line %d: order must be a whole number, got "%s".', $index + 1, $cells[2] );
					continue;
				}

				$order[ $cells[0] ] = (int) $cells[2];
			}
		}

		if ( $order && count( $order ) !== count( $controls ) ) {
			$errors[] = 'Give an order for every control, or for none.';
		}

		if ( ! $controls && ! $errors ) {
			$errors[] = 'No controls were found.';
		}

		if ( $errors ) {
			return array(
				'rules'  => null,
				'errors' => $errors,
			);
		}

		$definition = array(
			'name'                 => $name,
			'scheme'               => $order ? 'sequence' : 'any_order',
			'default_control_rule' => 'none',
			'controls'             => $controls,
		);

		if ( $order ) {
			asort( $order );
			$definition['params'] = array( 'sequence' => array_map( 'strval', array_keys( $order ) ) );
		}

		return Scoring_Rule_Set::from_array( $definition );
	}
}
