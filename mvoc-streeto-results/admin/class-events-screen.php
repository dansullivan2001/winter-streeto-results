<?php
/**
 * Series and event setup.
 *
 * Seeds the 2026/27 series with its eight fixtures in one click, so the
 * co-ordinator is not typing dates in before the first event.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Admin;

use MVOC\StreetO\Plugin;
use MVOC\StreetO\Repo\Events_Repo;

defined( 'ABSPATH' ) || exit;

/**
 * Lists and edits the series and its events.
 */
class Events_Screen {

	private const NONCE = 'mvoc_streeto_events';

	public const DEFAULT_SLUG = '2026-27';

	/**
	 * The club's published fixture list for 2026/27.
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

	/**
	 * @param Events_Repo|null $repo Events persistence.
	 */
	public function __construct( ?Events_Repo $repo = null ) {
		$this->repo = $repo ?? new Events_Repo();
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
		$events = $series ? $this->repo->events( $series['id'] ) : array();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Series and events', 'mvoc-streeto' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $series ) : ?>
				<p><?php esc_html_e( 'No series set up yet. This creates the 2026/27 series with its eight published fixtures; you can adjust anything afterwards.', 'mvoc-streeto' ); ?></p>
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
			?>

			<h2><?php echo esc_html( $series['name'] ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Enter the MapRun event name for each course. The 40-minute course is a separate MapRun event, normally the same name ending ScoreQ40 — leave it blank until it exists.', 'mvoc-streeto' ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( '#', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Date', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Venue', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'MapRun event — 60 min', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'MapRun event — 40 min', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mvoc-streeto' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $events as $event ) : ?>
							<?php
							$sources = array();
							foreach ( $this->repo->sources( $event['id'] ) as $source ) {
								$sources[ $source['course_label'] ] = $source['maprun_event_name'];
							}
							$number = (int) $event['event_number'];
							?>
							<tr>
								<td><?php echo esc_html( (string) $number ); ?></td>
								<td><?php echo esc_html( (string) $event['event_date'] ); ?></td>
								<td><?php echo esc_html( (string) $event['venue'] ); ?></td>
								<td>
									<input type="text" class="regular-text"
										name="sources[<?php echo esc_attr( (string) $number ); ?>][60]"
										value="<?php echo esc_attr( $sources['60'] ?? '' ); ?>"
										placeholder="Burpham Sep26 PXAS ScoreQ60" />
								</td>
								<td>
									<input type="text" class="regular-text"
										name="sources[<?php echo esc_attr( (string) $number ); ?>][40]"
										value="<?php echo esc_attr( $sources['40'] ?? '' ); ?>"
										placeholder="&hellip; ScoreQ40" />
								</td>
								<td>
									<?php
									echo $event['is_published']
										? esc_html__( 'Published', 'mvoc-streeto' )
										: esc_html__( 'Draft', 'mvoc-streeto' );
									?>
								</td>
								<td>
									<a href="<?php echo esc_url( $this->review_url( $event['id'] ) ); ?>">
										<?php esc_html_e( 'Results', 'mvoc-streeto' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<button type="submit" name="mvoc_streeto_action" value="save_sources" class="button button-primary">
						<?php esc_html_e( 'Save MapRun event names', 'mvoc-streeto' ); ?>
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

		if ( 'save_sources' === $action ) {
			return $this->save_sources();
		}

		return '';
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
	 * Save the MapRun event names against each event's courses.
	 */
	private function save_sources(): string {
		if ( ! isset( $_POST['sources'] ) || ! is_array( $_POST['sources'] ) ) {
			return '';
		}

		$series = $this->repo->find_series( self::DEFAULT_SLUG );
		if ( ! $series ) {
			return '';
		}

		$saved = 0;

		foreach ( wp_unslash( $_POST['sources'] ) as $number => $courses ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$event = $this->repo->find_event( $series['id'], (int) $number );

			if ( ! $event || ! is_array( $courses ) ) {
				continue;
			}

			$sources = array();
			foreach ( $courses as $course => $name ) {
				$name = sanitize_text_field( (string) $name );

				if ( '' !== $name ) {
					$sources[] = array(
						'maprun_event_name' => $name,
						'course_label'      => preg_replace( '/[^0-9]/', '', (string) $course ),
					);
					++$saved;
				}
			}

			$this->repo->save_sources( $event['id'], $sources );
		}

		return sprintf(
			/* translators: %d: number of MapRun event names saved. */
			_n( 'Saved %d MapRun event name.', 'Saved %d MapRun event names.', $saved, 'mvoc-streeto' ),
			$saved
		);
	}
}
