<?php
/**
 * Scoring rules for a series, held as data rather than code.
 *
 * Defaults reproduce the club's 2019/20 workbook, whose formulas were the
 * specification for this engine. Everything here is stored as JSON on the
 * series row, so a rule change next season is a settings edit.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object describing how a series is scored.
 */
class Scoring_Config {

	/** Round a scaled score to the nearest whole point. */
	public const ROUND_NEAREST = 'nearest';

	/** Round a scaled score down to a whole point. */
	public const ROUND_DOWN = 'down';

	/** Round a scaled score to the nearest ten. */
	public const ROUND_NEAREST_TEN = 'nearest_ten';

	/** Organiser bonus competes for one of the counting slots (workbook behaviour). */
	public const BONUS_COMPETES = 'competes';

	/** Organiser bonus is added on top of the counting scores. */
	public const BONUS_ADDED = 'added';

	/**
	 * How many event scores count towards the league total.
	 */
	public int $counting_events = 5;

	/**
	 * League points by finishing position: index 0 is first place.
	 *
	 * @var int[]
	 */
	public array $points_ladder;

	/**
	 * League points for any position beyond the end of the ladder.
	 */
	public int $points_below_ladder = 1;

	/**
	 * Course label => multiplier applied to bring scores onto a common scale.
	 *
	 * The 45-minute course is scaled by 60/45 so both courses can be ranked in
	 * one combined table.
	 *
	 * @var array<string,float>
	 */
	public array $course_factors;

	/**
	 * One of the ROUND_* constants.
	 */
	public string $rounding = self::ROUND_NEAREST;

	/**
	 * Whether the time penalty is deducted before the pro-rata scaling.
	 *
	 * Assumed true pending confirmation from the club. When false, the penalty
	 * is deducted from the already-scaled score instead, which makes lateness
	 * cost a 45-minute runner the same as a 60-minute one.
	 */
	public bool $penalty_before_scaling = true;

	/**
	 * Whether the tie-break compares raw penalties or scaled ones.
	 *
	 * Follows from penalty_before_scaling and is likewise unconfirmed.
	 */
	public bool $tiebreak_on_raw_penalty = true;

	/**
	 * One of the BONUS_* constants.
	 */
	public string $organiser_bonus_mode = self::BONUS_COMPETES;

	/**
	 * Build with workbook defaults, overridden by anything supplied.
	 *
	 * @param array<string,mixed> $overrides Partial configuration.
	 */
	public function __construct( array $overrides = array() ) {
		$this->points_ladder  = self::default_ladder();
		$this->course_factors = array(
			'60' => 1.0,
			'45' => 60 / 45,
		);

		foreach ( $overrides as $key => $value ) {
			if ( property_exists( $this, $key ) && null !== $value ) {
				$this->$key = $value;
			}
		}
	}

	/**
	 * The workbook's ladder: 1st scores 50, falling by one to 50th scoring 1.
	 *
	 * @return int[]
	 */
	private static function default_ladder(): array {
		$ladder = array();
		for ( $position = 1; $position <= 50; $position++ ) {
			$ladder[] = 51 - $position;
		}

		return $ladder;
	}

	/**
	 * League points awarded for a finishing position.
	 *
	 * @param int $position 1-based finishing position.
	 */
	public function points_for_position( int $position ): int {
		if ( $position < 1 ) {
			return 0;
		}

		return $this->points_ladder[ $position - 1 ] ?? $this->points_below_ladder;
	}

	/**
	 * Multiplier for a course, defaulting to 1 for an unrecognised label.
	 *
	 * An unknown label must not silently scale a score; leaving it at 1 means a
	 * mislabelled course shows up as an odd result rather than a wrong one.
	 *
	 * @param string $course_label Course label, e.g. '60'.
	 */
	public function factor_for_course( string $course_label ): float {
		return $this->course_factors[ $course_label ] ?? 1.0;
	}

	/**
	 * Apply the configured rounding to a scaled score.
	 *
	 * @param float $value Scaled score.
	 */
	public function round_score( float $value ): int {
		switch ( $this->rounding ) {
			case self::ROUND_DOWN:
				return (int) floor( $value );

			case self::ROUND_NEAREST_TEN:
				return (int) ( round( $value / 10 ) * 10 );

			case self::ROUND_NEAREST:
			default:
				return (int) round( $value );
		}
	}

	/**
	 * Rebuild from stored JSON.
	 *
	 * @param string $json Serialised configuration.
	 */
	public static function from_json( string $json ): self {
		$decoded = json_decode( $json, true );

		return new self( is_array( $decoded ) ? $decoded : array() );
	}

	/**
	 * Serialise for storage on the series row.
	 *
	 * Plain json_encode rather than wp_json_encode: this class is kept free of
	 * WordPress so it can be unit-tested without one, and there is no output
	 * escaping to do on a config object.
	 */
	public function to_json(): string {
		return (string) json_encode( get_object_vars( $this ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}
