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
				<?php echo esc_html( $event['title'] ); ?>
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
						<th scope="row" data-label="<?php esc_attr_e( 'Name', 'mvoc-streeto' ); ?>">
							<?php echo esc_html( $row['name'] ); ?>
							<?php if ( ! empty( $row['is_organiser'] ) ) : ?>
								<span class="mvoc-streeto-tag"><?php esc_html_e( 'Organiser', 'mvoc-streeto' ); ?></span>
							<?php endif; ?>
						</th>
						<td data-label="<?php esc_attr_e( 'Club', 'mvoc-streeto' ); ?>"><?php echo esc_html( $row['club'] ); ?></td>
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
								<abbr class="mvoc-streeto-scaled" title="<?php esc_attr_e( 'Adjusted for the 40-minute course', 'mvoc-streeto' ); ?>">*</abbr>
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

	<?php if ( ! empty( $model['has_short_course'] ) ) : ?>
		<p class="mvoc-streeto-footnote">
			<?php esc_html_e( '* Scores on the 40-minute course are multiplied by 150% so that both courses rank together.', 'mvoc-streeto' ); ?>
		</p>
	<?php endif; ?>

	<p class="mvoc-streeto-footnote">
		<?php esc_html_e( 'Equal totals finish equal, and are separated only by time penalty — never by finishing time.', 'mvoc-streeto' ); ?>
	</p>
</div>
