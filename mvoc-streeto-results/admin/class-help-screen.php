<?php
/**
 * Help & FAQ: the co-ordinator's guide to running a season, in one place.
 *
 * Static reference content only — no persistence, no form handling. Kept as
 * its own screen so the workflow, the screen-by-screen reference and the FAQ
 * live next to the tool itself rather than in a document nobody has open.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Admin;

use MVOC\StreetO\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Help & FAQ screen.
 */
class Help_Screen {

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mvoc-streeto' ) );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Help & FAQ', 'mvoc-streeto' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'A season, start to finish: pull results from MapRun, correct them, publish them, and let the shortcodes below keep the club website in sync. This page is the guide to doing that — the FAQ near the bottom answers the questions that come up most.', 'mvoc-streeto' ); ?>
			</p>

			<?php
			$this->render_contents();
			$this->render_workflow();
			$this->render_screens();
			$this->render_shortcodes();
			$this->render_scoring();
			$this->render_faq();
			$this->render_troubleshooting();
			?>
		</div>
		<?php
	}

	/**
	 * Jump list to each section below.
	 */
	private function render_contents(): void {
		?>
		<ul style="list-style:disc;margin:0 0 1.5em 1.5em;max-width:30em;">
			<li><a href="#mvoc-help-workflow"><?php esc_html_e( 'How a season runs, start to finish', 'mvoc-streeto' ); ?></a></li>
			<li><a href="#mvoc-help-screens"><?php esc_html_e( 'Screen by screen', 'mvoc-streeto' ); ?></a></li>
			<li><a href="#mvoc-help-shortcodes"><?php esc_html_e( 'Shortcodes', 'mvoc-streeto' ); ?></a></li>
			<li><a href="#mvoc-help-scoring"><?php esc_html_e( 'How scoring works', 'mvoc-streeto' ); ?></a></li>
			<li><a href="#mvoc-help-faq"><?php esc_html_e( 'Frequently asked questions', 'mvoc-streeto' ); ?></a></li>
			<li><a href="#mvoc-help-troubleshooting"><?php esc_html_e( 'Troubleshooting', 'mvoc-streeto' ); ?></a></li>
		</ul>
		<?php
	}

	/**
	 * The season workflow, start to finish.
	 */
	private function render_workflow(): void {
		?>
		<h2 id="mvoc-help-workflow"><?php esc_html_e( 'How a season runs, start to finish', 'mvoc-streeto' ); ?></h2>
		<ol style="max-width:55em;">
			<li>
				<?php
				printf(
					/* translators: %s: "Series and events", the menu item name. */
					esc_html__( '%s → Start a new season. Pick the year it starts; the name, the shortcode slug, the Over-55 age year and all eight fixture dates follow from it. Everything stays editable afterwards.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( 'Series and events', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
			<li>
				<?php esc_html_e( 'Enter each event\'s MapRun event name against its course, on the Series and events screen. The 40-minute course is a separate MapRun event and can be left blank until one exists.', 'mvoc-streeto' ); ?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: "Event results", the menu item name. */
					esc_html__( 'Open the event\'s %s screen and either Fetch from MapRun, or paste the response — both run through identical checks.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( 'Event results', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: "Confirm names", the menu item name. */
					esc_html__( '%s for anyone new. Ladies and Over-55 are pre-filled from MapRun\'s own data, so this is confirming rather than classifying.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( 'Confirm names', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
			<li>
				<?php esc_html_e( 'Back on Event results: resolve any duplicates, correct rows, add anyone by hand, and name the organiser.', 'mvoc-streeto' ); ?>
			</li>
			<li>
				<?php esc_html_e( 'Save and publish. Nothing is public until then.', 'mvoc-streeto' ); ?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: "Create draft post", the button name on the Event results screen. */
					esc_html__( 'Click %s at the top of Event results for a post with this event\'s shortcodes already in it, or write the post yourself and put the shortcodes on it by hand — see Shortcodes below.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( 'Create draft post', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
		</ol>
		<p class="description">
			<?php esc_html_e( 'That order matters once, not every time: from event 2 onward you are mostly repeating steps 2-6 for the next fixture, on a plugin that already knows the season.', 'mvoc-streeto' ); ?>
		</p>
		<?php
	}

	/**
	 * Reference for each admin screen.
	 */
	private function render_screens(): void {
		?>
		<h2 id="mvoc-help-screens"><?php esc_html_e( 'Screen by screen', 'mvoc-streeto' ); ?></h2>

		<h3><?php esc_html_e( 'Series and events', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'The landing page: where a season starts, and where every event\'s results are reached from.', 'mvoc-streeto' ); ?></p>
		<ul style="list-style:disc;margin:0 0 1.5em 1.5em;max-width:55em;">
			<li>
				<?php
				printf(
					/* translators: %s: "Make this the active season", a button label. */
					esc_html__( 'Only one season is active at a time. Active is what a shortcode with no series= attribute shows — the setting a standing "latest league" page depends on. Switch it with %s.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( 'Make this the active season', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: 1: "Season starting" field label, 2: "Create season" button label. */
					esc_html__( '%1$s + %2$s builds the eight fixtures for a new season on the third Tuesday of each month, September to April. Dates, the name, the slug and the age year are all editable afterwards.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( 'Season starting', 'mvoc-streeto' ) . '</strong>',
					'<strong>' . esc_html__( 'Create season', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
			<li>
				<?php esc_html_e( 'The events table columns: # is the event number shortcodes refer to (number="3"); Date drives the suggested MapRun names; Title / venue titles the event everywhere it is shown; Organiser creates a competitor automatically if the name typed there is not yet recognised; the two MapRun columns hold the exact event name as published in MapRun, one per course.', 'mvoc-streeto' ); ?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: "Fill in the suggested names", a button label. */
					esc_html__( '%s only fills MapRun-name boxes that are still empty — it never touches anything already entered, so it is safe to click at any time.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( 'Fill in the suggested names', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
			<li>
				<?php esc_html_e( 'Status is Draft until an event is published, or Cancelled if it will not run (numbering stays intact either way). Delete only appears while nothing has been imported for that event yet — once results exist, Cancel it instead.', 'mvoc-streeto' ); ?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: "Results", a link label. */
					esc_html__( 'Results opens that event\'s Event results screen — where the actual import and correction work happens.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( 'Results', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
		</ul>

		<h3><?php esc_html_e( 'Event results', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'One event\'s whole correction workflow, in the order its six numbered sections appear on screen.', 'mvoc-streeto' ); ?></p>
		<ul style="list-style:disc;margin:0 0 1.5em 1.5em;max-width:55em;">
			<li>
				<?php
				printf(
					/* translators: 1: "1. Import" section name, 2: "Paste JSON instead" link label. */
					esc_html__( '%1$s — Fetch from MapRun, or use %2$s where the server cannot reach it (see Troubleshooting). Re-importing is always safe: "Last imported…" is shown, and every correction below survives it.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( '1. Import', 'mvoc-streeto' ) . '</strong>',
					'<strong>' . esc_html__( 'Paste JSON instead', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: "2. Duplicates" section name. */
					esc_html__( '%s — the same run recorded more than once, usually against two course revisions. Pick which one to keep; the other is excluded, never deleted. Nothing is chosen for you, because which scoring is right is a judgement call.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( '2. Duplicates', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: "3. Results" section name. */
					esc_html__( '%s — the row-by-row table: edit Score, Penalty or Exclude, and give a reason for the correction. Badges explain anything unusual: "failed upload" (a zero-score MapRun row, excluded from ranking but kept visible), "not in the latest import" (see the FAQ below), "added by hand", and "name not confirmed" (fix on Confirm names).', 'mvoc-streeto' ),
					'<strong>' . esc_html__( '3. Results', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: "4. Add runners by hand" section name. */
					esc_html__( '%s — for anyone MapRun never recorded. A hand-added row carries no MapRun id, which is exactly what keeps it untouched by every later import.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( '4. Add runners by hand', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: "5. Organiser" section name. */
					esc_html__( '%s — listed on the results but not ranked. They score their best result again in the league table instead. Almost always one person; untick to remove, or add another where an event was run jointly.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( '5. Organiser', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: 1: "Save corrections" button, 2: "Save and publish" button, 3: "Return to draft" button. */
					esc_html__( '%1$s keeps the event a draft; %2$s makes it — and the league table it feeds — live immediately. %3$s (shown only once published) unpublishes it again.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( 'Save corrections', 'mvoc-streeto' ) . '</strong>',
					'<strong>' . esc_html__( 'Save and publish', 'mvoc-streeto' ) . '</strong>',
					'<strong>' . esc_html__( 'Return to draft', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: "Create draft post" button label. */
					esc_html__( '%s builds a draft news post — titled, categorised, tagged, and already carrying this event\'s shortcodes — so publishing the write-up is a copy-editing job, not a fresh page every month.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( 'Create draft post', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
		</ul>

		<h3><?php esc_html_e( 'Confirm names', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'Tells the plugin who each name MapRun sent that it does not yet recognise belongs to. Each answer is remembered, so a spelling is only ever asked about once — and a row left unresolved does not score until it is.', 'mvoc-streeto' ); ?></p>
		<ul style="list-style:disc;margin:0 0 1.5em 1.5em;max-width:55em;">
			<li>
				<?php
				printf(
					/* translators: %s: "Decide later" option label. */
					esc_html__( '%s is the default — it skips the row for now and asks again next time. Nothing is scored, and nothing is lost, while a row sits here.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( 'Decide later', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: "New competitor" option label. */
					esc_html__( '%s creates them, with Ladies and Over-55 pre-filled from MapRun\'s own data — correctable afterwards on Competitors.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( 'New competitor', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: "Or the same person as:" option label. */
					esc_html__( '%s offers ranked suggestions with a match percentage, for someone whose spelling changed. Nothing is ever chosen automatically, however strong the match — a wrong merge hands one runner another\'s league points.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( 'Or the same person as:', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
			<li>
				<?php esc_html_e( 'If you match to an existing competitor whose stored name differs from the spelling that just arrived, a checkbox offers to update it to the synced spelling — useful when MapRun has corrected a typo.', 'mvoc-streeto' ); ?>
			</li>
		</ul>

		<h3><?php esc_html_e( 'Competitors', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'The master list of everyone who has ever run, for correcting categories and merging duplicate records.', 'mvoc-streeto' ); ?></p>
		<ul style="list-style:disc;margin:0 0 1.5em 1.5em;max-width:55em;">
			<li><?php esc_html_e( 'Both categories come from MapRun, which is self-declared and sometimes wrong or missing. An edit here sticks and is never overwritten by a later import — except by the rebuild tool described below, which you control.', 'mvoc-streeto' ); ?></li>
			<li><?php esc_html_e( 'Ladies belongs to the person, so it is edited once and applies to every season. Over-55 belongs to one season, because everyone\'s age changes every year — a runner who turns 55 does not retroactively move into an already-published season\'s Over-55 table.', 'mvoc-streeto' ); ?></li>
			<li>
				<?php
				printf(
					/* translators: %s: "Rebuild Over-55 from MapRun" button label. */
					esc_html__( '%s re-derives the flag for the whole season from MapRun\'s year-of-birth data. Useful when a lot of rows were imported before this plugin started keeping that data — but it overwrites any hand corrections made for this season, so use it before correcting individuals, not after.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( 'Rebuild Over-55 from MapRun', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: "Merge into" column label. */
					esc_html__( '%s combines two competitor records that turned out to be the same person — moving every result and name spelling across, then deleting the now-empty one. There is no undo, so check the spelling and club first.', 'mvoc-streeto' ),
					'<strong>' . esc_html__( 'Merge into', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
		</ul>

		<h3><?php esc_html_e( 'MapRun Explorer', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'A setup and diagnostic tool, not part of the weekly workflow — use it once per site to confirm the server can reach MapRun, or any time a MapRun response looks unexpected.', 'mvoc-streeto' ); ?></p>
		<ul style="list-style:disc;margin:0 0 1.5em 1.5em;max-width:55em;">
			<li>
				<?php
				printf(
					/* translators: %s: "Test connection" button label. */
					esc_html__( '%s answers the one question only the club\'s server can: green means automatic fetching works; amber means the host blocks it and Paste JSON is the working route (see Troubleshooting).', 'mvoc-streeto' ),
					'<strong>' . esc_html__( 'Test connection', 'mvoc-streeto' ) . '</strong>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'Inspect an event shows every field MapRun returned, a sample value for each, and a hint about which field holds the score — handy if MapRun ever changes its response shape and results stop parsing.', 'mvoc-streeto' ); ?></li>
		</ul>
		<?php
	}

	/**
	 * Shortcode reference.
	 */
	private function render_shortcodes(): void {
		?>
		<h2 id="mvoc-help-shortcodes"><?php esc_html_e( 'Shortcodes', 'mvoc-streeto' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Two shortcodes: one event\'s results, and the league standings. Put the event\'s own shortcode on its page, followed by the league as it stood at that point in the season.', 'mvoc-streeto' ); ?>
		</p>
		<p>
			<code>[mvoc_streeto_event series="2026-27" number="1"]</code><br />
			<code>[mvoc_streeto_league series="2026-27" through_event="1"]</code><br />
			<code>[mvoc_streeto_league series="2026-27" through_event="1" category="ladies"]</code>
		</p>
		<ul style="list-style:disc;margin:0 0 1.5em 1.5em;max-width:55em;">
			<li>
				<?php
				printf(
					/* translators: %s: the "series" shortcode attribute. */
					esc_html__( '%s is the season\'s slug, shown on Series and events. Leave it out and the shortcode follows whichever season is currently active — what a standing page wants, so it never needs editing when the season rolls over.', 'mvoc-streeto' ),
					'<code>series</code>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: the "number" shortcode attribute. */
					esc_html__( '%s (event shortcode only) is the event number from the # column.', 'mvoc-streeto' ),
					'<code>number</code>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: the "through_event" shortcode attribute. */
					esc_html__( '%s (league shortcode only) caps the standings at that event number — still computed live, so a correction made afterwards to an earlier event still shows up here. Leave it out for the full current standings.', 'mvoc-streeto' ),
					'<code>through_event</code>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: the "category" shortcode attribute. */
					esc_html__( '%s (league shortcode only) filters which rows appear — overall, ladies, o55_men or o55_women — while still showing every ranking (Pos, Ladies, M55, W55) side by side, blank where someone is not in that category. It filters rows, never columns, so a ladies-only page still shows where each of them sits overall.', 'mvoc-streeto' ),
					'<code>category</code>'
				);
				?>
			</li>
		</ul>
		<p class="description">
			<?php esc_html_e( 'A standing "latest league" page wants both left out entirely:', 'mvoc-streeto' ); ?>
		</p>
		<p>
			<code>[mvoc_streeto_league]</code>
		</p>
		<p class="description">
			<?php esc_html_e( 'Nothing renders from a draft event or an unpublished league on the public site — a half-corrected table can never appear there by accident.', 'mvoc-streeto' ); ?>
		</p>
		<?php
	}

	/**
	 * Plain-language scoring rules.
	 */
	private function render_scoring(): void {
		?>
		<h2 id="mvoc-help-scoring"><?php esc_html_e( 'How scoring works', 'mvoc-streeto' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Reverse-engineered from the club\'s own spreadsheet and checked against its cached results, so this is what the club has always done, not a new rule.', 'mvoc-streeto' ); ?>
		</p>
		<ul style="list-style:disc;margin:0 0 1.5em 1.5em;max-width:55em;">
			<li>
				<?php
				printf(
					/* translators: %s: the event total formula. */
					esc_html__( 'Event total: %s — the net score (after the time penalty) scaled onto the 60-minute course, then rounded. A 40-minute result is multiplied by 150%%, which is exactly 60/40.', 'mvoc-streeto' ),
					'<code>round((Score − Penalty) × factor)</code>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: the late penalty rate. */
					esc_html__( 'Time penalty: %s, worked out from the finishing time rather than taken from MapRun. MapRun charges 30 points for every minute you start, so 47 seconds over the hour costs a full 30 there and 24 here. Correct the Penalty box on the results screen and your figure wins over both.', 'mvoc-streeto' ),
					'<code>1 point per 2 seconds late</code>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'Position: count how many totals beat yours, add one, then add anyone tied with you on total but with a smaller time penalty. Equal totals finish equal and are separated only by penalty, never by finishing time — a deliberately coarse tie-break, matching the club\'s own rule.', 'mvoc-streeto' ); ?></li>
			<li><?php esc_html_e( 'League points run 50 for first place down to 1 for fiftieth, and 1 for anything below.', 'mvoc-streeto' ); ?></li>
			<li><?php esc_html_e( 'League total: the best 5 event results count. An organiser\'s bonus (their best result, scored again) competes for one of those five slots rather than being added on top.', 'mvoc-streeto' ); ?></li>
			<li><?php esc_html_e( 'Over-55 follows British Orienteering\'s convention: the age reached on 31 December of the year the season starts. It is fixed per season, not per person, which is why the same runner can be Over-55 in one season\'s table and not in another.', 'mvoc-streeto' ); ?></li>
		</ul>
		<p class="description">
			<?php esc_html_e( 'Every rule above lives in that season\'s scoring settings, so a rule change is a settings edit, not a code change — ask if a season needs different figures from these.', 'mvoc-streeto' ); ?>
		</p>
		<?php
	}

	/**
	 * Frequently asked questions.
	 */
	private function render_faq(): void {
		?>
		<h2 id="mvoc-help-faq"><?php esc_html_e( 'Frequently asked questions', 'mvoc-streeto' ); ?></h2>

		<h3><?php esc_html_e( 'I re-imported an event. Did I just lose my corrections?', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'No. Rows are matched on MapRun\'s own id, never on a name, and an import only ever writes the raw MapRun columns — never a corrected score, an exclusion, or a competitor link. Re-importing refreshes what MapRun is authoritative about and leaves every correction exactly where you left it. That is the plugin\'s central promise, and it is worth rehearsing once: import, correct a row, re-import, and check the correction is still there.', 'mvoc-streeto' ); ?></p>

		<h3><?php esc_html_e( 'A row is flagged "not in the latest import" — what does that mean, and what should I do?', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'The row was in an earlier import but MapRun\'s latest response no longer includes it. It is marked withdrawn rather than deleted, because MapRun dropping a result is more often a glitch than a fact, and deleting it would take any correction with it. If the run reappears in a later import, it is restored with its original id and corrections intact. If the event itself was replaced in MapRun (see the next question), this is expected for every runner who has not yet appeared under the new event name.', 'mvoc-streeto' ); ?></p>

		<h3><?php esc_html_e( 'MapRun says the event was replaced with a corrected version — now what?', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'It happens: an event is found to be faulty and the organiser uploads a corrected version under a new MapRun name. Point that course at the new name on Series and events and import again. The old rows are withdrawn (not deleted), so they keep their corrections and stay visible — flagged as above — while the new rows are added alongside. Anyone who only ran under the old event drops off the published table until you decide what to do with them, which is deliberate: visibly, not silently.', 'mvoc-streeto' ); ?></p>

		<h3><?php esc_html_e( 'Why are there two rows for what looks like the same run?', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'Usually one run scored against two course revisions — MapRun\'s warning about "multiple events found" for that event name is what produces it. The Duplicates section clusters rows on matching start, finish and elapsed time (strong evidence of one run scored twice) and lets you pick which scoring to keep; the other is excluded, not deleted, so the decision can be revisited.', 'mvoc-streeto' ); ?></p>

		<h3><?php esc_html_e( 'A name looks obviously right — why is it still asking me to confirm it?', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'Nothing is ever merged automatically, however strong the suggested match, because a wrong merge silently hands one runner another\'s league points. Matching runs in order of decreasing certainty — a confirmed alias resolves silently next time, and everything else is ranked and left for a human decision. Diminutives are handled only where the short form is unambiguous: "Sam" is deliberately excluded because it could be Samuel or Samantha, and guessing across that split is exactly the error this list must not introduce.', 'mvoc-streeto' ); ?></p>

		<h3><?php esc_html_e( 'I confirmed someone as a new competitor by mistake, and they already existed under a different spelling — can this be fixed?', 'mvoc-streeto' ); ?></h3>
		<p>
			<?php
			printf(
				/* translators: %s: "Merge into", a column label on the Competitors screen. */
				esc_html__( 'Yes — on Competitors, use %s to combine the two records. Every result and name spelling moves across and the duplicate is deleted. There is no undo, so double-check which record is being kept before saving.', 'mvoc-streeto' ),
				'<strong>' . esc_html__( 'Merge into', 'mvoc-streeto' ) . '</strong>'
			);
			?>
		</p>

		<h3><?php esc_html_e( 'Why is the same person Over-55 this season but not shown that way in an older one?', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'Over-55 is deliberately held per season, not per person, because age changes every year. A single flag on the person would move a runner into the Over-55 table of every season already published the moment they turned 55 — which would silently rewrite history. Ladies, by contrast, belongs to the person and does carry across seasons.', 'mvoc-streeto' ); ?></p>

		<h3><?php esc_html_e( 'Can a past, already-published season still be corrected?', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'Yes. A correction to any event recalculates live wherever that event feeds into a league table — including a through_event page from earlier in the season — so a fix made months later still shows up correctly everywhere.', 'mvoc-streeto' ); ?></p>

		<h3><?php esc_html_e( 'What happens if I mark an event Cancelled?', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'It is skipped by scoring everywhere, for everyone, but its event number is not reused — the fixture list and every shortcode\'s numbering stay stable around it.', 'mvoc-streeto' ); ?></p>

		<h3><?php esc_html_e( 'I saved a correction — why does the public page still show the old figures?', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'League tables are cached briefly for speed, but any write that could change what one shows — a publish, a correction, a manual row, a cancellation — invalidates that cache immediately, so a change should appear on the next page load. If it genuinely does not, check the event itself is Published and not still a Draft; a draft event\'s results and the league rows they would contribute are only ever visible to someone who could publish it.', 'mvoc-streeto' ); ?></p>

		<h3><?php esc_html_e( 'Who can see and use each of these screens?', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'Activating the plugin creates a League Co-ordinator role that can reach every screen described on this page, without needing full site administration. Tools is the one exception: it is restricted to a true site Administrator, because it can permanently delete every StreetO record on the site.', 'mvoc-streeto' ); ?></p>
		<?php
	}

	/**
	 * Troubleshooting section.
	 */
	private function render_troubleshooting(): void {
		?>
		<h2 id="mvoc-help-troubleshooting"><?php esc_html_e( 'Troubleshooting', 'mvoc-streeto' ); ?></h2>

		<h3><?php esc_html_e( 'MapRun Explorer shows Amber, or Fetch from MapRun fails', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'MapRun\'s API listens on port 8886, a non-standard port that a lot of shared hosting blocks outbound. This is a hosting limit, not a fault in the plugin, and it was designed around from the start rather than patched afterwards: every screen that fetches from MapRun also offers Paste JSON, which runs through exactly the same validation and parsing. Open the exact URL shown on the Event results or MapRun Explorer screen in a browser tab, copy the response, and paste it in. If it is worth fixing properly, ask the site\'s host to allow outbound traffic from the web server to p.fne.com.au on port 8886.', 'mvoc-streeto' ); ?></p>

		<h3><?php esc_html_e( '"You do not have permission to view this page"', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'The logged-in account is neither a League Co-ordinator nor a site Administrator. Ask a site Administrator to grant the League Co-ordinator role (for the everyday screens) — Tools additionally requires full Administrator access, on purpose.', 'mvoc-streeto' ); ?></p>

		<h3><?php esc_html_e( 'Nothing shows up where a shortcode is placed', 'mvoc-streeto' ); ?></h3>
		<p><?php esc_html_e( 'Check, in order: the event or league genuinely has something published for it yet; the series= attribute (if used) matches the slug shown on Series and events exactly; and, for an event shortcode, that its number matches the # column. A neutral placeholder message rather than a blank space usually means the shortcode itself is fine but there is nothing published yet to show.', 'mvoc-streeto' ); ?></p>

		<h3><?php esc_html_e( 'A MapRun response looks wrong, or a score is not being read', 'mvoc-streeto' ); ?></h3>
		<p>
			<?php
			printf(
				/* translators: %s: "MapRun Explorer", the menu item name. */
				esc_html__( 'Paste the same response into %s\'s Inspect an event tool. It lists every field MapRun sent with a sample value, and hints at which field it thinks holds the score — useful for spotting whether MapRun has changed its response shape.', 'mvoc-streeto' ),
				'<strong>' . esc_html__( 'MapRun Explorer', 'mvoc-streeto' ) . '</strong>'
			);
			?>
		</p>
		<?php
	}
}
