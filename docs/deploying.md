# Deploying for testing

## Build the zip

```sh
./tools/build-zip.sh
```

Produces `build/mvoc-streeto-results-<version>.zip`, ready for WordPress's plugin
uploader. It contains only the plugin directory — the tests, the fixtures and Composer's dev
dependencies are left out. That is deliberate on two counts: the plugin has no runtime
dependencies at all, and the fixtures hold real competitors' names and birth years, which
have no business on a web server.

## Check the host first

**Tools → Site Health → Info → Server** on mvoc.org, and note the PHP version.

The plugin needs **PHP 7.4+** and **WordPress 6.0+**. Both are declared in the plugin
header, so WordPress refuses to activate rather than white-screening if the host is older.

## Where to test

### Option A — a throwaway local WordPress (safest)

[LocalWP](https://localwp.com) is free, installs in a few minutes, and lets you pick the PHP
version so you can rehearse against whatever mvoc.org actually runs. Best place to practise
the correction workflow, because you can break things freely and start again.

What it cannot tell you is whether the club's server can reach MapRun — see below.

### Option B — a staging site

If the club's host offers one-click staging, this is the best of both: a real copy of the
live site, on the same server, so the connectivity answer is the real one.

### Option C — the live site

Lower risk than it sounds, because of what the plugin does and does not touch:

- It creates its **own** tables, prefixed `wp_mvoc_so_`. No existing table is altered.
- It adds a "League Co-ordinator" role and one capability.
- It renders **nothing** anywhere until you place a shortcode on a page.
- Deactivating drops no data. Uninstalling only drops data if you explicitly opt in.

So an install-and-activate on live is close to inert. The one thing to be careful about is
placing a shortcode on a published page before you have reviewed the results — and drafts
are invisible to the public anyway, so even that is guarded.

## Install

1. **Plugins → Add New → Upload Plugin**, choose the zip, **Install Now**, **Activate**.
2. Activation creates the tables and the role. Nothing else happens.

## The one question only the club's server can answer

**StreetO Results → MapRun Explorer → Test connection.**

MapRun's API listens on port **8886**, and a lot of shared hosting blocks outbound traffic to
anything but 80 and 443. This is untested on mvoc.org and cannot be tested from anywhere else
— a laptop reaching MapRun says nothing about whether the web server can.

- **Green:** automatic fetching works. Nothing more to do.
- **Amber:** the host blocks it. Everything still works via **Paste JSON**, which runs through
  exactly the same validation and parsing — the event screen shows the exact URL to open for
  each course, so it is open, copy, paste. This was designed in from the start, not bolted on.

The check distinguishes two cases, because they need different answers. If the server can
reach `p.fne.com.au` on the normal web port but not on 8886, it is an outbound firewall rule
and the host can change it:

> Please allow outbound TCP from the web server to `p.fne.com.au` on port 8886.

If it cannot reach the host on any port, outbound traffic is restricted more broadly and it
is worth asking the host what is permitted.

**As of the first live install on mvoc.org, port 8886 is blocked** — cURL error 7, refused in
66 ms, which is a firewall rejecting the connection rather than a timeout. Pasting is
therefore the working route there unless the host opens the port.

## First run

1. **Series and events → Start a new season.** Pick the year it starts; the name, the
   shortcode slug, the age year for the Over-55 categories and all eight fixture dates
   follow from it. Dates land on the third Tuesday of each month, September to April, which
   is where every one of the published 2026/27 fixtures falls. Everything stays editable.
2. Paste each event's MapRun event name against its course. The 40-minute course is a
   separate MapRun event (`… ScoreQ40`); leave it blank until one exists.
3. **Results** on an event → **Fetch from MapRun** (or paste).
4. **Confirm names** for anyone new — the event is preselected. Ladies and Over-55 are
   pre-filled from MapRun's own data, so this is confirming rather than classifying.
5. Back on the event: resolve any duplicates, correct rows, add anyone by hand, name the
   organiser.
6. **Save and publish.** Nothing is public until then.
7. Put the shortcodes on the event page:

```
[mvoc_streeto_event series="2026-27" number="1"]
[mvoc_streeto_league series="2026-27"]
```

Leaving the series out shows whichever season is marked current, which is what a standing
league page wants — it then never needs editing when the season rolls over:

```
[mvoc_streeto_league]
```

## Verifying a build

Three checks, all runnable without a full WordPress test harness:

```sh
composer install && ./vendor/bin/phpunit     # 236 unit tests, no database needed
php tools/check-references.php               # every self:: and $this-> resolves
php tools/integration-test.php /path/to/wp   # 42 checks against a real database
```

The last one bootstraps a real WordPress, creates a throwaway series, exercises the
persistence layer against it and removes it again. It exists because the unit tests
deliberately touch no database, and three bugs got through that gap — a column the repo
wrote but the schema lacked, a constant removed with a caller left behind, and a form value
mangled in transit. None were visible without WordPress actually running.

Worth running all three before any deploy.

## The test that matters most

Import, make a correction, then **import again** and check the correction is still there.

That is the design's central promise — result rows are matched on MapRun's id and never
deleted, so a re-import refreshes only what MapRun is authoritative about. It is proven by
unit tests, but it is worth seeing with your own eyes before the season depends on it.

A good rehearsal, using last season's real data:

1. Import `Worcester Park Apr26 PXAS ScoreQ60`.
2. Resolve the duplicate the response contains — one runner scored twice, 760 against 730.
3. Change someone's score and give a reason.
4. Import again.
5. Both the duplicate decision and the score correction should be untouched.

## Running the tests

Needs PHP and Composer, neither of which is on the machine this was last built from:

```sh
composer install
./vendor/bin/phpunit
```
