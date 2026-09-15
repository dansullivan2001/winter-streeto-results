<?php
/**
 * Published league table.
 *
 * Compact by default — Pos, Name, Total — with each runner's per-event scores
 * in a details element beneath. The detail is always in the markup so it stays
 * readable without JavaScript and findable by the browser's find-in-page, which
 * is how a runner looks for their own name.
 *
 * @var array<string,mixed> $model Table model from League_Presenter.
 *
 * @package MVOC_StreetO
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="mvoc-streeto mvoc-streeto-league"
	data-expand-label="<?php esc_attr_e( 'Show all scores', 'mvoc-streeto' ); ?>"
	data-collapse-label="<?php esc_attr_e( 'Hide scores', 'mvoc-streeto' ); ?>"
	data-all-label="<?php esc_attr_e( 'All', 'mvoc-streeto' ); ?>"
	data-filter-label="<?php esc_attr_e( 'Filter by category', 'mvoc-streeto' ); ?>">
	<h3 class="mvoc-streeto-league-heading">
		<?php
		$event_count = count( $model['events'] );
		printf(
			/* translators: 1: category label (e.g. "Overall", "Ladies"), 2: number of events scored so far. */
			esc_html(
				_n( '%1$s after %2$d event', '%1$s after %2$d events', $event_count, 'mvoc-streeto' )
			),
			esc_html( $model['label'] ),
			(int) $event_count
		);
		?>
	</h3>

	<?php if ( ! empty( $model['includes_drafts'] ) ) : ?>
		<p class="mvoc-streeto-draft">
			<?php esc_html_e( 'Includes unpublished results — visible only to you. Visitors see this once you publish.', 'mvoc-streeto' ); ?>
		</p>
	<?php endif; ?>

	<div class="mvoc-streeto-scroll">
	<table class="mvoc-streeto-table mvoc-streeto-league-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Pos', 'mvoc-streeto' ); ?></th>
				<?php foreach ( \MVOC\StreetO\Domain\League_Presenter::category_columns() as $key => $label ) : ?>
					<th scope="col" class="mvoc-streeto-category-col" data-category="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></th>
				<?php endforeach; ?>
				<th scope="col"><?php esc_html_e( 'Name', 'mvoc-streeto' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Events', 'mvoc-streeto' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Total', 'mvoc-streeto' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $model['rows'] as $row ) : ?>
				<?php
				// Which category columns this competitor holds a position in, so the
				// filter buttons can show or hide the row client-side without a second,
				// category-scoped table to keep in sync.
				$row_categories = implode(
					' ',
					array_keys( array_filter( $row['positions'], static fn( $position ) => null !== $position ) )
				);
				?>
				<tr data-categories="<?php echo esc_attr( $row_categories ); ?>">
					<td data-label="<?php esc_attr_e( 'Pos', 'mvoc-streeto' ); ?>"><?php echo esc_html( (string) $row['position'] ); ?></td>
					<?php foreach ( \MVOC\StreetO\Domain\League_Presenter::category_columns() as $key => $label ) : ?>
						<td class="mvoc-streeto-category-col" data-label="<?php echo esc_attr( $label ); ?>">
							<?php
							// Blank rather than a dash where they are not in the
							// category: a dash reads as "no position yet".
							echo isset( $row['positions'][ $key ] ) && null !== $row['positions'][ $key ]
								? esc_html( (string) $row['positions'][ $key ] )
								: '';
							?>
						</td>
					<?php endforeach; ?>
					<th scope="row" data-label="<?php esc_attr_e( 'Name', 'mvoc-streeto' ); ?>">
						<details class="mvoc-streeto-detail">
							<summary><?php echo esc_html( $row['name'] ); ?></summary>
							<ul class="mvoc-streeto-events">
								<?php foreach ( $row['event_points'] as $event ) : ?>
									<li>
										<span class="mvoc-streeto-event-label"><?php echo esc_html( $event['label'] ); ?></span>
										<span class="mvoc-streeto-event-points">
											<?php echo null === $event['points'] ? '—' : esc_html( (string) $event['points'] ); ?>
										</span>
									</li>
								<?php endforeach; ?>
								<?php if ( null !== $row['organiser_points'] ) : ?>
									<li class="mvoc-streeto-organiser-bonus">
										<span class="mvoc-streeto-event-label">
											<?php
											printf(
												/* translators: %s: the event they organised. */
												esc_html__( 'Organiser (%s)', 'mvoc-streeto' ),
												esc_html( (string) $row['organised'] )
											);
											?>
										</span>
										<span class="mvoc-streeto-event-points"><?php echo esc_html( (string) $row['organiser_points'] ); ?></span>
									</li>
								<?php endif; ?>
							</ul>
						</details>
					</th>
					<td data-label="<?php esc_attr_e( 'Events', 'mvoc-streeto' ); ?>"><?php echo esc_html( (string) $row['events_entered'] ); ?></td>
					<td data-label="<?php esc_attr_e( 'Total', 'mvoc-streeto' ); ?>"><strong><?php echo esc_html( (string) $row['total'] ); ?></strong></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	</div>

	<p class="mvoc-streeto-footnote">
		<?php
		// The count comes from the series' scoring rules rather than the
		// sentence, so a season that counts a different many says so.
		//
		// Guarded, like `includes_drafts` above, because a model can be served
		// from a transient written by an earlier version of the plugin that
		// never set this key. The cache key now carries the plugin version so
		// that should not happen — but a published page saying "The best 0
		// results count." is a bad enough failure to be worth two defences
		// rather than one. Absent, the sentence is simply left out.
		if ( ! empty( $model['counting_events'] ) ) {
			printf(
				/* translators: %d: how many event results count towards the league total. */
				esc_html( _n( 'The best result counts.', 'The best %d results count.', (int) $model['counting_events'], 'mvoc-streeto' ) ),
				(int) $model['counting_events']
			);
			echo ' ';
		}
		?>
		<?php esc_html_e( 'Event organisers score their best result again in place of the event they ran.', 'mvoc-streeto' ); ?>
	</p>
</div>
