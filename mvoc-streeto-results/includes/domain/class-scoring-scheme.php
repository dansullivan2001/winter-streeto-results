<?php
/**
 * The contract every custom scoring scheme implements.
 *
 * A scheme turns one runner's punches into a score. Everything else — ranking,
 * the late penalty, the pro-rata, league points — happens downstream of the
 * score and does not care how it was reached, which is what lets a new scheme
 * be a single file: write a class implementing this, add its name to
 * Scoring_Scheme_Registry, and the validator, the admin screens and the AI
 * prompt all pick it up.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A way of scoring a set of punches.
 */
interface Scoring_Scheme {

	/**
	 * The `scheme` value in a rule set's JSON, e.g. `consecutive_groups`.
	 */
	public function id(): string;

	/**
	 * A short name for the admin screens.
	 */
	public function label(): string;

	/**
	 * What the scheme does and what `params` it reads, in plain English.
	 *
	 * Shown on the Help screen and copied into the AI prompt, so it has to be
	 * enough for someone to write a valid definition from.
	 */
	public function describe(): string;

	/**
	 * A valid `params` value, used as the worked example in the prompt.
	 *
	 * @return array<string,mixed>
	 */
	public function example_params(): array;

	/**
	 * Problems with a rule set's `params`, each naming the offending key.
	 *
	 * @param array<string,mixed> $params Scheme parameters.
	 * @return string[] Empty when valid.
	 */
	public function validate_params( array $params ): array;

	/**
	 * Score a run.
	 *
	 * @param array<int,array<string,mixed>> $punches Punches, as Parser returns them: chronological, each with `control`, `time_secs`, `is_extra`.
	 * @param Scoring_Rule_Set               $rules   The rule set being applied.
	 */
	public function score( array $punches, Scoring_Rule_Set $rules ): Scheme_Result;
}
