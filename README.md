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

**1.0.0.** Import, correction, checking, publishing, both shortcodes and the co-ordinator's
guide (**StreetO Results → Help & FAQ**) are complete and covered by the suite. A live-site
trial is still outstanding, and the integration checks have only ever been run by hand.

Deferred functionality — the shared-map case, and editing a season's scoring rules from the
admin screens — is listed in [docs/roadmap.md](docs/roadmap.md) with what exists today and
what each would cost.

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
   data, so this is confirming rather than classifying. If a suggested match's stored name
   differs from the one just synced, there's an option to update it to the synced spelling.
   Names, club, Ladies and Over-55 are all correctable afterwards on the **Competitors**
   screen — Ladies against the person, Over-55 against the season.
5. Resolve any duplicates, correct rows, add anyone by hand, name the organiser.
6. **League table** to check the standings the event produces. It counts unpublished
   events, so this is the league exactly as publishing would leave it — and it warns
   where a result scored but has no name confirmed against it, which is the usual
   reason someone is on the event table and missing from the league.
7. **Save and publish.** Nothing is public until then.

Then put the shortcodes on the event page:

```
[mvoc_streeto_event series="2026-27" number="1"]
[mvoc_streeto_league series="2026-27" through_event="1"]
[mvoc_streeto_league series="2026-27" through_event="1" category="ladies"]
```

Both tables show every ranking side by side — Pos, Ladies, M55 and W55 — the way the club's
spreadsheet did, with a cell left blank where someone is not in that category. On the event
table those are the rankings *on the night*, decided by the same rule as the overall
position and renumbered from one, so the leading lady reads 1st in the Ladies column
whatever she is overall. On the league table they are the season's standings. The organiser
is unranked in all four, on the night as before — their reward is the league bonus.

A runner's Ladies and Over-55 status comes from their competitor record, which is also
where the league reads it, so the two tables on one page can never classify anyone
differently. The corollary is that a result with **no name confirmed against it** holds no
category at all: it ranks overall and leaves the three category cells blank. **StreetO
Results → League table** warns about exactly those rows before you publish.

The `category` attribute filters which *rows* appear rather than which columns, so a ladies
league table still shows where each of them sits overall. Categories are `overall`,
`ladies`, `o55_men` and `o55_women`. It applies to the league shortcode only — an event
table always lists the whole field.

`through_event` caps the standings at that event number, so event 1's page keeps recording
the league as it stood after event 1 even once later events publish. It's still computed
live rather than snapshotted, so a correction made to event 1 afterwards still shows up
there. Leave it out for the full current standings — that's what a standing "latest league"
page wants, and leaving the series out too means it never needs editing when the season
rolls over:

```
[mvoc_streeto_league]
```

## Checking before publishing

**StreetO Results → League table** shows those same standings, computed by the same code,
before anything is public. It counts unpublished events by default — that is the point: the
question it answers is "if I publish this, what does the league look like?", which
previously could only be answered by publishing and looking at the website.

It is wider than the published table, because it is read at a desk rather than on a phone in
the dark: every ranking, a column per event, the organiser bonus and the total — the shape
the club's spreadsheet had. Scores in bold are the ones making up the total, so whether a
new result moves anyone or is simply dropped by the best-5 rule is visible at a glance.
`Through event` caps it the way the shortcode attribute does, for checking the table that
will appear on one event's page.

It also warns where a result scored but is attached to nobody. Those results are on the
event table and absent from the league, and the standings themselves cannot show it — a row
no competitor is linked to simply is not there. The warning links to **Confirm names**.

The screen is read-only, and publishing is still done from the event's own results screen:
checking and acting are deliberately separate.

## Scoring

The rules were reverse-engineered from the club's own spreadsheet and are verified against
its cached results — a whole event and a whole season are committed as fixtures and
reproduced exactly.

**Event table.** Position, Ladies, M55, W55, Name, Course, Score, Penalty, Total,
League points. Elapsed time is deliberately absent: the tie-break ignores it, so a time
column would only invite "why am I below someone slower?" when the rule simply does not
look at time. Club is absent too: MapRun takes it as free text, so one club arrives spelt
several ways and a published column prints the inconsistency rather than resolving it. It
is still imported and still kept — it tells two runners of the same name apart — and it is
editable on the **Competitors** screen.

`Total = round((Score − Penalty) × factor)`, where the factor brings a
40-minute result onto the 60-minute scale. The club's event information states that rule
directly: the *net* score is multiplied by 150%, which is exactly 60/40 — and "net" is what
settles that the penalty comes off before the scaling.

**The time penalty is the club's, not MapRun's.** The club charges 1 point per 2 seconds
late; MapRun charges 30 points per *started* minute. Same rate, different granularity — 47
seconds over the hour costs 30 points on MapRun's figures and 24 on the club's. So the
penalty is recomputed from the elapsed time and the course's limit (`60` → 3600s, `40` →
2400s) rather than read off `GrossScore − NetScore`, and a part-block counts in full, the
same shape as MapRun's own rule.

Because the elapsed time is stored raw alongside everything else, this applies to events
already imported without re-fetching them. `raw_penalty` still holds what MapRun charged,
unedited, and the review screen shows it next to the recomputed figure wherever the two
differ. A correction typed into the Penalty box still beats both. Where the elapsed time or
the course's limit is unknown — a hand-added row, an unrecognised course label — MapRun's
penalty stands, because a missing time is not evidence that nobody was late.

**When MapRun reports no score, the punches are scored instead.** MapRun only scores an
event it recognises as a score course, and whether it does comes down to the course name.
Burpham, September 2026, was set up as `Score Q60` with a space in it: MapRun marked all 44
finishers MP and returned `GrossScore: 0` for every one of them — while recording every
punch, in order, with its split time. The names pulled through and the table published a
field of nobodies on nil points.

So where MapRun reports no score, or zero, **and** the row carries punches, the score is
rebuilt from the control record: a control is worth its first digit times ten (13 scores 10,
27 scores 20, 55 scores 50), each control counting once however many times it was punched.
Nothing else is touched. A row MapRun scored keeps MapRun's figure even where the plugin
would have made the total something else, and a failed upload — no punches — keeps its
nothing rather than becoming a runner who scored nil.

A rebuilt score is not passed off as MapRun's. The import says how many rows it rebuilt, the
review screen marks each one *from punches* beside its score, and `results.score_source`
records it on the row. The club's late penalty is recomputed from the elapsed time as it is
for any other row; no penalty is attributed to MapRun, which charged none. Fixing the course
name in MapRun is still worth doing — a re-import then simply takes MapRun's own figures
again.

```
Position = count(better totals) + 1 + count(equal total with a smaller penalty)
```

That second term is the club's deliberately coarse tie-break: equal totals finish equal and
are separated **only** by time penalty, never by finishing time. League points run 100 for
first down to 1 for hundredth, and 1 for anything below.

**League table.** The best 5 results count. An organiser scores their best result again in
place of the event they ran, and that bonus competes for one of the five counting slots
rather than being added on top. The bonus waits for the event: naming next month's
organiser puts nothing on the table until that event's results are in, so a season's
fixtures can be set up in full without inventing points for events nobody has run.

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
leave the field blank. It arrives as free text from MapRun, so the same club turns up spelt
several ways; a short alias list collapses the ones that matter, and the Competitors screen
lets the rest be tidied by hand. Year of birth was once the decisive signal here, but it is
no longer stored, so genuine namesakes now both surface as candidates and the co-ordinator
picks — acceptable precisely because nothing is ever merged automatically.

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
their difference is the penalty *MapRun* charged — kept on record, but replaced by the
club's finer rule above. `TotalTimeSecs` is what that rule is measured from. `Gender` and
`YearOfBirth` are both supplied, which is why neither category needs classifying by hand.

The year of birth is used to derive the Over-55 flag at import and then discarded — **no
date of birth is stored anywhere**, and a test asserts no table ever grows a column that
looks like one. Holding every member's date of birth to work out one boolean was not a fair
trade.

Things real responses contain that a hand-written test fixture would not:

- **`warningFlag`**, raised when an event name matches more than one MapRun event, so the
  response merges results from all of them. Surfaced, never swallowed. It is *not* the cause
  of duplicate rows, though it reads like one: a September event came back with the flag
  clear and still contained a genuine `(Rev30)` duplicate. The two are independent, and
  treating the warning as the explanation would mean not looking on a clean event.
- **`(RevNN)` suffixes on surnames**, recording which course revision a result was scored
  against. Stripped for matching — but *not* a duplicate marker: some runners carry one
  while appearing only once.
- **`TrackStartDateTimeUTC`**, the only date in a row — everything else is a time of day
  with no day attached. It is not UTC: the offset is a flat ten hours in both GMT and BST,
  so MapRun is subtracting their own Australian Eastern Standard Time from the local
  wall-clock and mislabelling it. Ten hours back gives the date the run belongs to.
- **`Classifier: "--"`** for a failed upload: zero score, zero time, no punches. Excluded
  from ranking, kept visible.
- **`Classifier: "DNF"`**, which is not `--` and went unhandled for most of the plugin's
  life. Nothing excluded it, and the engine ranks on whether the score is numeric — where
  zero is numeric — so every DNF row took a position. A Cobham response had eight. Seven
  were empty on every measure and drew 46 league points each for equal-55th; a runner whose
  only row was one of those would have banked 46 for opening the app and abandoning it,
  against 50 for genuinely finishing 51st. The eighth had sixteen controls and 550 points
  with no finish punch, ranked 47th, and pushed thirteen runners down a place. Under the
  club's rule a missing finish punch means no score, so both shapes are now excluded on
  import and stay visible, exactly as `--` does. The zero-elapsed-time condition is required
  as well as the classifier: it is the trace the missing finish punch leaves, and it means a
  DNF arriving with a real time is left for the co-ordinator rather than dropped on a guess.

  Excluding is not deleting, so the score stays on the review screen and the row can be put
  back. That is where the counterpart annotation earns its place: an excluded row carrying a
  score is exactly what tempts someone into un-excluding it, and in a field of fifty there is
  no way to see by eye whether its owner is already in the table. Every excluded row now says
  either what that runner already scores — `this runner already scores here: 830 (18th)` —
  or that nothing else of theirs counts, which is the case where putting it back is right.
- **A failed upload MapRun did not mark as one.** A September response carried
  `Classifier: "OK"`, twenty controls and a score of 660, with zero elapsed time, zero
  distance and every punch at zero seconds. It is not `--`, so nothing excluded it; it is
  not a duplicate, so the detector never saw it; and the engine ranks on a numeric score
  alone, so it took 660 points in a field scoring 350 to 950. Such a row is now flagged for
  review — named up front, and its row highlighted and noted — but still scores until the
  co-ordinator either excludes it or ticks it as checked, because a broken upload and a real run whose timing MapRun lost
  look identical from here, and silently dropping the second would remove a genuine result.
- **Repeat punches appended out of order.** `punchControlIds` gets "Extra" punches added at
  the end regardless of when they happened, so the parser re-sorts by time.

Duplicates are clustered on identical start, finish and elapsed time rather than on the name
suffix — far stronger evidence of one run scored twice. The runner's name is part of that
signature, so pairs who set off together are never merged.

That strictness is right for the question it answers and blind to a second one, which
`Repeat_Entry_Detector` now asks: is this one *runner* scoring twice? A real Cobham response
had eleven such runners in a field of fifty-one, and the duplicate detector could see three
of them. The rest shared none of the four fields: a completed run alongside a stray
recording an hour later, scoring 0 over two minutes — and 0 is a number, so the stray ranked
— or two genuine runs against two course revisions, scoring 830 and 730 fourteen seconds
apart. Every one of them ranked, took a place off everyone below, and reached the published
table, while the league kept the better of the two without anybody choosing it. Sixty-seven
rows took a position in a field of fifty-one people.

Rows are grouped by the confirmed competitor where there is one and by name where there is
not, because on a fresh import nothing is linked yet and that is exactly when the table is
being read. Two runners who genuinely share a name are grouped only until they are linked to
different competitors, so the false positive is cleared by doing the thing the warning asks
for. Unlike the other two warnings this one carries no "checked" tick: one runner scoring
twice is wrong whichever row is right, so the only answer is to exclude the others.

Those four fields are therefore stored raw, alongside the course revision and the track
start date. They were not until v10, and the cost was exact: the detector was handed stored rows, found no start or
finish on any of them, and reported no duplicates on every event for the whole of the
plugin's life — while its own tests, which fed it parser output, passed. The upgrade
backfills the columns from the response snapshots in `fetches`, so an already-imported
season is repaired without re-fetching it. v11 adds the track start date the same way, and
the same backfill recovers it.

A MapRun course stays live after the night, so a run done weeks later arrives in the same
response and scores like any other — a real December event carried one from the following
April. That is not a duplicate of anything, so no amount of cluster detection would find
it; only the date does. Rows whose date is not the event's are named on the review screen
and score until the co-ordinator excludes them. Their rows carry the same highlight as a
zero-time row, and both drop it once answered.

Either warning can be answered two ways, and v13 adds the second. Excluding the row says it
should not have scored; ticking "Checked — keep this row" says it should, and both are
recorded in the overrides trail with whatever reason was given. Without the second, the only
answer the screen accepted was Exclude, so a row checked against the night and found genuine
went on warning for the rest of the season — and a warning that cannot be answered is the
one that teaches a co-ordinator to read past the colour, taking the next real warning with
it. The tick is deliberately inert: it changes no score, penalty or position, and a test
asserts that. It does not survive its own evidence either. An import that changes the row's
classifier, score, penalty, elapsed time or track start un-ticks it, because the decision was
about figures MapRun has since revised; an import that brings the same figures back — which
is most of them, on a night when the co-ordinator imports two or three times as late uploads
arrive — leaves it alone.

Once a cluster has been answered it stays on the screen but folds into an "already decided"
block, with the kept scoring still selected. It cannot simply vanish — that card is the only
place the course revisions are shown, so revisiting the choice anywhere else would mean
picking between two bare scores — and it must not look untouched either, which is what
invites the same decision to be made twice. A choice settles every row in its cluster rather
than only adding exclusions: picking the other scoring has to *clear* the first one, or both
rows end up excluded and the runner disappears from the event.

The lesson is in `tests/unit/StoredRowSeamTest.php`: a domain class proven against parser
output is not proven against what the database gives back. That test drives the whole
round trip — parse, through the columns an import writes, through the resolver the screens
call, into the domain class.

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
docs/                     Deployment notes and the roadmap
```

## Licence

GPL-2.0-or-later, matching WordPress.
