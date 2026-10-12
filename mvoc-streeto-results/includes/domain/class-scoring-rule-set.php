<?php
/**
 * A named, reusable definition of how an event is scored from its punches.
 *
 * Held as JSON so it can be written by hand, pasted in, or produced by an AI
 * from a description of the event. Because of that, parsing is strict: an
 * unknown key is an error rather than being ignored, since a misspelt key that
 * quietly does nothing publishes the wrong scores.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable-in-practice value object for one scoring definition.
 */
class Scoring_Rule_Set {

	/** Unlisted controls are worth the club's standard: the tens band of their number. */
	public const DEFAULT_TENS = 'tens';

	/** Unlisted controls are worth nothing. */
	public const DEFAULT_NONE = 'none';

	/** Format version of the JSON, so the schema can grow. */
	public const VERSION = 1;

	public string $name = '';

	public string $description = '';

	public string $scheme = 'any_order';

	/**
	 * Control id => points, for controls that are not worth the default.
	 *
	 * @var array<string,int>
	 */
	public array $controls = array();

	public string $default_control_rule = self::DEFAULT_TENS;

	/**
	 * Parameters for the scheme.
	 *
	 * @var array<string,mixed>
	 */
	public array $params = array();

	/**
	 * Read and validate a definition.
	 *
	 * @param string $json Definition as JSON.
	 * @return array{rules:Scoring_Rule_Set|null,errors:string[]}
	 */
	public static function parse( string $json ): array {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || ( $data && array_keys( $data ) === range( 0, count( $data ) - 1 ) ) ) {
			return array(
				'rules'  => null,
				'errors' => array( 'The definition is not valid JSON, or is not an object ({ ... }).' ),
			);
		}

		return self::from_array( $data );
	}

	/**
	 * Validate and build from a decoded definition.
	 *
	 * @param array<string,mixed> $data Decoded JSON.
	 * @return array{rules:Scoring_Rule_Set|null,errors:string[]}
	 */
	public static function from_array( array $data ): array {
		$errors = array();
		$known  = array( 'version', 'name', 'description', 'scheme', 'controls', 'default_control_rule', 'params' );

		foreach ( array_keys( $data ) as $key ) {
			if ( ! in_array( $key, $known, true ) ) {
				$errors[] = sprintf( '%s: unknown key.', $key );
			}
		}

		if ( isset( $data['version'] ) && self::VERSION !== $data['version'] ) {
			$errors[] = sprintf( 'version: only version %d is supported.', self::VERSION );
		}

		$rules = new self();

		foreach ( array( 'name', 'description' ) as $field ) {
			if ( isset( $data[ $field ] ) && ! is_string( $data[ $field ] ) ) {
				$errors[] = sprintf( '%s: must be text.', $field );
			} elseif ( isset( $data[ $field ] ) ) {
				$rules->$field = trim( $data[ $field ] );
			}
		}

		if ( isset( $data['default_control_rule'] ) ) {
			if ( ! in_array( $data['default_control_rule'], array( self::DEFAULT_TENS, self::DEFAULT_NONE ), true ) ) {
				$errors[] = 'default_control_rule: must be "tens" or "none".';
			} else {
				$rules->default_control_rule = $data['default_control_rule'];
			}
		}

		$errors = array_merge( $errors, $rules->read_controls( $data['controls'] ?? array() ) );

		$scheme = Scoring_Scheme_Registry::find( (string) ( $data['scheme'] ?? '' ) );

		if ( null === $scheme ) {
			$errors[] = sprintf(
				'scheme: must be one of %s.',
				implode( ', ', array_keys( Scoring_Scheme_Registry::all() ) )
			);
		} else {
			$rules->scheme = $scheme->id();
			$params        = $data['params'] ?? array();

			if ( ! is_array( $params ) ) {
				$errors[] = 'params: must be an object.';
			} else {
				$rules->params = $params;
				$errors        = array_merge( $errors, $scheme->validate_params( $params ) );
			}
		}

		return array(
			'rules'  => $errors ? null : $rules,
			'errors' => $errors,
		);
	}

	/**
	 * Read the controls map, reporting anything that is not control => whole points.
	 *
	 * @param mixed $controls Candidate.
	 * @return string[]
	 */
	private function read_controls( $controls ): array {
		if ( ! is_array( $controls ) ) {
			return array( 'controls: must be an object of control number to points.' );
		}

		$errors = array();

		foreach ( $controls as $control => $points ) {
			if ( ! is_int( $points ) ) {
				$errors[] = sprintf( 'controls.%s: points must be a whole number.', $control );
				continue;
			}

			$this->controls[ (string) $control ] = $points;
		}

		return $errors;
	}

	/**
	 * Score a run under this definition.
	 *
	 * Null where there are no punches at all: a failed upload is not a nil score.
	 *
	 * @param array<int,array<string,mixed>> $punches Punches, as Parser returns them.
	 */
	public function score( array $punches ): Scheme_Result {
		$scheme = Scoring_Scheme_Registry::find( $this->scheme );

		if ( ! $punches || null === $scheme ) {
			return new Scheme_Result( null );
		}

		return $scheme->score( $punches, $this );
	}

	/**
	 * Serialise for storage.
	 */
	public function to_json(): string {
		return (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array(
				'version'              => self::VERSION,
				'name'                 => $this->name,
				'description'          => $this->description,
				'scheme'               => $this->scheme,
				'default_control_rule' => $this->default_control_rule,
				'controls'             => (object) $this->controls,
				'params'               => (object) $this->params,
			)
		);
	}
}
