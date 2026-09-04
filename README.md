# MVOC Winter StreetO Results

A WordPress plugin for Mole Valley Orienteering Club's Winter Street Orienteering league.

It pulls each event's results from MapRun, lets the league co-ordinator correct them, and
renders the event and league tables on the club website through shortcodes — replacing a
spreadsheet, a lot of retyping, and a copy-and-paste step.

The series is eight monthly events from September to April, each offering a 60-minute and a
40-minute score course. Four competitions run off the same results: Overall, Ladies,
Over-55 Men and Over-55 Women.

## Why

The job used to run like this: read the results out of MapRun, key them into a spreadsheet,
work out by eye which "D Smith" was the same person as last month's "Dave Smith", then paste
the finished tables into a WordPress page. Slow, easy to get wrong, and dependent on one
person and one file on their laptop.

## Status

Working and in testing. Import, correction, publishing and both shortcodes are complete;
the co-ordinator's guide and a live-site trial are outstanding.

## Installing

Build a zip and upload it through **Plugins → Add New → Upload Plugin**:

```sh
./tools/build-zip.sh
```

Needs PHP 7.4+ and WordPress 6.0+. Both are declared in the plugin header, so WordPress
refuses to activate rather than failing badly on an older host.

Activating creates the plugin's own tables and a **League Co-ordinator** role, so the
co-ordinator gets these screens without full site administration. It alters no existing
table and renders nothing until a shortcode is placed on a page.

See [docs/deploying.md](docs/deploying.md) for where to test and what to check first.

## Using it

1. **Series and events → Start a new season.** Pick the year it starts; the name, the
   shortcode slug, the age year for the Over-55 categories and all eight fixture dates
   follow from it. Everything stays editable.
2. Enter each event's MapRun event name against its course.
3. **Results** on an event → **Fetch from MapRun**, or paste the response.
4. **Confirm names** for anyone new. Ladies and Over-55 are pre-filled from MapRun's own
   data, so this is confirming rather than classifying. Both are correctable afterwards on
   the **Competitors** screen — Ladies against the person, Over-55 against the season.
5. Resolve any duplicates, correct rows, add anyone by hand, name the organiser.
6. **Save and publish.** Nothing is public until then.

Then put the shortcodes on the event page:

```
[mvoc_streeto_event series="2026-27" number="1"]
[mvoc_streeto_league series="2026-27" through_event="1"]
[mvoc_streeto_league series="2026-27" through_event="1" category="ladies"]
```

The league table shows every ranking side by side — Pos, Ladies, M55 and W55 — the way the
club's spreadsheet did, with a cell left blank where someone is not in that category. The
`category` attribute filters which *rows* appear rather than which columns, so a ladies table
still shows where each of them sits overall. Categories are `overall`, `ladies`, `o55_men`
and `o55_women`.

`through_event` caps the standings at that event number, so event 1's page keeps recording
the league as it stood after event 1 even once later events publish. It's still computed
live rather than snapshotted, so a correction made to event 1 afterwards still shows up
there. Leave it out for the full current standings — that's what a standing "latest league"
page wants, and leaving the series out too means it never needs editing when the season
rolls over:

```
[mvoc_streeto_league]
```

## Scoring

The rules were reverse-engineered from the club's own spreadsheet and are verified against
its cached results — a whole event and a whole season are committed as fixtures and
reproduced exactly.

**Event table.** `Total = round((Score − Penalty) × factor)`, where the factor brings a
40-minute result onto the 60-minute scale. The club's event information states that rule
directly: the *net* score is multiplied by 150%, which is exactly 60/40 — and "net" is what
settles that the penalty comes off before the scaling.

```
Position = count(better totals) + 1 + count(equal total with a smaller penalty)
```

That second term is the club's deliberately coarse tie-break: equal totals finish equal and
are separated **only** by time penalty, never by finishing time. League points run 50 for
first down to 1 for fiftieth, and 1 for anything below.

**League table.** The best 5 results count. An organiser scores their best result again in
place of the event they ran, and that bonus competes for one of the five counting slots
rather than being added on top.

Over-55 follows British Orienteering's convention — the age reached on 31 December of the
competition year, which is why a year of birth is enough. A winter league straddles two
years, so the season's starting year decides.

The flag is held **per season**, not per person. Competitors are deliberately global, so a
name confirmed one year still resolves the next — but age is not: everybody's changes every
year. A single flag would move a runner into the Over-55 table of every season already
published the moment they turned 55.

All of it lives in `Scoring_Config`, stored per series, so a rule change is a settings edit.

## Design

Three layers, kept strictly separate:

| Layer | Where | Rule |
|---|---|---|
| Raw | `fetches`, `raw_*` columns | What MapRun said, stored verbatim and never edited |
| Overrides | `overrides` | Corrections as their own rows, with a reason and an author |
| Computed | derived | Tables built from raw + overrides on demand |

Any published number can be traced back to the MapRun response it came from.

### Corrections survive a re-import

The co-ordinator will import, correct for twenty minutes, then re-import when a late upload
appears. That has to be safe:

- Rows match on MapRun's `Id`, never on a name. Names change spelling between uploads; ids
  do not. An id identifies a *result*, not a person — one runner's three uploads carry three
  different ids.
- **Rows are never deleted.** One that has vanished is marked withdrawn, because MapRun
  dropping a result is likelier a glitch than a fact, and deleting would take the correction
  with it. A row that returns is restored with its id, and its corrections, intact.
- Hand-added rows carry no MapRun id, which is what makes them untouchable by an import.
- An import writes only the raw columns — never a resolved value, an exclusion, or a
  competitor link.

`Import_Reconciler` holds those decisions in plain PHP, so they are proven by unit tests
without a database.

### Name matching

The same person arrives as "Dave Smith" one month and "David Smith" the next, enters their
club four different ways, and may be recorded with or without a hyphen. Matching runs in
order of decreasing certainty: a confirmed alias resolves silently, and anything else gets
ranked suggestions scored on surname, first name, year of birth and club.

Club is a mild confirmation, never a refutation, because runners change clubs and often
leave the field blank. Year of birth was once the decisive signal here, but it is no longer
stored, so genuine namesakes now both surface as candidates and the co-ordinator picks —
acceptable precisely because nothing is ever merged automatically.

Diminutives are handled, but only where the short form is unambiguous. "Sam" is deliberately
absent, because it maps to both Samuel and Samantha, and guessing across genders is exactly
the error the list must not introduce.

**Nothing is ever auto-merged**, however strong the suggestion. A wrong merge hands one
runner another's league points — worse than asking a question with an easy answer.

## The MapRun API

```
GET https://p.fne.com.au:8886/resultsGetPublicForEventv2?eventName=<full event name>
```

Unauthenticated. The envelope is
`{ errorFlag, statusMessage, warningFlag, warningMessage, results: [...] }`.

`GrossScore` is the points collected and `NetScore` the figure after the time penalty, so
the penalty is their difference. `Gender` and `YearOfBirth` are both supplied, which is why
neither category needs classifying by hand.

The year of birth is used to derive the Over-55 flag at import and then discarded — **no
date of birth is stored anywhere**, and a test asserts no table ever grows a column that
looks like one. Holding every member's date of birth to work out one boolean was not a fair
trade.

Things real responses contain that a hand-written test fixture would not:

- **`warningFlag`**, raised when an event name matches more than one MapRun event — which is
  what produces duplicate rows. Surfaced, never swallowed.
- **`(RevNN)` suffixes on surnames**, recording which course revision a result was scored
  against. Stripped for matching — but *not* a duplicate marker: some runners carry one
  while appearing only once.
- **`Classifier: "--"`** for a failed upload: zero score, zero time, no punches. Excluded
  from ranking, kept visible.
- **Repeat punches appended out of order.** `punchControlIds` gets "Extra" punches added at
  the end regardless of when they happened, so the parser re-sorts by time.

Duplicates are clustered on identical start, finish and elapsed time rather than on the name
suffix — far stronger evidence of one run scored twice. The runner's name is part of that
signature, so pairs who set off together are never merged.

### Port 8886

The API is on a non-standard port, and shared hosting often blocks outbound traffic to
anything but 80 and 443. So **pasting the JSON is a first-class path**, not an emergency
fallback: both routes run through identical validation and parsing. The MapRun Explorer
screen tests which is available on a given host.

## Development

```sh
composer install
./vendor/bin/phpunit                         # unit tests, no database needed
php tools/check-references.php               # every self:: and $this-> resolves
php tools/integration-test.php /path/to/wp   # against a real WordPress and database
```

The domain classes carry no WordPress dependencies, so the logic worth testing is testable
with plain PHPUnit. The integration test exists because that leaves a gap: three bugs got
through it — a column the repo wrote but the schema lacked, a constant removed with a caller
left behind, and a form value mangled in transit. None were visible without WordPress
actually running. Worth running all three before any deploy.

## Layout

```
mvoc-streeto-results/     The plugin — this directory is what gets installed
  includes/               Bootstrap, schema, MapRun client and parser, domain logic
  admin/                  Admin screens
  public/                 Shortcodes, templates, styles
tests/                    Unit tests and fixtures
tools/                    Build and verification scripts
docs/                     Deployment notes
```

## Licence

GPL-2.0-or-later, matching WordPress.
