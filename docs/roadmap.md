# Roadmap

Functionality that is specified, half-built or implied somewhere in the codebase
but is **not implemented**. Each entry says what exists today, what is missing,
and what it would cost — so that a comment describing behaviour is never the only
record of whether that behaviour is real.

Opened alongside the 1.0.0 code review. Nothing here blocks a release; all of it
is deferred deliberately.

---

## Shared-map results

**Status:** table exists, nothing reads or writes it.

`wp_mvoc_so_result_competitors` is created and indexed by `Schema`, with a unique
key on `(result_id, competitor_id)`. It is meant for the case where two people
run one map together and both should take that row's league points.

What is missing is everything except the table:

- no UI on the event review screen to link a second competitor to a row;
- `Scoring_Engine` and `League_Service` both read `results.competitor_id` only,
  so a link would not affect any score;
- nothing writes the table at all.

The sole reference is `Competitors_Repo::merge()`, and only so that a merge
cannot orphan rows if the table is ever populated.

**Today's workaround:** add the second runner as a hand-entered row on the event
review screen, with the same score. It is one extra row to key in per shared map,
and the league treats the two as independent results, which for a shared map is
the right answer anyway.

**Cost to build:** a competitor picker per row that writes the join table, plus a
read in `League_Service::standings_with_notes()` that credits every linked
competitor rather than just `competitor_id`. The unique key is already correct.

**Why it is deferred:** the case is genuinely rare, the workaround is cheap, and
guessing pairs from an `&` in a name — the obvious shortcut — is exactly the kind
of automatic merge the plugin refuses to do elsewhere.

---

## Editing a season's scoring rules

**Status:** fully modelled, stored, and read — but no way to change it.

A series carries its whole `Scoring_Config` as JSON on the series row, and the
engine reads every rule from there: the points ladder, how many events count,
course factors and time limits, rounding, penalty ordering, the tie-break and the
organiser bonus mode. This is what lets a finished season keep the rules it was
published under while a new one starts on different ones.

`Events_Repo::save_scoring_config()` exists to write it. **Nothing calls it.**
The only writes are `ensure_series()` at creation and the v11 ladder migration in
`Schema`. A co-ordinator cannot change any scoring rule from the admin screens.

Practically this means:

- the courses a season offers are fixed at creation (every screen now reads them
  from `Scoring_Config::course_labels()`, so an editor would immediately reach
  the whole UI);
- changing `counting_events`, the ladder or the pro-rata needs direct database
  access;
- `penalty_before_scaling` and `tiebreak_on_raw_penalty` are both documented as
  "assumed pending confirmation from the club" and cannot be flipped to check.

**Cost to build:** a settings panel on the Series and events screen, writing
through the existing `save_scoring_config()`. The domain layer needs nothing —
it already treats every one of these as data.

**Why it is deferred:** the defaults reproduce the club's own workbook and have
been checked against its computed answers, so nothing needs changing for the
seasons currently in scope. It becomes urgent the first time the club changes a
rule mid-plugin-life.

---

## Automatic import for the short course when MapRun has no event yet

**Status:** works, with a manual step.

"Fetch from MapRun" imports every configured source, so both courses come in
together once both MapRun event names are set. But the 40-minute MapRun event
often does not exist when a season is seeded, so `fill_suggested_names()`
deliberately fills only the longest course and leaves the short one blank for the
co-ordinator to add later.

There is no prompt or reminder that a source is still blank. An event silently
imports one course until somebody notices.

**Cost to build:** a notice on the review screen when an event has fewer sources
than the series has courses.

---

## Integration coverage

**Status:** the harness exists; it has not been run in CI.

`tools/integration-test.php` runs against a real WordPress install and
`tools/verify.sh` will run it when given a path — but every run recorded so far
reports `integration skipped (no WordPress path given)`, including in the release
workflow.

Everything below the domain layer is therefore unexercised by automation: the
repositories, the migrations in `Schema::install()`, activation, and uninstall.
`SchemaConsistencyTest` covers part of the gap by reading the DDL and comparing it
against what the repos write, which is why it exists, but it cannot catch a query
that is syntactically fine and semantically wrong.

**Cost to build:** a WordPress service container in the release workflow, or a
`wp-env` step, and a path passed to `verify.sh`.

**Why it matters:** two of the three defects found in the 1.0.0 review were in
repository code, and neither could have been caught by a test that does not touch
a database.
