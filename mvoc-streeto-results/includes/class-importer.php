<?php
/**
 * Imports a MapRun event: fetch, snapshot, parse, resolve, reconcile.
 *
 * The order matters. The raw payload is stored verbatim *before* anything is
 * parsed, so that if the parser ever gets something wrong the original response
 * is still on record and the event can be rebuilt from it.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO;

use MVOC\StreetO\Domain\Competitor_Registry;
use MVOC\StreetO\Domain\Import_Reconciler;
use MVOC\StreetO\MapRun\Client;
use MVOC\StreetO\MapRun\Parser;
use MVOC\StreetO\Repo\Competitors_Repo;
use MVOC\StreetO\Repo\Events_Repo;
use MVOC\StreetO\Repo\Results_Repo;

defined( 'ABSPATH' ) || exit;

/**
 * Runs an import for one event, across all its MapRun sources.
 */
class Importer {

	private Client $client;

	private Events_Repo $events;

	private Results_Repo $results;

	private Competitors_Repo $competitors;

	private Competitor_Registry $registry;

	private Import_Reconciler $reconciler;

	/**
	 * @param Client|null              $client      MapRun client.
	 * @param Events_Repo|null         $events      Events persistence.
	 * @param Results_Repo|null        $results     Results persistence.
	 * @param Competitors_Repo|null    $competitors Competitor persistence.
	 * @param Competitor_Registry|null $registry    Name resolution.
	 */
	public function __construct(
		?Client $client = null,
		?Events_Repo $events = null,
		?Results_Repo $results = null,
		?Competitors_Repo $competitors = null,
		?Competitor_Registry $registry = null
	) {
		$this->client      = $client ?? new Client();
		$this->events      = $events ?? new Events_Repo();
		$this->results     = $results ?? new Results_Repo();
		$this->competitors = $competitors ?? new Competitors_Repo();
		$this->registry    = $registry ?? new Competitor_Registry();
		$this->reconciler  = new Import_Reconciler();
	}

	/**
	 * Import every source configured for an event.
	 *
	 * @param int         $event_id Event id.
	 * @param string|null $pasted   Pasted JSON, used instead of fetching when given.
	 * @return array{summary:array<string,int>,warnings:string[],unmatched:array<int,array<string,mixed>>,errors:string[]}
	 */
	public function import( int $event_id, ?string $pasted = null ): array {
		$sources  = $this->events->sources( $event_id );
		$summary  = array();
		$warnings = array();
		$errors   = array();
		$parsed   = array();

		foreach ( $sources as $source ) {
			try {
				$result = null === $pasted
					? $this->client->fetch( $source['maprun_event_name'] )
					: $this->client->ingest( $pasted );
			} catch ( \RuntimeException $e ) {
				// One course failing must not abandon the other. A 40-minute
				// event that nobody entered returns an error, and that should
				// not block importing the 60.
				$errors[] = sprintf( '%s: %s', $source['course_label'], $e->getMessage() );
				continue;
			}

			if ( ! empty( $result['warning'] ) ) {
				$warnings[] = sprintf( '%s: %s', $source['course_label'], $result['warning'] );
			}

			$rows = ( new Parser() )->parse( $result['rows'], (string) $source['course_label'] );

			$fetch_id = $this->events->record_fetch(
				(int) $source['id'],
				$result['payload'],
				count( $rows ),
				null === $pasted ? 'http' : 'paste'
			);

			$actions = $this->reconciler->reconcile(
				$this->results->for_source( (int) $source['id'] ),
				$rows
			);

			foreach ( $actions as $action ) {
				$this->results->apply_action( $action, $event_id, (int) $source['id'], $fetch_id );
			}

			$summary = self::add_summaries( $summary, Import_Reconciler::summarise( $actions ) );
			$parsed  = array_merge( $parsed, $rows );

			// A paste only ever covers one source, so stop after the first.
			if ( null !== $pasted ) {
				break;
			}
		}

		$this->events->touch_fetched( $event_id );
		$this->link_competitors( $event_id );

		$resolution = $this->registry->resolve(
			$parsed,
			$this->competitors->all(),
			$this->competitors->aliases()
		);

		return array(
			'summary'   => $summary,
			'warnings'  => $warnings,
			'errors'    => $errors,
			'unmatched' => $resolution['unmatched'],
		);
	}

	/**
	 * Attach competitors to any result row whose name is already confirmed.
	 *
	 * Run after every import so that names confirmed at an earlier event
	 * resolve without the co-ordinator doing anything. A row whose competitor
	 * has already been set by hand is left alone.
	 *
	 * @param int $event_id Event id.
	 * @return int Rows newly linked.
	 */
	public function link_competitors( int $event_id ): int {
		$aliases = $this->competitors->aliases();
		$linked  = 0;

		foreach ( $this->results->for_event( $event_id ) as $row ) {
			if ( $row['competitor_id'] ) {
				continue;
			}

			$key = Domain\Name_Matcher::alias_key(
				(string) $row['raw_first_name'],
				(string) $row['raw_surname']
			);

			if ( isset( $aliases[ $key ] ) ) {
				$this->results->override(
					(int) $row['id'],
					'competitor',
					$aliases[ $key ],
					'matched by confirmed name'
				);
				++$linked;
			}
		}

		return $linked;
	}

	/**
	 * Add two action summaries together.
	 *
	 * @param array<string,int> $running Accumulated totals.
	 * @param array<string,int> $next    Totals to add.
	 * @return array<string,int>
	 */
	private static function add_summaries( array $running, array $next ): array {
		foreach ( $next as $action => $count ) {
			$running[ $action ] = ( $running[ $action ] ?? 0 ) + $count;
		}

		return $running;
	}
}
