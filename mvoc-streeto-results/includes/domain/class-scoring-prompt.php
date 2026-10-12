<?php
/**
 * The prompt a co-ordinator gives an AI to turn a description of an event into
 * a scoring definition.
 *
 * Built from the scheme registry, so it can never describe a scheme the
 * plugin lacks or omit one it has, and every example in it is checked by a
 * test against the real validator.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Generates the AI prompt text.
 */
class Scoring_Prompt {

	/**
	 * The full prompt, ready to paste above a description of the event.
	 */
	public static function text(): string {
		$lines = array(
			'You are helping set up scoring for a score-format street orienteering event. Turn my description of the event into ONE JSON object, and nothing else, in the format below. Use only the schemes listed. If my description cannot be expressed with them, say so instead of inventing keys. After the JSON, list any assumptions you made.',
			'',
			'Format:',
			'{',
			'  "version": 1,',
			'  "name": "short name for this scoring",',
			'  "description": "one sentence for the results page footnote",',
			'  "scheme": "<one of the scheme ids below>",',
			'  "default_control_rule": "tens" or "none",',
			'  "controls": { "<control number>": <whole points>, ... },',
			'  "params": { <parameters for the scheme> }',
			'}',
			'',
			'"controls" lists controls worth something other than the default. "default_control_rule" says what an unlisted control is worth: "tens" means the tens digit of its number times ten (13 is worth 10, 27 is worth 20, 55 is worth 50), "none" means nothing. Control numbers and points are plain text keys and whole numbers. Unknown keys are rejected.',
			'',
			'Schemes:',
		);

		foreach ( Scoring_Scheme_Registry::all() as $id => $scheme ) {
			$lines[] = '';
			$lines[] = sprintf( '- "%s" (%s): %s', $id, $scheme->label(), $scheme->describe() );
			$lines[] = '  Example: ' . self::example( $scheme );
		}

		$lines[] = '';
		$lines[] = 'My event:';

		return implode( "\n", $lines );
	}

	/**
	 * A scheme's example definition, as one line of JSON.
	 *
	 * @param Scoring_Scheme $scheme Scheme.
	 */
	private static function example( Scoring_Scheme $scheme ): string {
		return (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array(
				'version' => Scoring_Rule_Set::VERSION,
				'name'    => 'Example',
				'scheme'  => $scheme->id(),
				'params'  => (object) $scheme->example_params(),
			)
		);
	}
}
