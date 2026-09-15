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

**Status:** the harness exists and now covers the repository code that had none;
it still does not run in CI.

`tools/integration-test.php` boots a real WordPress via `wp-load.php`, creates a
throwaway series, exercises the persistence layer against a live database and
cleans up after itself. `tools/verify.sh` runs it **only when given a path**:

```sh
./tools/verify.sh ~/Local\ Sites/mvoc/app/public
```

Without one it prints `integration skipped (no WordPress path given)` and
`verify.sh` still exits 0. Every run on record — including every release run —
reports `skipped`.

That the script is never run has already cost something. 1.0.0 gave
`Results_Repo::delete_manual()` a required `$event_id` and left
`integration-test.php` calling it with one argument: a guaranteed
`ArgumentCountError` that sat in the repository through two releases, invisible
because `php -l` checks only syntax and the unit tests never load that file.
`tools/check-references.php` now also checks call arity across `tools/` and
`tests/`, so that specific failure cannot recur silently.

**What it covers now.** Schema and column existence, that no birth year is
stored anywhere, series and event CRUD, clearing and renaming MapRun sources,
manual rows, overrides, the delete-refused-while-results-exist guard, per-season
Over-55 flags, exactly-one-active-season, and the v11 ladder migration. Added
after the 1.0.0 review, covering SQL that had never been executed anywhere:

- `Competitors_Repo::merge()` with both competitors sharing a season, an event
  and a result — every unique key that can collide, colliding — asserting that
  organiser credit survives, that all three uniquely-keyed join tables end with
  one row on the survivor, and that nothing anywhere still points at the
  absorbed id;
- `delete_event()` leaving no `event_organisers` rows behind;
- `delete_manual()` refusing a row that belongs to a different event.

**What it still does not cover.** The import pipeline end to end (it needs
either a MapRun response or a stubbed client), activation and uninstall, and
anything in the admin screens, which are only reachable through a real request.

**Cost to finish:** a WordPress service container in the release workflow, or a
`wp-env` step, and a path passed to `verify.sh`. Consider making a missing path
a failure rather than a skip once CI supplies one, so `skipped` stops being a
passing outcome.

**Why it matters:** of the four defects found reviewing 1.0.0 and its release,
three were in code no database-free test can reach — two repository methods and
the call above. `SchemaConsistencyTest` closes part of the gap by reading the
DDL and comparing it against what the repos write, but it cannot catch a query
that is syntactically fine and semantically wrong.
