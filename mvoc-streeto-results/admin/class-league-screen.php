<?php
/**
 * League table preview: the standings as they stand, before anything is public.
 *
 * The question this screen answers is the one asked with a finger on the
 * Publish button — "if I publish this event, what does the league look like?"
 * Until now the only way to see that was to publish and look at the website,
 * which is the wrong order.
 *
 * So it shows the full spreadsheet-shaped table the club used to keep: every
 * ranking, every event, the organiser bonus and the total, side by side. The
 * published table is deliberately narrower because it is read on a phone in
 * the dark; this one is read on a desk, with time to check it.
 *
 * Read-only by design. Nothing here writes, and nothing here publishes —
 * an event is still published from its own results screen, deliberately.
 *
 * The arithmetic is League_Service's, the same code the shortcode runs, so a
 * table checked here and a table on the website cannot disagree about anything
 * but which events are in it.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Admin;

use MVOC\StreetO\Domain\League_Presenter;
use MVOC\StreetO\League_Service;
use MVOC\StreetO\Plugin;
use MVOC\StreetO\Repo\Events_Repo;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the league standings for checking.
 */
class League_Screen {

	/**
	 * Where each user's last-viewed series is remembered.
	 *
	 * The same meta the other screens use, so switching season on one screen
	 * switches it on all of them.
	 */
	private const LAST_SERIES_META = 'mvoc_streeto_last_series';

	/**
	 * Marker proving the controls below were submitted.
	 *
	 * An unticked checkbox submits nothing at all, so without this there is no
	 * way to tell "the co-ordinator unticked drafts" from "first visit, no
	 * query string" — and drafts default to on, because a preview that hid
	 * the unpublished event would answer the wrong question.
	 */
	private const SUBMITTED = 'view';

	private Events_Repo $events;

	private League_Service $league;

	/**
	 * @param Events_Repo|null    $events Series and events persistence.
	 * @param League_Service|null $league Standings builder.
	 */
	public function __construct( ?Events_Repo $events = null, ?League_Service $league = null ) {
		$this->events = $events ?? new Events_Repo();
		$this->league = $league ?? new League_Service( $this->events );
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mvoc-streeto' ) );
		}

		$all_series = $this->events->all_series();
		$series     = $this->current_series( $all_series );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'League table', 'mvoc-streeto' ); ?></h1>
			<p class="description" style="max-width:55em;">
				<?php esc_html_e( 'The standings as they stand, including events not yet published — for checking before you publish, not for the public. Nothing on this page is visible on the website, and nothing here changes any data.', 'mvoc-streeto' ); ?>
			</p>

			<?php
			if ( ! $series ) {
				$this->render_no_series();
				echo '</div>';

				return;
			}

			$this->render_table( $series, $all_series );
			?>
		</div>
		<?php
	}

	/**
	 * Nothing to show yet, and where to go instead.
	 */
	private function render_no_series(): void {
		?>
		<p>
			<?php
			printf(
				/* translators: %s: link to the Series and events screen. */
				esc_html__( 'No season yet. Start one on %s.', 'mvoc-streeto' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=' . Admin_Menu::SLUG ) ) . '">'
					. esc_html__( 'Series and events', 'mvoc-streeto' ) . '</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * The controls, the summary and the table itself.
	 *
	 * @param array<string,mixed>            $series     The season being viewed.
	 * @param array<int,array<string,mixed>> $all_series Every season, for the picker.
	 */
	private function render_table( array $series, array $all_series ): void {
		$all_events     = $this->events->events( (int) $series['id'] );
		$through        = $this->through_event( $all_events );
		$include_drafts = $this->include_drafts();
		$category       = $this->category();

		$events = $this->league->events( $series, $through, $include_drafts );

		$this->render_controls( $series, $all_series, $all_events, $through, $include_drafts, $category );

		if ( ! $events ) {
			?>
			<p>
				<?php
				echo $include_drafts
					? esc_html__( 'No events to score yet. Import an event\'s results and they will appear here, published or not.', 'mvoc-streeto' )
					: esc_html__( 'No published events yet. Tick "Include unpublished events" to see the standings as they would stand.', 'mvoc-streeto' );
				?>
			</p>
			<?php
			return;
		}

		$scored = $this->league->standings_with_notes( $series, $events );
		$model  = $this->league->model( $scored['rows'], $events, $category );

		$this->render_summary( $events, $scored['unlinked'] );

		if ( ! $model['rows'] ) {
			?>
			<p><?php esc_html_e( 'Nobody is in this table yet.', 'mvoc-streeto' ); ?></p>
			<?php
			return;
		}

		$this->render_standings( $model, $events );
	}

	/**
	 * Season, cut-off, drafts and category — all in one GET form.
	 *
	 * @param array<string,mixed>            $series         The season being viewed.
	 * @param array<int,array<string,mixed>> $all_series     Every season.
	 * @param array<int,array<string,mixed>> $all_events     Every event in this season.
	 * @param int|null                       $through        Cut-off event number, or null.
	 * @param bool                           $include_drafts Whether drafts are counted.
	 * @param string                         $category       Selected category key.
	 */
	private function render_controls(
		array $series,
		array $all_series,
		array $all_events,
		?int $through,
		bool $include_drafts,
		string $category
	): void {
		?>
		<form method="get" style="margin:1em 0;padding:0.75em;border:1px solid #ccd0d4;background:#fff;">
			<input type="hidden" name="page" value="<?php echo esc_attr( Admin_Menu::SLUG . '-league' ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( self::SUBMITTED ); ?>" value="1" />

			<label for="mvoc-league-series"><strong><?php esc_html_e( 'Season', 'mvoc-streeto' ); ?></strong></label>
			<select id="mvoc-league-series" name="series">
				<?php foreach ( $all_series as $option ) : ?>
					<option value="<?php echo esc_attr( $option['slug'] ); ?>"
						<?php selected( $series['slug'], $option['slug'] ); ?>>
						<?php echo esc_html( $option['name'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="mvoc-league-through" style="margin-left:1em;">
				<strong><?php esc_html_e( 'Through event', 'mvoc-streeto' ); ?></strong>
			</label>
			<select id="mvoc-league-through" name="through">
				<option value=""><?php esc_html_e( 'The whole season so far', 'mvoc-streeto' ); ?></option>
				<?php foreach ( $all_events as $event ) : ?>
					<option value="<?php echo esc_attr( (string) $event['event_number'] ); ?>"
						<?php selected( $through, (int) $event['event_number'] ); ?>>
						<?php
						printf(
							/* translators: 1: event number, 2: event name. */
							esc_html__( '%1$d — %2$s', 'mvoc-streeto' ),
							(int) $event['event_number'],
							esc_html( (string) $event['label'] )
						);
						?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="mvoc-league-category" style="margin-left:1em;">
				<strong><?php esc_html_e( 'Show', 'mvoc-streeto' ); ?></strong>
			</label>
			<select id="mvoc-league-category" name="category">
				<?php foreach ( self::category_options() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $category, $key ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label style="margin-left:1em;">
				<input type="checkbox" name="drafts" value="1" <?php checked( $include_drafts ); ?> />
				<?php esc_html_e( 'Include unpublished events', 'mvoc-streeto' ); ?>
			</label>

			<button type="submit" class="button" style="margin-left:1em;"><?php esc_html_e( 'Show', 'mvoc-streeto' ); ?></button>

			<p class="description" style="margin:0.75em 0 0;">
				<?php esc_html_e( 'Through event matches the shortcode\'s through_event: the league as it stood at that point, which is what each event\'s own page shows. Cancelled events never count.', 'mvoc-streeto' ); ?>
			</p>
		</form>
		<?php
	}

	/**
	 * What went into this table, and anything worth fixing before publishing.
	 *
	 * @param array<int,array<string,mixed>> $events   Events counted.
	 * @param array<int,int>                 $unlinked Event id => scoring rows with no competitor.
	 */
	private function render_summary( array $events, array $unlinked ): void {
		$drafts = array_values(
			array_filter( $events, static fn( array $event ): bool => ! $event['is_published'] )
		);

		// The events actually carrying unconfirmed results, so the warning
		// below is raised only when it has something to name.
		$needing_names = array_values(
			array_filter( $events, static fn( array $event ): bool => ! empty( $unlinked[ (int) $event['id'] ] ) )
		);

		?>
		<p>
			<?php
			printf(
				/* translators: %d: number of events counted. */
				esc_html( _n( 'Built from %d event:', 'Built from %d events:', count( $events ), 'mvoc-streeto' ) ),
				count( $events )
			);
			?>
			<?php $last = count( $events ) - 1; ?>
			<?php foreach ( $events as $index => $event ) : ?>
				<a href="<?php echo esc_url( $this->review_url( (int) $event['id'] ) ); ?>">
					<?php echo esc_html( (string) $event['event_number'] . '. ' . $event['label'] ); ?></a><?php
					if ( ! $event['is_published'] ) :
						?><em> (<?php esc_html_e( 'unpublished', 'mvoc-streeto' ); ?>)</em><?php
					endif;
				?><?php echo $index === $last ? '' : ', '; ?>
			<?php endforeach; ?>
		</p>

		<?php if ( $drafts ) : ?>
			<div class="notice notice-info inline" style="margin:1em 0;">
				<p>
					<?php
					printf(
						/* translators: %d: number of unpublished events counted. */
						esc_html(
							_n(
								'%d of these events is not published, so the website does not show these standings yet.',
								'%d of these events are not published, so the website does not show these standings yet.',
								count( $drafts ),
								'mvoc-streeto'
							)
						),
						count( $drafts )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( $needing_names ) : ?>
			<div class="notice notice-warning inline" style="margin:1em 0;">
				<p>
					<strong><?php esc_html_e( 'Some results are not counted in this table.', 'mvoc-streeto' ); ?></strong>
					<?php esc_html_e( 'They scored, but are not yet confirmed as belonging to anybody, so they appear on the event table and nowhere here. Confirm the names and they will drop into place.', 'mvoc-streeto' ); ?>
				</p>
				<ul style="list-style:disc;margin:0 0 1em 1.5em;">
					<?php foreach ( $needing_names as $event ) : ?>
						<?php $count = (int) $unlinked[ (int) $event['id'] ]; ?>
							<li>
								<?php
								printf(
									/* translators: 1: event name, 2: number of unconfirmed scoring results. */
									esc_html(
										_n(
											'%1$s — %2$d unconfirmed result',
											'%1$s — %2$d unconfirmed results',
											$count,
											'mvoc-streeto'
										)
									),
									esc_html( (string) $event['label'] ),
									(int) $count
								);
								?>
								(<a href="<?php echo esc_url( $this->names_url( (int) $event['id'] ) ); ?>"><?php
									esc_html_e( 'Confirm names', 'mvoc-streeto' );
								?></a>)
							</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * The standings themselves: every ranking and every event, side by side.
	 *
	 * @param array<string,mixed>            $model  Table model from League_Presenter.
	 * @param array<int,array<string,mixed>> $events Events counted, in the model's column order.
	 */
	private function render_standings( array $model, array $events ): void {
		?>
		<h2 style="margin-top:1.5em;">
			<?php
			printf(
				/* translators: 1: category label, 2: number of competitors listed. */
				esc_html( _n( '%1$s — %2$d competitor', '%1$s — %2$d competitors', count( $model['rows'] ), 'mvoc-streeto' ) ),
				esc_html( (string) $model['label'] ),
				count( $model['rows'] )
			);
			?>
		</h2>

		<div style="overflow-x:auto;">
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Pos', 'mvoc-streeto' ); ?></th>
					<?php foreach ( League_Presenter::category_columns() as $label ) : ?>
						<th scope="col"><?php echo esc_html( $label ); ?></th>
					<?php endforeach; ?>
					<th scope="col"><?php esc_html_e( 'Name', 'mvoc-streeto' ); ?></th>
					<?php foreach ( $events as $event ) : ?>
						<th scope="col" style="text-align:right;">
							<a href="<?php echo esc_url( $this->review_url( (int) $event['id'] ) ); ?>"
								title="<?php echo esc_attr( (string) $event['label'] ); ?>">
								<?php echo esc_html( (string) $event['event_number'] ); ?></a>
							<?php if ( ! $event['is_published'] ) : ?>
								<span title="<?php esc_attr_e( 'Not published', 'mvoc-streeto' ); ?>">*</span>
							<?php endif; ?>
						</th>
					<?php endforeach; ?>
					<th scope="col" style="text-align:right;"><?php esc_html_e( 'Org', 'mvoc-streeto' ); ?></th>
					<th scope="col" style="text-align:right;"><?php esc_html_e( 'Events', 'mvoc-streeto' ); ?></th>
					<th scope="col" style="text-align:right;"><?php esc_html_e( 'Total', 'mvoc-streeto' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $model['rows'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $row['position'] ); ?></td>
						<?php foreach ( array_keys( League_Presenter::category_columns() ) as $key ) : ?>
							<td>
								<?php
								// Blank rather than a dash where they are not in the
								// category: a dash reads as "no position yet".
								echo isset( $row['positions'][ $key ] ) && null !== $row['positions'][ $key ]
									? esc_html( (string) $row['positions'][ $key ] )
									: '';
								?>
							</td>
						<?php endforeach; ?>
						<th scope="row"><?php echo esc_html( (string) $row['name'] ); ?></th>
						<?php foreach ( $row['event_points'] as $event_points ) : ?>
							<td style="text-align:right;<?php echo $event_points['counts'] ? '' : 'color:#787c82;'; ?>">
								<?php
								if ( null === $event_points['points'] ) {
									echo '<span style="color:#a7aaad;">&mdash;</span>';
								} elseif ( $event_points['counts'] ) {
									echo '<strong>' . esc_html( (string) $event_points['points'] ) . '</strong>';
								} else {
									echo esc_html( (string) $event_points['points'] );
								}
								?>
							</td>
						<?php endforeach; ?>
						<td style="text-align:right;<?php echo $row['organiser_counts'] ? '' : 'color:#787c82;'; ?>">
							<?php
							if ( null === $row['organiser_points'] ) {
								echo '<span style="color:#a7aaad;">&mdash;</span>';
							} elseif ( $row['organiser_counts'] ) {
								echo '<strong>' . esc_html( (string) $row['organiser_points'] ) . '</strong>';
							} else {
								echo esc_html( (string) $row['organiser_points'] );
							}

							if ( null !== $row['organiser_points'] ) {
								// Which event they organised, on hover: the column is
								// one character wide and the name would not fit.
								printf(
									' <span class="description" title="%s">&#9432;</span>',
									esc_attr( (string) $row['organised'] )
								);
							}
							?>
						</td>
						<td style="text-align:right;"><?php echo esc_html( (string) $row['events_entered'] ); ?></td>
						<td style="text-align:right;"><strong><?php echo esc_html( (string) $row['total'] ); ?></strong></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>

		<p class="description" style="max-width:55em;margin-top:1em;">
			<?php esc_html_e( 'Scores in bold are the ones making up the total; the rest are the results dropped by the best-5 rule. Org is the organiser bonus — their best result again, competing for one of those five slots rather than added on top. A * against a column heading means that event is not published yet.', 'mvoc-streeto' ); ?>
		</p>
		<?php
	}

	/**
	 * The category picker's options: all four rankings, plus every row.
	 *
	 * Filtering by category drops the rows outside it, exactly as the
	 * shortcode's `category` attribute does — the columns stay, so a ladies
	 * table still shows where each of them sits overall.
	 *
	 * @return array<string,string>
	 */
	private static function category_options(): array {
		return array(
			'overall'   => __( 'Everyone', 'mvoc-streeto' ),
			'ladies'    => __( 'Ladies', 'mvoc-streeto' ),
			'o55_men'   => __( 'Over 55 Men', 'mvoc-streeto' ),
			'o55_women' => __( 'Over 55 Women', 'mvoc-streeto' ),
		);
	}

	/**
	 * The category asked for, defaulting to everyone.
	 */
	private function category(): string {
		$category = isset( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return League_Presenter::is_category( $category ) ? $category : 'overall';
	}

	/**
	 * The cut-off event number, or null for the whole season so far.
	 *
	 * @param array<int,array<string,mixed>> $all_events Every event in the season.
	 */
	private function through_event( array $all_events ): ?int {
		$through = isset( $_GET['through'] ) ? absint( wp_unslash( $_GET['through'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $through < 1 ) {
			return null;
		}

		// A number nobody in this season has means "the whole season" rather
		// than an empty table — the likeliest way to get one is switching
		// season with a cut-off still selected.
		foreach ( $all_events as $event ) {
			if ( (int) $event['event_number'] === $through ) {
				return $through;
			}
		}

		return null;
	}

	/**
	 * Whether unpublished events are counted.
	 *
	 * On by default: this screen exists to show what publishing would do.
	 */
	private function include_drafts(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::SUBMITTED ] ) ) {
			return true;
		}

		return isset( $_GET['drafts'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The season being viewed: asked for, last used, active, or the first.
	 *
	 * @param array<int,array<string,mixed>> $all_series Every season.
	 * @return array<string,mixed>|null
	 */
	private function current_series( array $all_series ): ?array {
		$slug = isset( $_GET['series'] ) ? sanitize_title( wp_unslash( $_GET['series'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $slug ) {
			foreach ( $all_series as $series ) {
				if ( $series['slug'] === $slug ) {
					update_user_meta( get_current_user_id(), self::LAST_SERIES_META, $slug );

					return $series;
				}
			}
		}

		$remembered = (string) get_user_meta( get_current_user_id(), self::LAST_SERIES_META, true );
		foreach ( $all_series as $series ) {
			if ( $series['slug'] === $remembered ) {
				return $series;
			}
		}

		foreach ( $all_series as $series ) {
			if ( ! empty( $series['is_active'] ) ) {
				return $series;
			}
		}

		return $all_series[0] ?? null;
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
	 * Admin URL for an event's unconfirmed names.
	 *
	 * @param int $event_id Event id.
	 */
	private function names_url( int $event_id ): string {
		return add_query_arg(
			array(
				'page'  => Admin_Menu::SLUG . '-names',
				'event' => $event_id,
			),
			admin_url( 'admin.php' )
		);
	}
}
