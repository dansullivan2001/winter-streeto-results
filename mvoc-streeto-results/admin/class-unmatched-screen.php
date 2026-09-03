<?php
/**
 * Unmatched-names queue: confirm who each new MapRun name belongs to.
 *
 * This replaces the spreadsheet's "League Check" column, where the co-ordinator
 * counted name occurrences by eye to spot mismatches. Every confirmation here
 * is stored as an alias, so a spelling is decided once and resolves silently at
 * every later event.
 *
 * Names come from the rows already imported against an event, not from a fresh
 * fetch: the data is in hand by the time anyone reaches this screen, so asking
 * MapRun again would be slower, would need the network, and could disagree with
 * what is actually stored.
 *
 * Nothing is ever pre-selected, however strong the suggestion. A wrong merge
 * hands one runner another's league points, and that is a worse failure than
 * asking a question that turns out to be easy.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Admin;

use MVOC\StreetO\Domain\Competitor_Registry;
use MVOC\StreetO\Domain\Name_Matcher;
use MVOC\StreetO\Importer;
use MVOC\StreetO\Plugin;
use MVOC\StreetO\Repo\Competitors_Repo;
use MVOC\StreetO\Repo\Events_Repo;
use MVOC\StreetO\Repo\Results_Repo;

defined( 'ABSPATH' ) || exit;

/**
 * Queues an event's unrecognised names for confirmation.
 */
class Unmatched_Screen {

	private const NONCE = 'mvoc_streeto_unmatched';

	private Competitors_Repo $repo;

	private Competitor_Registry $registry;

	private Events_Repo $events;

	private Results_Repo $results;

	/**
	 * @param Competitors_Repo|null    $repo     Competitor persistence.
	 * @param Competitor_Registry|null $registry Resolution logic.
	 * @param Events_Repo|null         $events   Events persistence.
	 * @param Results_Repo|null        $results  Results persistence.
	 */
	public function __construct(
		?Competitors_Repo $repo = null,
		?Competitor_Registry $registry = null,
		?Events_Repo $events = null,
		?Results_Repo $results = null
	) {
		$this->repo     = $repo ?? new Competitors_Repo();
		$this->registry = $registry ?? new Competitor_Registry();
		$this->events   = $events ?? new Events_Repo();
		$this->results  = $results ?? new Results_Repo();
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mvoc-streeto' ) );
		}

		$notice = '';

		if ( isset( $_POST['mvoc_streeto_action'] ) ) {
			check_admin_referer( self::NONCE );
			$notice = $this->confirm_choices();
		}

		$events   = $this->all_events();
		$event_id = $this->selected_event_id( $events );
		$event    = $event_id ? $this->events->find_event_by_id( $event_id ) : null;

		$unmatched = array();
		$resolved  = 0;

		if ( $event ) {
			$result    = $this->resolve_event( $event_id );
			$unmatched = $result['unmatched'];
			$resolved  = $result['resolved'];
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Confirm names', 'mvoc-streeto' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Tell the plugin who each unrecognised name belongs to. Each answer is remembered, so a spelling is only ever asked about once.', 'mvoc-streeto' ); ?>
			</p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $events ) : ?>
				<p><?php esc_html_e( 'No events yet. Create the series first, then import an event.', 'mvoc-streeto' ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin_Menu::SLUG . '-names' ); ?>" />
				<p>
					<label for="mvoc-event">
						<?php esc_html_e( 'Event', 'mvoc-streeto' ); ?>
					</label>
					<select id="mvoc-event" name="event" onchange="this.form.submit()">
						<?php foreach ( $events as $option ) : ?>
							<option value="<?php echo esc_attr( (string) $option['id'] ); ?>"
								<?php selected( $event_id, (int) $option['id'] ); ?>>
								<?php
								printf(
									'%d. %s%s',
									(int) $option['event_number'],
									esc_html( $option['title'] ),
									$option['imported'] ? '' : esc_html__( ' — not imported', 'mvoc-streeto' )
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<noscript>
						<button type="submit" class="button"><?php esc_html_e( 'Go', 'mvoc-streeto' ); ?></button>
					</noscript>
				</p>
			</form>

			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="event" value="<?php echo esc_attr( (string) $event_id ); ?>" />

				<?php if ( $unmatched ) : ?>
					<h2>
						<?php
						printf(
							/* translators: 1: names needing a decision, 2: rows already recognised. */
							esc_html__( '%1$d name(s) to confirm — %2$d row(s) already recognised', 'mvoc-streeto' ),
							count( $unmatched ),
							(int) $resolved
						);
						?>
					</h2>

					<?php foreach ( $unmatched as $entry ) : ?>
						<?php $this->render_entry( $entry ); ?>
					<?php endforeach; ?>

					<p>
						<button type="submit" name="mvoc_streeto_action" value="confirm" class="button button-primary">
							<?php esc_html_e( 'Confirm selected', 'mvoc-streeto' ); ?>
						</button>
						<span class="description">
							<?php esc_html_e( 'Anything left on "Decide later" is skipped and will be asked again.', 'mvoc-streeto' ); ?>
						</span>
					</p>
				<?php elseif ( $resolved > 0 ) : ?>
					<div class="notice notice-success inline">
						<p>
							<?php
							printf(
								/* translators: %d: number of rows. */
								esc_html__( 'Every name is recognised — all %d rows resolved without asking.', 'mvoc-streeto' ),
								(int) $resolved
							);
							?>
						</p>
					</div>
				<?php else : ?>
					<p><?php esc_html_e( 'Nothing imported for this event yet.', 'mvoc-streeto' ); ?></p>
				<?php endif; ?>
			</form>

			<?php if ( $event ) : ?>
				<p>
					<a href="<?php echo esc_url( $this->review_url( $event_id ) ); ?>">
						<?php esc_html_e( '&larr; Back to the event results', 'mvoc-streeto' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Every event, flagged with whether anything has been imported for it.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function all_events(): array {
		$events = array();

		foreach ( $this->events->all_series() as $series ) {
			foreach ( $this->events->events( (int) $series['id'] ) as $event ) {
				$event['imported'] = (bool) $this->results->for_event( (int) $event['id'] );
				$events[]          = $event;
			}
		}

		return $events;
	}

	/**
	 * Which event to show: the one asked for, else the last one imported.
	 *
	 * Defaulting to the most recent import means arriving here from a fresh
	 * import lands on the right event without a query string.
	 *
	 * @param array<int,array<string,mixed>> $events Events with import flags.
	 */
	private function selected_event_id( array $events ): int {
		$requested = isset( $_GET['event'] ) ? (int) $_GET['event'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $requested && isset( $_POST['event'] ) ) {
			$requested = (int) $_POST['event']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( $requested ) {
			return $requested;
		}

		$imported = array_values( array_filter( $events, static fn( array $e ): bool => $e['imported'] ) );
		$last     = end( $imported );

		return $last ? (int) $last['id'] : (int) ( $events[0]['id'] ?? 0 );
	}

	/**
	 * Resolve an event's stored rows against the registry.
	 *
	 * @param int $event_id Event id.
	 * @return array{unmatched:array<int,array<string,mixed>>,resolved:int}
	 */
	private function resolve_event( int $event_id ): array {
		$rows = array();

		foreach ( $this->results->for_event( $event_id ) as $row ) {
			$effective = Results_Repo::effective( $row );

			// Withdrawn rows are no longer in MapRun, so confirming a name for
			// one would be busywork over a result that will not be published.
			if ( $effective['is_withdrawn'] ) {
				continue;
			}

			$rows[] = array(
				'first_name'    => $row['raw_first_name'],
				'surname'       => $row['raw_surname'],
				'display_name'  => $effective['display_name'],
				'club'          => $row['raw_club'],
				'gender'        => $row['raw_gender'],
				'year_of_birth' => $row['raw_year_of_birth'] ? (int) $row['raw_year_of_birth'] : null,
				'competitor_id' => $row['competitor_id'],
			);
		}

		$result = $this->registry->resolve( $rows, $this->repo->all(), $this->repo->aliases() );

		$resolved = 0;
		foreach ( $result['rows'] as $row ) {
			if ( $row['competitor_id'] ) {
				++$resolved;
			}
		}

		return array(
			'unmatched' => $result['unmatched'],
			'resolved'  => $resolved,
		);
	}

	/**
	 * Admin URL for an event's review screen.
	 *
	 * @param int $event_id Event id.
	 */
	private function review_url( int $event_id ): string {
		return add_query_arg(
			array(
				'page'  => Admin_Menu::SLUG . '-review',
				'event' => $event_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render one queued name with its options.
	 *
	 * @param array<string,mixed> $entry Queue entry.
	 */
	private function render_entry( array $entry ): void {
		$key    = (string) $entry['alias_key'];
		$field  = 'choice[' . $key . ']';
		$detail = array_filter(
			array(
				$entry['club'],
				$entry['year_of_birth'] ? sprintf( 'b. %d', (int) $entry['year_of_birth'] ) : '',
				'F' === $entry['gender'] ? __( 'female', 'mvoc-streeto' ) : '',
			)
		);

		?>
		<div class="card" style="max-width:none;margin-bottom:1em;">
			<h3 style="margin-top:0;">
				<?php echo esc_html( $entry['display_name'] ); ?>
				<?php if ( $detail ) : ?>
					<span class="description">— <?php echo esc_html( implode( ', ', $detail ) ); ?></span>
				<?php endif; ?>
			</h3>

			<?php
			// Carried through the form so confirming needs no second lookup.
			$proposed = $entry['proposed'];
			$base     = 'proposed[' . $key . ']';
			foreach ( array( 'first_name', 'surname', 'display_name', 'club' ) as $plain ) :
				?>
				<input type="hidden" name="<?php echo esc_attr( $base . '[' . $plain . ']' ); ?>"
					value="<?php echo esc_attr( (string) $proposed[ $plain ] ); ?>" />
			<?php endforeach; ?>
			<input type="hidden" name="<?php echo esc_attr( $base . '[year_of_birth]' ); ?>"
				value="<?php echo esc_attr( (string) ( $proposed['year_of_birth'] ?? 0 ) ); ?>" />
			<?php if ( $proposed['is_female'] ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $base . '[is_female]' ); ?>" value="1" />
			<?php endif; ?>
			<?php if ( $proposed['is_over55'] ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $base . '[is_over55]' ); ?>" value="1" />
			<?php endif; ?>

			<p>
				<label>
					<input type="radio" name="<?php echo esc_attr( $field ); ?>" value="" checked />
					<?php esc_html_e( 'Decide later', 'mvoc-streeto' ); ?>
				</label>
			</p>

			<p>
				<label>
					<input type="radio" name="<?php echo esc_attr( $field ); ?>" value="new" />
					<?php esc_html_e( 'New competitor', 'mvoc-streeto' ); ?>
					<span class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: Ladies yes/no, 2: Over-55 yes/no. */
								__( '(Ladies: %1$s, Over 55: %2$s — from MapRun, editable afterwards)', 'mvoc-streeto' ),
								$proposed['is_female'] ? __( 'yes', 'mvoc-streeto' ) : __( 'no', 'mvoc-streeto' ),
								$proposed['is_over55'] ? __( 'yes', 'mvoc-streeto' ) : __( 'no', 'mvoc-streeto' )
							)
						);
						?>
					</span>
				</label>
			</p>

			<?php if ( $entry['suggestions'] ) : ?>
				<p><strong><?php esc_html_e( 'Or the same person as:', 'mvoc-streeto' ); ?></strong></p>
				<?php foreach ( $entry['suggestions'] as $suggestion ) : ?>
					<p style="margin-left:1.5em;">
						<label>
							<input type="radio" name="<?php echo esc_attr( $field ); ?>"
								value="<?php echo esc_attr( (string) $suggestion['competitor']['id'] ); ?>" />
							<?php echo esc_html( (string) ( $suggestion['competitor']['display_name'] ?? '' ) ); ?>
							<span class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: confidence score, 2: reasons. */
										__( '— %1$d%% match: %2$s', 'mvoc-streeto' ),
										(int) $suggestion['score'],
										$suggestion['reasons']
											? implode( ', ', $suggestion['reasons'] )
											: __( 'similar name', 'mvoc-streeto' )
									)
								);
								?>
							</span>
						</label>
					</p>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Apply the co-ordinator's choices, returning a notice.
	 */
	private function confirm_choices(): string {
		if ( ! isset( $_POST['choice'] ) || ! is_array( $_POST['choice'] ) ) {
			return '';
		}

		$proposals = $this->proposals_from_post();
		$linked    = 0;
		$created   = 0;

		foreach ( wp_unslash( $_POST['choice'] ) as $raw_key => $raw_choice ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$alias_key = Name_Matcher::normalise( (string) $raw_key );
			$choice    = sanitize_text_field( (string) $raw_choice );

			if ( '' === $alias_key || '' === $choice ) {
				continue;
			}

			if ( 'new' === $choice ) {
				if ( isset( $proposals[ $alias_key ] ) ) {
					$this->repo->create_with_alias( $proposals[ $alias_key ], $alias_key );
					++$created;
				}
				continue;
			}

			$competitor_id = (int) $choice;
			if ( $competitor_id > 0 ) {
				$this->repo->link_alias( $alias_key, $competitor_id );
				++$linked;
			}
		}

		if ( 0 === $linked && 0 === $created ) {
			return '';
		}

		// Attach the newly confirmed names to results already imported, so the
		// co-ordinator does not have to re-fetch an event just to make a name
		// they have just confirmed take effect.
		$attached = ( new Importer() )->link_all_events();

		return sprintf(
			/* translators: 1: competitors created, 2: names linked, 3: existing result rows updated. */
			__( 'Created %1$d competitor(s), linked %2$d name(s), and attached %3$d existing result row(s).', 'mvoc-streeto' ),
			$created,
			$linked,
			$attached
		);
	}

	/**
	 * Competitor details for names chosen as "new", keyed by alias key.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function proposals_from_post(): array {
		if ( ! isset( $_POST['proposed'] ) || ! is_array( $_POST['proposed'] ) ) {
			return array();
		}

		$proposals = array();

		foreach ( wp_unslash( $_POST['proposed'] ) as $raw_key => $fields ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! is_array( $fields ) ) {
				continue;
			}

			$alias_key = Name_Matcher::normalise( (string) $raw_key );

			$proposals[ $alias_key ] = array(
				'first_name'    => sanitize_text_field( (string) ( $fields['first_name'] ?? '' ) ),
				'surname'       => sanitize_text_field( (string) ( $fields['surname'] ?? '' ) ),
				'display_name'  => sanitize_text_field( (string) ( $fields['display_name'] ?? '' ) ),
				'club'          => sanitize_text_field( (string) ( $fields['club'] ?? '' ) ),
				'year_of_birth' => (int) ( $fields['year_of_birth'] ?? 0 ),
				'is_female'     => ! empty( $fields['is_female'] ),
				'is_over55'     => ! empty( $fields['is_over55'] ),
			);
		}

		return $proposals;
	}
}
