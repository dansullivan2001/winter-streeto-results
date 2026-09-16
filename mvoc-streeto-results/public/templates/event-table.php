<?php
/**
 * Published event results table.
 *
 * @var array<string,mixed> $model Table model from Event_Presenter.
 * @var array<string,mixed> $event Event row.
 *
 * @package MVOC_StreetO
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="mvoc-streeto mvoc-streeto-event">
	<?php
	// The table sits directly under the organiser's write-up on the event page,
	// so it needs a heading and a rule above it — without them the prose runs
	// straight into the header row. Numbered rather than titled: the page
	// heading already carries the venue, and the number is what the report and
	// the league tables below refer to.
	?>
	<h3 class="mvoc-streeto-event-heading">
		<?php
		printf(
			/* translators: %d: event number within the series. */
			esc_html__( 'Event %d results', 'mvoc-streeto' ),
			(int) $event['event_number']
		);
		?>
	</h3>

	<?php if ( ! $event['is_published'] ) : ?>
		<p class="mvoc-streeto-draft"><?php esc_html_e( 'Draft — visible only to you until published.', 'mvoc-streeto' ); ?></p>
	<?php endif; ?>

	<div class="mvoc-streeto-scroll">
		<table class="mvoc-streeto-table">
			<caption class="screen-reader-text">
				<?php echo esc_html( $event['label'] ); ?>
			</caption>
			<thead>
				<tr>
					<?php
					// The headings stay the presenter's to decide — but the three
					// category ones have to carry the same class as their cells, or
					// a narrow screen hides the cells and leaves the headings
					// behind, and every figure in the row sits under the wrong one.
					$category_labels = \MVOC\StreetO\Domain\Categories::columns();
					?>
					<?php foreach ( $model['columns'] as $column ) : ?>
						<th scope="col"<?php echo in_array( $column, $category_labels, true ) ? ' class="mvoc-streeto-category-col"' : ''; ?>><?php echo esc_html( $column ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $model['rows'] as $row ) : ?>
					<tr<?php echo ! empty( $row['is_organiser'] ) ? ' class="mvoc-streeto-organiser"' : ''; ?>>
						<td>
							<?php echo esc_html( $row['position_label'] ?: '—' ); ?>
						</td>
						<?php foreach ( array_keys( \MVOC\StreetO\Domain\Categories::columns() ) as $key ) : ?>
							<?php
							// Blank rather than a dash where they are not in the
							// category: a dash reads as "no position yet".
							?>
							<td class="mvoc-streeto-category-col"><?php echo isset( $row['positions'][ $key ] ) && null !== $row['positions'][ $key ] ? esc_html( (string) $row['positions'][ $key ] ) : ''; ?></td>
						<?php endforeach; ?>
						<th scope="row">
							<?php echo esc_html( $row['name'] ); ?>
							<?php if ( ! empty( $row['is_organiser'] ) ) : ?>
								<span class="mvoc-streeto-tag"><?php esc_html_e( 'Organiser', 'mvoc-streeto' ); ?></span>
							<?php endif; ?>
						</th>
						<td>
							<?php echo $row['course'] ? esc_html( $row['course'] . ' min' ) : '—'; ?>
						</td>
						<td>
							<?php echo null === $row['score'] ? '—' : esc_html( (string) $row['score'] ); ?>
						</td>
						<td>
							<?php echo $row['penalty'] ? esc_html( (string) $row['penalty'] ) : '—'; ?>
						</td>
						<td>
							<?php echo null === $row['total'] ? '—' : esc_html( (string) $row['total'] ); ?>
							<?php if ( ! empty( $row['is_scaled'] ) ) : ?>
								<abbr class="mvoc-streeto-scaled" title="<?php esc_attr_e( 'Adjusted onto the long-course scale', 'mvoc-streeto' ); ?>">*</abbr>
							<?php endif; ?>
						</td>
						<td>
							<?php echo null === $row['league_points'] ? '—' : esc_html( (string) $row['league_points'] ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<?php
	// Course, score and penalty are hidden on a narrow screen — see the
	// stylesheet. Said here rather than left to be noticed, because a runner
	// who took a penalty would otherwise see a total they cannot account for.
	?>
	<p class="mvoc-streeto-footnote mvoc-streeto-narrow-note">
		<?php esc_html_e( 'Course, score and penalty show on a wider screen — turn your phone sideways.', 'mvoc-streeto' ); ?>
	</p>

	<?php if ( ! empty( $model['scaled_courses'] ) ) : ?>
		<p class="mvoc-streeto-footnote">
			<?php
			// Course and percentage come from the series' own scoring rules, so
			// this says what was actually done rather than repeating a default.
			foreach ( $model['scaled_courses'] as $scaled ) {
				printf(
					/* translators: 1: course label such as 40, 2: percentage such as 150. */
					esc_html__( '* Scores on the %1$s-minute course are multiplied by %2$d%% so that both courses rank together.', 'mvoc-streeto' ),
					esc_html( (string) $scaled['label'] ),
					(int) $scaled['percent']
				);
				echo ' ';
			}
			?>
		</p>
	<?php endif; ?>

	<p class="mvoc-streeto-footnote">
		<?php esc_html_e( 'Equal totals finish equal, and are separated only by time penalty — never by finishing time.', 'mvoc-streeto' ); ?>
	</p>
</div>
