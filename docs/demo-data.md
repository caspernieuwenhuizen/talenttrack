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
| PDP cycle | A season per year the window covers, a development dossier per player per season, its conversation cycle, calendar links and verdicts |
| Training content | Exercises and principles on each training, per-team exercise overrides, holiday windows |
| Match day | Prep for every fixture, and results, goals and substitutions for the ones already played |
| Test trainings | Open sessions for invited players, one past and one upcoming per age group |
| Team development | A formation and playing-style mix per team, a match-day blueprint, coach-marked pairings, and a chemistry series |
| Scouting pipeline | Scouting visits across the window and the prospects found on them |
| Trial cases | Historical trials on existing players plus open ones, each with a staff panel, assessments and extensions |
| Tournaments | A tournament per team with its squad, target minutes, fixtures and per-period assignments |
| Staff development | Coaching badges, development plans and goals, evaluations with ratings, mentor pairings |
| Media | A squad photo per team, portraits of the players whose family gave media consent, and one external video link |
| Messages and operator records | Conversations with read state, saved filters, report presets, workflow tasks, invitations |
| Behaviour and potential | Behaviour ratings across the window, and dated potential histories for squads old enough to be asked |

Presets scale the volume: **tiny** (1 team, 4 weeks), **small** (3 teams,
8 weeks), **medium** (6 teams, 16 weeks), **large** (12 teams, 36 weeks).
Each preset generates 12 players per team.

The squads are **spread across your age-group ladder** rather than taken from the youngest end, so a three-team academy gets a young squad, an older one, and something in between. That matters for more than variety: potential bands, PDP cycles and evaluations with development plans are not things to demonstrate on seven-year-olds, and before this the oldest demo player *was* seven. Age groups whose name carries no age — a **Senior** catch-all, say — are skipped, because the generator derives a player's birth year from the group name and would otherwise fill a senior squad with children.

**Evaluations come on two cadences**, because there are two things being
recorded. **Round evaluations** are written four times a season — a
start-of-season baseline, two mid-season rounds and an end-of-season review —
a few days ahead of the PDP conversation that reviews them, so the evidence
panel on every generated conversation has the round behind it. **Match
evaluations** are written against the matches the run generates, for roughly a
third of the matches a player **played** — carrying that match's opponent, the
result and the minutes the player was actually on the pitch for. A player who
was benched or unavailable has no write-up for that Saturday, because there is
nothing to write up. A three-year window therefore gives a player around a
dozen round evaluations rather than three hundred, which is the difference
between a list a coach scans and one they scroll.

Ratings are written **on the scale the install is configured for** and land on
values that scale can express — no 6.4 on a step of 1. An archetype that
improves moves at least one whole step across a season, so the development
story is legible from the list without opening a chart.

**Media consent is stated on every player**, and deliberately not the same
for all of them. Every fifth player in a squad has no consent on record; the
rest carry a yes with the date they joined and the coach who took it. Which
players those are follows their position in the squad rather than chance, so
regenerating with the same seed gives the same answer.

Photos follow from that. A **portrait** — a photo of one child — is only taken
of a player whose family gave consent, which is what makes the consent field
visibly do something. The **squad photo** keeps everyone, including the
players without consent. That is on purpose: one image depicting children of
mixed consent is exactly the case the media tab has to handle, and a demo that
quietly left those players out of the team photo could not show it.

**Behaviour and potential** are seeded with their gaps intact. Roughly one player in five old enough to have a potential band does not have one, one per squad is left overdue, and one is revised **down** rather than up. That is deliberate: the traffic light, the *Potential not revisited* alert and the potential trajectory all exist to make missing and moving data visible, and a demo where nothing is ever missing or overdue makes them look like features that never fire. Potential is not seeded below age 13 at all — the product does not ask for it there, so neither does the demo.

The week count is how far **back** the activity window runs. On top of it every
preset also generates **four weeks ahead**, so a demo install has a next match
and upcoming trainings — the week planner, match prep and the upcoming-activity
alerts all have something to show. Future activities carry no **result**: no
register, no minutes, no ratings and no match execution. Match prep is written
for them, which is what a coach's screen looks like mid-week.

Every team keeps the same **weekly rhythm**, whichever day you generate on:
trainings on **Tuesday and Thursday** from 18:30 to 20:00, and every third week
the Thursday training gives way to a **match on Saturday**, kicking off at 10:00
with players reporting at 09:15. So a Saturday in a match week always has a
fixture to hang minutes, the register and match evaluations on.

Generating runs in steps, over as many requests as it takes, and the whole
calendar is laid out against the moment the run **started**. A long generation
that carries on past midnight, or across the turn of a week, still produces one
consistent grid rather than a fixture in one week and the training that
precedes it in another.

Every fixture has an **opponent** and alternates between **home and away**; an
away game gives the opponent's ground as its location. A match that has been
played shows as **Finalized** on the match executions list, the way a match
reads once the coach has reviewed it and locked it after the final whistle.

They do carry a **planned squad**, though, and so does every past activity.
Attendance is two different things — the squad a coach planned and the register
they took afterwards — and a generated academy used to contain only the second.
That made half the attendance model invisible locally, including every state
where the two disagree, which is the state most of the attendance bugs of the
last year have turned on.

A run now produces all three cases, so the surfaces that handle them have
something to stand on:

- **planned and registered** — a past activity where both exist for the same
  player, which is the ordinary state and the one most easily misread;
- **planned, not yet played** — a future activity with a squad and no register;
- **planned, never registered** — a minority of past activities nobody took the
  register for, so the empty-register confirm, the completeness counts and the
  *attendance not recorded* alert have a case.

Planned squads use the real plan vocabulary — mostly *Expected*, with some *Not
coming* and *Maybe* — rather than marking everybody as coming.

## Seasons

The window decides how many **seasons** a run builds: one per season-year it
touches, on the club's August-to-June convention. A short window gives one
season; three years of history gives three or four, depending on where in the
calendar the run starts. A season the club already has is reused rather than
duplicated, and is never removed by a wipe.

Each season carries its **own PDP cycle** — a dossier per player, four
conversations, and the coach's preparation for each of them. Seasons that have
finished are **closed with a verdict**; the current one stays open at whatever
stage the window puts it.

A dossier is only ever closed once **every conversation in its cycle has been
held**. For a finished season that is all of them, so all of its dossiers
close. In a window covering only the current season, a minority of the
dossiers whose cycle has already run its course close — and early in a season,
where every conversation is still in the diary, none do. An open dossier with
four conversations to come is what that season honestly looks like; a
completed one with a signed-off verdict over talks nobody has had would say
the player's year was finished before it started.

Prior seasons are **not archived** — archived rows drop out of most
lists, and hiding most of what was generated is the opposite of why it was
generated.

The squad **moves between seasons**. A player who is U13 this season was U12
last season and U11 the season before, and their `tt_player_team_history`
spells say so — as does the work: the trainings they attended, the evaluations
written about them and the test sessions they sat in a past season all belong
to the squad they were in **then**, not to the one they are in now. A team the
academy's cohorts had not reached yet in an early season gets no sessions in
it, rather than a calendar full of sessions nobody attended.

Players **arrive and leave**. Some join partway through the window, and a few
per squad left the academy at the end of an earlier season — released, off
every current roster, but with the history they leave behind intact, down to
the dossier whose verdict is the release itself. Nobody leaves in a run whose
window covers a single season; a squad cannot have changed between seasons
there are not two of.

**Set my own numbers** under the preset opens three fields — teams, players per
team, weeks of history — prefilled from the chosen preset and overridable per
run. This is the only way to change the player count, and therefore the number
of demo accounts: every preset ships 12 players per team, which suits a U15
squad and not a U8 one playing six-a-side. Leave a field empty and the preset's
value is used, so touching nothing generates exactly what the preset always did.
The line below the fields shows the resulting player and account count as you
type.

Values are clamped to what a run can finish — at most **40 teams**, **40
players per team** and **156 weeks** (three years) of history. A number above
a maximum is not refused: the run goes ahead with the maximum and **says so**,
in a notice on this page and as a warning on the command line, naming the
number asked for and the number used.

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

A guardian link needs a WP account, and the demo user set ships two parent
personas, so each parent account is given a small family rather than every
player getting a guardian. That is enough for the parent persona to sign in
and see a populated dashboard; the rest of the roster has no linked guardian,
which is also what a real academy looks like.

The two families are deliberately different sizes, and the sizes are fixed
rather than rolled. **Demo Parent** gets one child — a guardian who lands
straight on that child's record. **Demo Parent Of Two** gets two, which is
what makes the child picker and the dashboard child switcher reachable. Every
generated academy therefore demonstrates both, instead of the multi-child
guardian appearing in roughly two runs out of three.

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

The training step finishes by working out **how many minutes each player has
spent on each principle**, the same calculation the nightly job does. So a
player's training tab is right the moment the run ends, rather than reading
"seven trainings, nothing ever trained" until a scheduled job nobody on a demo
install waits for.

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

Every run gets its own batch, including two runs started in the same second.
They used to be able to share one — the batch name was built from the preset,
the seed and the time to the second — which meant a wipe scoped to "that
batch" took both runs with it, and the second run treated the first run's
players and trainings as its own and tried to write their details twice.

The demo WP user accounts survive a data wipe. Removing them is a separate
action ("Wipe demo users"), guarded so it refuses to delete an account whose
email is outside the configured demo domain, the account you are logged in
as, or the last remaining administrator.

### When a wipe cannot finish

A batch is removed in bounded steps rather than one statement per entity
type, so a batch with hundreds of thousands of rows of one kind — evaluation
ratings, usually — comes out in full. If the database still refuses one of
those steps, the wipe says so: the confirmation turns into a warning naming
the entity types it could not clear, and those rows keep their demo tags so
running the wipe again picks them up. A wipe that reports a row count and no
warning has removed everything it was asked to.

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
