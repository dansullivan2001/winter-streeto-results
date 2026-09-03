<?php
/**
 * Series and event setup: names, dates, venues, organisers and MapRun sources.
 *
 * Everything about a fixture is editable here, because fixtures move. The list
 * seeded on creation is the club's published calendar, not a contract.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Admin;

use MVOC\StreetO\Plugin;
use MVOC\StreetO\Repo\Competitors_Repo;
use MVOC\StreetO\Repo\Events_Repo;

defined( 'ABSPATH' ) || exit;

/**
 * Lists and edits the series and its events.
 */
class Events_Screen {

	private const NONCE = 'mvoc_streeto_events';

	public const DEFAULT_SLUG = '2026-27';

	/**
	 * The club's published fixture list for 2026/27, used only to seed.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private const FIXTURES = array(
		array( '2026-09-15', 'Burpham & Merrow' ),
		array( '2026-10-20', 'Tattenham Corner' ),
		array( '2026-11-17', 'Cheam' ),
		array( '2026-12-15', 'Ashtead' ),
		array( '2027-01-19', 'Chessington' ),
		array( '2027-02-16', 'Ewell' ),
		array( '2027-03-16', 'Epsom' ),
		array( '2027-04-20', 'Fetcham' ),
	);

	private Events_Repo $repo;

	private Competitors_Repo $competitors;

	/**
	 * @param Events_Repo|null      $repo        Events persistence.
	 * @param Competitors_Repo|null $competitors Competitor persistence.
	 */
	public function __construct( ?Events_Repo $repo = null, ?Competitors_Repo $competitors = null ) {
		$this->repo        = $repo ?? new Events_Repo();
		$this->competitors = $competitors ?? new Competitors_Repo();
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mvoc-streeto' ) );
		}

		$notice = $this->handle_post();
		$series = $this->repo->find_series( self::DEFAULT_SLUG );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Series and events', 'mvoc-streeto' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $series ) : ?>
				<p><?php esc_html_e( 'No series set up yet. This creates the 2026/27 series with the eight published fixtures — every name, date and venue stays editable afterwards.', 'mvoc-streeto' ); ?></p>
				<form method="post">
					<?php wp_nonce_field( self::NONCE ); ?>
					<button type="submit" name="mvoc_streeto_action" value="seed" class="button button-primary">
						<?php esc_html_e( 'Create the 2026/27 series', 'mvoc-streeto' ); ?>
					</button>
				</form>
				</div>
				<?php
				return;
			endif;

			$events      = $this->repo->events( $series['id'] );
			$competitors = $this->competitors->all();
			?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>

				<h2><?php esc_html_e( 'Series', 'mvoc-streeto' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="series-name"><?php esc_html_e( 'Name', 'mvoc-streeto' ); ?></label></th>
						<td>
							<input type="text" id="series-name" name="series_name" class="regular-text"
								value="<?php echo esc_attr( $series['name'] ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: the series slug used in shortcodes. */
									esc_html__( 'Shortcodes refer to this series as %s, which does not change when you rename it.', 'mvoc-streeto' ),
									'<code>' . esc_html( $series['slug'] ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Events', 'mvoc-streeto' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'The 40-minute course is a separate MapRun event, normally the same name ending ScoreQ40. Leave it blank until one exists.', 'mvoc-streeto' ); ?>
				</p>

				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:3em"><?php esc_html_e( '#', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Date', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Title / venue', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Organiser', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'MapRun — 60 min', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'MapRun — 40 min', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mvoc-streeto' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $events as $event ) : ?>
							<?php
							$number  = (int) $event['event_number'];
							$field   = 'events[' . $number . ']';
							$sources = array();

							foreach ( $this->repo->sources( $event['id'] ) as $source ) {
								$sources[ $source['course_label'] ] = $source['maprun_event_name'];
							}
							?>
							<tr>
								<td><?php echo esc_html( (string) $number ); ?></td>
								<td>
									<input type="date" name="<?php echo esc_attr( $field . '[event_date]' ); ?>"
										value="<?php echo esc_attr( (string) $event['event_date'] ); ?>" />
								</td>
								<td>
									<input type="text" class="regular-text"
										name="<?php echo esc_attr( $field . '[title]' ); ?>"
										value="<?php echo esc_attr( (string) $event['title'] ); ?>" />
								</td>
								<td>
									<select name="<?php echo esc_attr( $field . '[organiser]' ); ?>">
										<option value="0"><?php esc_html_e( '— none —', 'mvoc-streeto' ); ?></option>
										<?php foreach ( $competitors as $competitor ) : ?>
											<option value="<?php echo esc_attr( (string) $competitor['id'] ); ?>"
												<?php selected( $event['organiser_competitor_id'], $competitor['id'] ); ?>>
												<?php echo esc_html( $competitor['display_name'] ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<br />
									<input type="text" class="regular-text"
										name="<?php echo esc_attr( $field . '[organiser_name]' ); ?>"
										placeholder="<?php esc_attr_e( 'or type a name', 'mvoc-streeto' ); ?>" />
								</td>
								<td>
									<input type="text" class="regular-text"
										name="<?php echo esc_attr( $field . '[source_60]' ); ?>"
										value="<?php echo esc_attr( $sources['60'] ?? '' ); ?>"
										placeholder="Burpham Sep26 PXAS ScoreQ60" />
								</td>
								<td>
									<input type="text" class="regular-text"
										name="<?php echo esc_attr( $field . '[source_40]' ); ?>"
										value="<?php echo esc_attr( $sources['40'] ?? '' ); ?>"
										placeholder="&hellip; ScoreQ40" />
								</td>
								<td>
									<?php
									echo $event['is_published']
										? esc_html__( 'Published', 'mvoc-streeto' )
										: esc_html__( 'Draft', 'mvoc-streeto' );
									?>
									<br />
									<a href="<?php echo esc_url( $this->review_url( (int) $event['id'] ) ); ?>">
										<?php esc_html_e( 'Results', 'mvoc-streeto' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description">
					<?php esc_html_e( 'Organisers rarely exist as competitors before the first event has been imported, so typing a name creates one. When they later appear in MapRun results, the name is already known and matches automatically.', 'mvoc-streeto' ); ?>
				</p>

				<h2><?php esc_html_e( 'Add an event', 'mvoc-streeto' ); ?></h2>
				<p>
					<input type="date" name="new_event[event_date]" />
					<input type="text" name="new_event[title]" class="regular-text"
						placeholder="<?php esc_attr_e( 'Title / venue', 'mvoc-streeto' ); ?>" />
					<button type="submit" name="mvoc_streeto_action" value="add_event" class="button">
						<?php esc_html_e( 'Add', 'mvoc-streeto' ); ?>
					</button>
				</p>

				<p>
					<button type="submit" name="mvoc_streeto_action" value="save" class="button button-primary">
						<?php esc_html_e( 'Save changes', 'mvoc-streeto' ); ?>
					</button>
				</p>
			</form>

			<h2><?php esc_html_e( 'Shortcodes', 'mvoc-streeto' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Put these on each event page — the results first, then the league.', 'mvoc-streeto' ); ?>
			</p>
			<p>
				<code>[mvoc_streeto_event series="<?php echo esc_html( $series['slug'] ); ?>" number="1"]</code><br />
				<code>[mvoc_streeto_league series="<?php echo esc_html( $series['slug'] ); ?>"]</code><br />
				<code>[mvoc_streeto_league series="<?php echo esc_html( $series['slug'] ); ?>" category="ladies"]</code>
			</p>
		</div>
		<?php
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
	 * Handle a submission, returning a notice.
	 */
	private function handle_post(): string {
		if ( ! isset( $_POST['mvoc_streeto_action'] ) ) {
			return '';
		}

		check_admin_referer( self::NONCE );

		$action = sanitize_key( wp_unslash( $_POST['mvoc_streeto_action'] ) );

		if ( 'seed' === $action ) {
			return $this->seed_series();
		}

		$series = $this->repo->find_series( self::DEFAULT_SLUG );
		if ( ! $series ) {
			return '';
		}

		if ( 'add_event' === $action ) {
			return $this->add_event( $series );
		}

		return $this->save_all( $series );
	}

	/**
	 * Create the series and its eight fixtures.
	 */
	private function seed_series(): string {
		$series_id = $this->repo->ensure_series(
			self::DEFAULT_SLUG,
			__( 'Winter StreetO 2026/27', 'mvoc-streeto' )
		);

		foreach ( self::FIXTURES as $index => $fixture ) {
			list( $date, $venue ) = $fixture;

			$this->repo->save_event(
				$series_id,
				array(
					'event_number' => $index + 1,
					'title'        => $venue,
					'event_date'   => $date,
					'venue'        => $venue,
				)
			);
		}

		return __( 'Series created with eight events.', 'mvoc-streeto' );
	}

	/**
	 * Append an event to the end of the series.
	 *
	 * @param array<string,mixed> $series Series row.
	 */
	private function add_event( array $series ): string {
		$fields = isset( $_POST['new_event'] ) && is_array( $_POST['new_event'] )
			? wp_unslash( $_POST['new_event'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		$title = sanitize_text_field( (string) ( $fields['title'] ?? '' ) );
		if ( '' === $title ) {
			return '';
		}

		$existing = $this->repo->events( $series['id'] );
		$next     = 1;
		foreach ( $existing as $event ) {
			$next = max( $next, (int) $event['event_number'] + 1 );
		}

		$this->repo->save_event(
			$series['id'],
			array(
				'event_number' => $next,
				'title'        => $title,
				'venue'        => $title,
				'event_date'   => sanitize_text_field( (string) ( $fields['event_date'] ?? '' ) ),
			)
		);

		return sprintf(
			/* translators: %d: the new event's number. */
			__( 'Added event %d.', 'mvoc-streeto' ),
			$next
		);
	}

	/**
	 * Save the series name, every event, and their MapRun sources.
	 *
	 * @param array<string,mixed> $series Series row.
	 */
	private function save_all( array $series ): string {
		global $wpdb;

		if ( isset( $_POST['series_name'] ) ) {
			$name = sanitize_text_field( wp_unslash( $_POST['series_name'] ) );

			if ( '' !== $name && $name !== $series['name'] ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					\MVOC\StreetO\Schema::table( 'series' ),
					array( 'name' => $name ),
					array( 'id' => $series['id'] ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		if ( ! isset( $_POST['events'] ) || ! is_array( $_POST['events'] ) ) {
			return __( 'Saved.', 'mvoc-streeto' );
		}

		$saved = 0;

		foreach ( wp_unslash( $_POST['events'] ) as $number => $fields ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$event = $this->repo->find_event( $series['id'], (int) $number );

			if ( ! $event || ! is_array( $fields ) ) {
				continue;
			}

			$title = sanitize_text_field( (string) ( $fields['title'] ?? '' ) );

			$this->repo->save_event(
				$series['id'],
				array(
					'event_number'            => (int) $number,
					'title'                   => $title ?: $event['title'],
					'venue'                   => $title ?: $event['venue'],
					'event_date'              => sanitize_text_field( (string) ( $fields['event_date'] ?? '' ) ),
					'organiser_competitor_id' => $this->resolve_organiser( $fields ),
					'status'                  => $event['status'],
				)
			);

			$sources = array();
			foreach ( array( '60', '40' ) as $course ) {
				$name = sanitize_text_field( (string) ( $fields[ 'source_' . $course ] ?? '' ) );

				if ( '' !== $name ) {
					$sources[] = array(
						'maprun_event_name' => $name,
						'course_label'      => $course,
					);
				}
			}

			$this->repo->save_sources( (int) $event['id'], $sources );
			++$saved;
		}

		return sprintf(
			/* translators: %d: number of events saved. */
			_n( 'Saved %d event.', 'Saved %d events.', $saved, 'mvoc-streeto' ),
			$saved
		);
	}

	/**
	 * The organiser for an event: the one chosen, or one created from a name.
	 *
	 * Organisers rarely exist as competitors before the first import, so a
	 * typed name creates the record and its alias. That alias is what makes
	 * them match automatically when they next appear in MapRun results, rather
	 * than becoming a second identity for the same person.
	 *
	 * @param array<string,mixed> $fields Submitted event fields.
	 */
	private function resolve_organiser( array $fields ): int {
		$typed = sanitize_text_field( (string) ( $fields['organiser_name'] ?? '' ) );

		if ( '' !== $typed ) {
			$parts   = preg_split( '/\s+/', trim( $typed ), 2 );
			$first   = $parts[0] ?? '';
			$surname = $parts[1] ?? '';

			$competitor = array(
				'first_name'   => $first,
				'surname'      => $surname,
				'display_name' => $typed,
			);

			$alias_key = \MVOC\StreetO\Domain\Name_Matcher::alias_key( $first, $surname );

			// Reuse an existing competitor with that name rather than making a
			// second one: a duplicate identity would split their league points.
			$aliases = $this->competitors->aliases();
			if ( isset( $aliases[ $alias_key ] ) ) {
				return (int) $aliases[ $alias_key ];
			}

			return $this->competitors->create_with_alias( $competitor, $alias_key );
		}

		return (int) ( $fields['organiser'] ?? 0 );
	}
}
