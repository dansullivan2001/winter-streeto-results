<?php
/**
 * The list of scoring schemes the plugin knows.
 *
 * Adding a scheme is writing its class and adding the class name to
 * SCHEMES. The validator, the admin dropdown, the Help text and the AI prompt
 * all read from here, so nothing else needs to learn about it.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Looks schemes up by their `scheme` id.
 */
class Scoring_Scheme_Registry {

	/**
	 * Every scheme, as class names in this namespace.
	 *
	 * @var string[]
	 */
	private const SCHEMES = array(
		Scheme_Any_Order::class,
		Scheme_Sequence::class,
		Scheme_Consecutive_Groups::class,
		Scheme_Unlock_Rules::class,
	);

	/**
	 * All schemes keyed by id.
	 *
	 * @return array<string,Scoring_Scheme>
	 */
	public static function all(): array {
		$schemes = array();

		foreach ( self::SCHEMES as $class ) {
			$scheme                    = new $class();
			$schemes[ $scheme->id() ] = $scheme;
		}

		return $schemes;
	}

	/**
	 * One scheme by id, or null if there is none.
	 *
	 * @param string $id Scheme id.
	 */
	public static function find( string $id ): ?Scoring_Scheme {
		return self::all()[ $id ] ?? null;
	}
}
