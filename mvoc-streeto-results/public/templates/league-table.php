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
	data-collapse-label="<?php esc_attr_e( 'Hide scores', 'mvoc-streeto' ); ?>">
	<h3 class="mvoc-streeto-league-heading"><?php echo esc_html( $model['label'] ); ?></h3>

	<?php if ( ! empty( $model['includes_drafts'] ) ) : ?>
		<p class="mvoc-streeto-draft">
			<?php esc_html_e( 'Includes unpublished events — visible only to you. Visitors see the published events only.', 'mvoc-streeto' ); ?>
		</p>
	<?php endif; ?>

	<div class="mvoc-streeto-scroll">
	<table class="mvoc-streeto-table mvoc-streeto-league-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Pos', 'mvoc-streeto' ); ?></th>
				<?php foreach ( \MVOC\StreetO\Domain\League_Presenter::category_columns() as $key => $label ) : ?>
					<th scope="col" class="mvoc-streeto-category-col"><?php echo esc_html( $label ); ?></th>
				<?php endforeach; ?>
				<th scope="col"><?php esc_html_e( 'Name', 'mvoc-streeto' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Events', 'mvoc-streeto' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Total', 'mvoc-streeto' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $model['rows'] as $row ) : ?>
				<tr>
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
		<?php esc_html_e( 'The best 5 results count. Event organisers score their best result again in place of the event they ran.', 'mvoc-streeto' ); ?>
	</p>
</div>
