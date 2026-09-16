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
					<?php foreach ( $model['columns'] as $column ) : ?>
						<th scope="col"><?php echo esc_html( $column ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $model['rows'] as $row ) : ?>
					<tr<?php echo ! empty( $row['is_organiser'] ) ? ' class="mvoc-streeto-organiser"' : ''; ?>>
						<td data-label="<?php esc_attr_e( 'Pos', 'mvoc-streeto' ); ?>">
							<?php echo esc_html( $row['position_label'] ?: '—' ); ?>
						</td>
						<?php foreach ( \MVOC\StreetO\Domain\Categories::columns() as $key => $label ) : ?>
							<?php
							// Blank rather than a dash where they are not in the
							// category: a dash reads as "no position yet". Written
							// on one line so the cell is genuinely empty, which is
							// what lets the stacked phone layout drop it instead of
							// printing a label with nothing after it.
							?>
							<td class="mvoc-streeto-category-col" data-label="<?php echo esc_attr( $label ); ?>"><?php echo isset( $row['positions'][ $key ] ) && null !== $row['positions'][ $key ] ? esc_html( (string) $row['positions'][ $key ] ) : ''; ?></td>
						<?php endforeach; ?>
						<th scope="row" data-label="<?php esc_attr_e( 'Name', 'mvoc-streeto' ); ?>">
							<?php echo esc_html( $row['name'] ); ?>
							<?php if ( ! empty( $row['is_organiser'] ) ) : ?>
								<span class="mvoc-streeto-tag"><?php esc_html_e( 'Organiser', 'mvoc-streeto' ); ?></span>
							<?php endif; ?>
						</th>
						<td data-label="<?php esc_attr_e( 'Course', 'mvoc-streeto' ); ?>">
							<?php echo $row['course'] ? esc_html( $row['course'] . ' min' ) : '—'; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Score', 'mvoc-streeto' ); ?>">
							<?php echo null === $row['score'] ? '—' : esc_html( (string) $row['score'] ); ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Penalty', 'mvoc-streeto' ); ?>">
							<?php echo $row['penalty'] ? esc_html( (string) $row['penalty'] ) : '—'; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Total', 'mvoc-streeto' ); ?>">
							<?php echo null === $row['total'] ? '—' : esc_html( (string) $row['total'] ); ?>
							<?php if ( ! empty( $row['is_scaled'] ) ) : ?>
								<abbr class="mvoc-streeto-scaled" title="<?php esc_attr_e( 'Adjusted onto the long-course scale', 'mvoc-streeto' ); ?>">*</abbr>
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'League pts', 'mvoc-streeto' ); ?>">
							<?php echo null === $row['league_points'] ? '—' : esc_html( (string) $row['league_points'] ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

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
