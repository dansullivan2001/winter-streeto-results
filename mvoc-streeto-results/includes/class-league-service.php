<?php
/**
 * Builds league standings from the database.
 *
 * The shortcode and the admin league preview must never disagree: the whole
 * point of the preview is that what it shows is what the website will show
 * once the event is published. So the selection of events, the scoring of each
 * one and the assembly of the standings live here, once, and both callers ask
 * for them rather than each doing the work.
 *
 * What differs between them is policy, not arithmetic, and stays with the
 * caller: the shortcode caches and refuses to show a draft to a visitor, the
 * admin screen caches nothing and shows drafts on purpose.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO;

use MVOC\StreetO\Domain\League_Builder;
use MVOC\StreetO\Domain\League_Presenter;
use MVOC\StreetO\Domain\Scoring_Engine;
use MVOC\StreetO\Repo\Competitors_Repo;
use MVOC\StreetO\Repo\Events_Repo;
use MVOC\StreetO\Repo\Results_Repo;

defined( 'ABSPATH' ) || exit;

/**
 * Scores a season's events and ranks the competitors.
 */
class League_Service {

	private Events_Repo $events;

	private Results_Repo $results;

	private Competitors_Repo $competitors;

	/**
	 * @param Events_Repo|null      $events      Events persistence.
	 * @param Results_Repo|null     $results     Results persistence.
	 * @param Competitors_Repo|null $competitors Competitor persistence.
	 */
	public function __construct(
		?Events_Repo $events = null,
		?Results_Repo $results = null,
		?Competitors_Repo $competitors = null
	) {
		$this->events      = $events ?? new Events_Repo();
		$this->results     = $results ?? new Results_Repo();
		$this->competitors = $competitors ?? new Competitors_Repo();
	}

	/**
	 * The events a league table is built from, in running order.
	 *
	 * A cancelled event never counts, for anyone. It is kept in the series so
	 * the numbering stays stable, not so it can score.
	 *
	 * @param array<string,mixed> $series         Series row.
	 * @param int|null            $through_event  Cap the standings at this event
	 *                                            number, for a table recording
	 *                                            how the league stood at the
	 *                                            time. Null for the whole season
	 *                                            so far.
	 * @param bool                $include_drafts Whether unpublished events
	 *                                            count too. Only ever true for
	 *                                            a preview.
	 * @return array<int,array<string,mixed>>
	 */
	public function events( array $series, ?int $through_event = null, bool $include_drafts = false ): array {
		return array_values(
			array_filter(
				$this->events->events( $series['id'] ),
				static fn( array $event ): bool => ! $event['is_cancelled']
					&& ( $event['is_published'] || $include_drafts )
					&& ( null === $through_event || $event['event_number'] <= $through_event )
			)
		);
	}

	/**
	 * Score every given event and build the standings.
	 *
	 * @param array<string,mixed>            $series Series row.
	 * @param array<int,array<string,mixed>> $events Events to count, in order.
	 * @return array<int,array<string,mixed>>
	 */
	public function standings( array $series, array $events ): array {
		return $this->standings_with_notes( $series, $events )['rows'];
	}

	/**
	 * The standings, plus what the co-ordinator should know before publishing.
	 *
	 * `unlinked` counts, per event id, the rows that scored league points but
	 * are not attached to a competitor — every one of them a result that will
	 * be on the event table and missing from the league. That is the single
	 * likeliest reason a preview looks wrong, and it is invisible from the
	 * standings themselves, because a row nobody is linked to simply is not
	 * there. Confirming those names on the Confirm names screen fixes it.
	 *
	 * Returned together rather than from a second method so the events are
	 * scored once, not twice.
	 *
	 * @param array<string,mixed>            $series Series row.
	 * @param array<int,array<string,mixed>> $events Events to count, in order.
	 * @return array{rows:array<int,array<string,mixed>>,unlinked:array<int,int>}
	 */
	public function standings_with_notes( array $series, array $events ): array {
		$config = $this->events->scoring_config( $series );
		$engine = new Scoring_Engine( $config );

		$competitors = array();
		// Per season: Over-55 belongs to the season a runner competed in, so a
		// published league never reclassifies anyone as they age.
		foreach ( $this->competitors->all_for_series( (int) $series['id'] ) as $competitor ) {
			$competitor['event_points']       = array_fill( 0, count( $events ), null );
			$competitors[ $competitor['id'] ] = $competitor;
		}

		$unlinked = array();

		foreach ( $events as $index => $event ) {
			$scored = $engine->score_event(
				Results_Repo::effective_rows( $this->results->for_event( $event['id'] ), $config )
			);

			foreach ( $scored as $row ) {
				$id = $row['competitor_id'] ?? null;

				if ( null === $id && null !== $row['league_points'] ) {
					$unlinked[ (int) $event['id'] ] = ( $unlinked[ (int) $event['id'] ] ?? 0 ) + 1;
				}

				if ( null === $id || null === $row['league_points'] || ! isset( $competitors[ $id ] ) ) {
					continue;
				}

				// A runner with two counted rows at one event keeps the better,
				// which is the safe reading of an unresolved duplicate.
				$existing = $competitors[ $id ]['event_points'][ $index ];
				$competitors[ $id ]['event_points'][ $index ] = null === $existing
					? $row['league_points']
					: max( $existing, $row['league_points'] );
			}

			foreach ( $this->events->organisers( (int) $event['id'] ) as $organiser_id ) {
				if ( isset( $competitors[ $organiser_id ] ) ) {
					$competitors[ $organiser_id ]['organised'] = $event['label'];
				}
			}
		}

		// Only people who actually scored belong in the league table.
		$entrants = array_filter(
			$competitors,
			static fn( array $c ): bool => ! empty( $c['organised'] )
				|| array_filter( $c['event_points'], static fn( $p ): bool => null !== $p )
		);

		return array(
			'rows'     => ( new League_Builder( $config ) )->build( array_values( $entrants ) ),
			'unlinked' => $unlinked,
		);
	}

	/**
	 * The table model for one category, ready to render.
	 *
	 * @param array<string,mixed>            $series   Series row.
	 * @param array<int,array<string,mixed>> $events   Events to count, in order.
	 * @param string                         $category One of League_Presenter's category keys.
	 * @return array<string,mixed>
	 */
	public function present( array $series, array $events, string $category = 'overall' ): array {
		return $this->model( $this->standings( $series, $events ), $events, $category );
	}

	/**
	 * Turn standings already built into one category's table model.
	 *
	 * Separate from present() so a caller that needs the standings for
	 * something else — the league preview, which also wants the notes above —
	 * can render a category without scoring the season a second time.
	 *
	 * @param array<int,array<string,mixed>> $standings Rows from League_Builder.
	 * @param array<int,array<string,mixed>> $events    Events they were built from.
	 * @param string                         $category  One of League_Presenter's category keys.
	 * @return array<string,mixed>
	 */
	public function model( array $standings, array $events, string $category = 'overall' ): array {
		return ( new League_Presenter() )->present(
			$standings,
			array_map( static fn( array $event ): string => (string) $event['label'], $events ),
			$category
		);
	}
}
