<?php
/**
 * What a scoring scheme made of one run.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A score and the account of how it was reached.
 *
 * The breakdown is the audit trail: the co-ordinator is putting their name to
 * a number the plugin worked out, so every punch that did or did not count
 * has to be explainable on the review screen.
 */
class Scheme_Result {

	public const COUNTED = 'counted';
	public const IGNORED = 'ignored';
	public const BONUS   = 'bonus';

	/**
	 * Null when there was nothing to score from, which is not the same as nil.
	 */
	public ?int $score;

	/**
	 * One entry per thing that happened: outcome, control, points, note.
	 *
	 * @var array<int,array{outcome:string,control:string,points:int,note:string}>
	 */
	public array $breakdown = array();

	/**
	 * @param int|null $score Total, or null for no punches.
	 */
	public function __construct( ?int $score = null ) {
		$this->score = $score;
	}

	/**
	 * Record an event and add its points to the running total.
	 *
	 * @param string $outcome One of the class constants.
	 * @param string $control Control id, or a group name for a bonus.
	 * @param int    $points  Points awarded; 0 for an ignored punch.
	 * @param string $note    Why.
	 */
	public function add( string $outcome, string $control, int $points, string $note ): void {
		$this->breakdown[] = array(
			'outcome' => $outcome,
			'control' => $control,
			'points'  => $points,
			'note'    => $note,
		);

		$this->score = (int) $this->score + $points;
	}
}
