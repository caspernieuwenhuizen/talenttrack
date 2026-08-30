---
title: Demo data
group: configuration
summary: Fill a club with a plausible academy for exploring or demonstrating TalentTrack, and wipe it again cleanly.
audience: [admin, dev]
module: TT\Modules\DemoData\DemoDataModule
order: 150
---

# Demo data

The demo-data generator fills a club with a plausible academy: teams, a
roster, staff, a training calendar, evaluations and development goals. Use it
to explore TalentTrack, to demonstrate it, or to give a new install something
to look at before real data arrives.

Find it under **TalentTrack → Demo data** in wp-admin.

Every generated row is tagged with the batch that produced it, so a wipe
removes exactly what was generated and never touches real records.

## What gets generated

| Category | What it fills |
| --- | --- |
| People | Staff records plus the demo WP user accounts for each persona |
| Teams | Age-group teams with a head coach, and the coach/team assignments |
| Players | A roster per team, each with an archetype that shapes their ratings |
| Activities | Trainings and matches across the preset's window, with attendance |
| Evaluations | Evaluation rounds with per-category ratings following each player's archetype |
| Goals | One or two development goals per player |
| Journey events | Timeline entries written as each player, evaluation and goal is created |
| Guardians | Guardian links to the demo parent accounts, plus per-player parent-visibility grants |
| Injuries | Injury records with return-to-play dates, and the timeline events they raise |
| Player profile | Age-group history, attribute values, the club's custom fields and values, goal-to-evaluation links |
| Player reports | Generated reports across the audiences an academy produces |
| Measurements | A testing battery, per-age-group target bands, team testing sessions and one result per player |
| PDP cycle | The season, a development dossier per player, its conversation cycle, calendar links and verdicts |
| Training content | Exercises and principles on each training, per-team exercise overrides, holiday windows |
| Match day | Prep for every fixture, and results, goals and substitutions for the ones already played |
| Test trainings | Open sessions for invited players, one past and one upcoming per age group |
| Team development | A formation and playing-style mix per team, a match-day blueprint, coach-marked pairings, and a chemistry series |
| Scouting pipeline | Scouting visits across the window and the prospects found on them |
| Trial cases | Historical trials on existing players plus open ones, each with a staff panel, assessments and extensions |
| Tournaments | A tournament per team with its squad, target minutes, fixtures and per-period assignments |
| Staff development | Coaching badges, development plans and goals, evaluations with ratings, mentor pairings |
| Messages and operator records | Conversations with read state, saved filters, report presets, workflow tasks, invitations |

Presets scale the volume: **tiny** (1 team, 4 weeks), **small** (3 teams,
8 weeks), **medium** (6 teams, 16 weeks), **large** (12 teams, 36 weeks).
Each preset generates 12 players per team.

The week count is how far **back** the activity window runs. On top of it every
preset also generates **four weeks ahead**, so a demo install has a next match
and upcoming trainings — the week planner, match prep and the upcoming-activity
alerts all have something to show. Future activities are planned and carry no
result: no attendance, no minutes, no ratings and no match execution. Match
prep is written for them, which is what a coach's screen looks like mid-week.

**Set my own numbers** under the preset opens three fields — teams, players per
team, weeks of history — prefilled from the chosen preset and overridable per
run. This is the only way to change the player count, and therefore the number
of demo accounts: every preset ships 12 players per team, which suits a U15
squad and not a U8 one playing six-a-side. Leave a field empty and the preset's
value is used, so touching nothing generates exactly what the preset always did.
The line below the fields shows the resulting player and account count as you
type. Values are clamped to what a run can finish.

Generated match data is internally consistent, because reports read it as if
it were real: availability never marks a player present on a date their injury
record says they were out, goal scorers come from that match's lineup, and
substitutions take a starter off for a bench player so minutes played never
exceed the match length and a team's total lands exactly on squad size times
it. Squad size follows the age group — six for the youngest, eight in
the middle, eleven from the early teens — because youth football is
small-sided.

**Minutes are recorded on played matches**, derived from that substitution
stream: a starter who was never replaced gets the full match, one taken off
gets the minute they went off, and a substitute gets what was left when they
came on. A player who sat on the bench without appearing has no minutes rather
than zero — "did not feature" and "played nothing" are different facts, and the
minutes surfaces exist to tell them apart. Fixtures in the future carry no
minutes, because they have not been played.

Chemistry snapshots are computed by the chemistry engine from the team's
blueprint lineup, not invented, so a recompute agrees with what is stored.

Most generated players carry a historical trial case, closed with an admit
decision and dated before they joined the roster. Without it a demo academy's
players appear fully signed from nowhere, and the journey the product is built
around has no beginning.

**A generate run never sends email.** Invitations and workflow tasks are
written directly rather than through the services that dispatch them, so the
invitations screen shows rows in all four states without anyone receiving
anything.

Staff certifications are the one category that can come back empty: they
require the club's `cert_type` vocabulary, which has no default seed
(see #2490). The generator skips them rather than inventing lookup entries.

A guardian link needs a WP account, and the demo user set ships one parent
persona, so each parent account is given a small family (one to three
children) rather than every player getting a guardian. That is enough for the
parent persona to sign in and see a populated dashboard; the rest of the
roster has no linked guardian, which is also what a real academy looks like.

Generation is reproducible: the same seed, preset and content language
produce the same academy every time — and the same academy whether it was
generated in one go or a step at a time.

## How a run progresses

A run is a list of steps, not one long wait. The overlay names the step it is
on — *Step 7 of 24 — Evaluations* — and each step is its own short request to
the server, so the large preset no longer runs into the timeout your hosting's
proxy applies to a single request. That is what the **Proxy Error** on the
large preset used to be.

Leave the tab open until the overlay finishes. If you close it, or the
connection drops, the run stops where it was and the rows it had already
written stay — tagged, so a wipe still reaches them. Next time you open the
page it says so:

> **A demo run is unfinished.** 14 of 24 steps done, batch `large-20260504`.

**Resume this run** picks up at the next step. **Discard it** forgets the run;
the rows it wrote stay in the club until you wipe them. You cannot start a
second run while one is unfinished — two generations writing at once would
race on every table they touch.

If your browser has JavaScript switched off, the whole run happens in the one
request as before. That still works for the smaller presets; the large one is
what needs the steps.

## Generating twice into the same club

A second run adds to what is already there rather than replacing it. Each run
now builds its own academy: the matches it analyses and the trainings it
observes are the ones that run created, so a second run into a populated club
produces a full academy rather than a thinner one. It used to report lower
counts the second time, which read as a failure and was in fact the categories
looking at the whole club and skipping whatever the first run had covered.

Two categories still work from the whole club, and deliberately: **staff
development** and **knowledge courses** are about the people your academy
employs, and those may already exist rather than having been generated. Running
twice does not give a coach a second staff development file or a second
enrolment; those skip what is already there, so their counts can be lower on
the second run.

If you want a fresh academy rather than more of the same one, **wipe first,
then generate**.

## Choosing what to generate

The generate form splits into two groups.

**Master data** (teams, people, players) — uncheck any of these to build on
rows already in your club instead of generating new ones. If you uncheck
Teams, the club must already have teams; the form refuses the run otherwise
rather than silently producing nothing.

**Dependent entities** (activities, evaluations, goals, …) — uncheck any to
skip that category on top of whatever master data ends up present.

## Wiping

The wipe form removes demo-tagged rows by category. Each category also wipes
its dependents: wiping Teams removes the activities, attendance, evaluations
and ratings tied to those teams, and wiping Players removes that player's
evaluations, goals, journey events and trial cases.

Wiping Players does **not** remove teams, and wiping Teams does **not**
remove players — an operator rebuilding one usually wants to keep the other.

Scope a wipe to a single batch with the Batch dropdown, or leave it on
**All batches**.

The demo WP user accounts survive a data wipe. Removing them is a separate
action ("Wipe demo users"), guarded so it refuses to delete an account whose
email is outside the configured demo domain, the account you are logged in
as, or the last remaining administrator.

## Coverage

`src/Modules/DemoData/DemoCoverage.php` is the single source of truth for
what generation covers. Every `tt_*` table the schema creates appears there
exactly once, in one of three states:

- **generated** — a producer fills it. The entry names the `entity_type`
 used for demo tagging, the `category` the operator toggles, the
 `written_by` producer, and `depends_on` for delete ordering.
- **planned** — in scope but not written yet; the value is the issue that
 will write it (epic #2461).
- **exempt** — never generated, with the reason stated. Configuration,
 vocabulary, reference data seeded by migrations, system logs, and anything
 whose fabrication would be misleading or cause a side effect (a scheduled
 report would send real email; a Strava connection needs real OAuth tokens).

`tools/check-demo-coverage.php` fails when a table is in none of the three
states, so a migration that adds a table forces a generate-or-exempt
decision. `bin/demo-coverage-selfcheck.php` proves the derived delete order
is dependency-safe and that no generated entity type sits outside a wipe
cascade. Both run in CI on every PR.

### Adding a generator

1. Implement `DependentGeneratorInterface` — `category()`, `fromContext()`
 and `generate()` returning the row count. Tag every inserted row via
 `DemoBatchRegistry::tag()`; an untagged row is one the wipe can never
 reach, which leaves a permanent orphan on the operator's install.
2. Flip the table's manifest entry from `planned` to a generated entry, and
 add its `entity_type` to the cascade of the category that owns it.
3. Give the category a `tier`, a `run_order` and, if the Excel workbook has a
 matching sheet, an `excel_sheet` key.
4. Add a label and hint in `categoryLabel()` / `categoryHint()`. The
 generate and wipe forms pick the category up from there — neither form
 needs editing.

`run_order` matters more than it looks. Every dependent generator draws from
one MT stream seeded once per run, so inserting a generator ahead of an
existing one changes every random value after it and the same seed stops
reproducing the same academy. Append rather than insert unless you mean to
change the output.

Content strings belong in a per-language array on the generator itself
(see `GoalGenerator::TITLES_BY_LANGUAGE`), not behind `__()`. Generated rows
are stored data, and routing them through gettext would make the stored
content depend on whether `.mo` files happen to be compiled.

Where a module writes rows off a hook (journey events, via
`JourneyEventSubscriber`), fire the same action the real feature fires rather
than writing the rows directly — that keeps demo timelines identical in shape
to production ones. Those rows still need tagging; see
`DemoGenerator::tagUntaggedJourneyEvents()`.
