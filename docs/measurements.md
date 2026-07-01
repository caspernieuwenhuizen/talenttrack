<!-- audience: user, admin -->

# Measurements & Testing

A **measurement** is one recorded value of a test for one player on a date —
a sprint time, a height, a jump, a bleep-test level. Measurements give a
player's physical and athletic development a chronological, comparable
record alongside their evaluations and goals.

This page describes the foundation: the data model and who can see what.
The setup wizard, result-entry screens, and the per-player trend view roll
out on top of it.

## The pieces

- **Test (definition)** — a thing you measure (e.g. "Sprint 30m", "Height").
  Each test belongs to a **category** and has a **value type**, a **unit**,
  a **recurrence**, and a **direction** (is higher or lower better?).
- **Category** — the grouping a test sits under. Seeded with
  *Anthropometric*, *Physical*, *Technical*, and *Mental*; an admin can edit
  the list.
- **Unit** — the unit of measure. Seeded with proper units (cm, m, kg, g, s,
  min, reps, level, %, bpm); a test picks one **or** supplies its own custom
  unit.
- **Recurrence** — how often the test should run: annually, twice a year,
  quarterly, monthly, or ad hoc. This powers "who's due".
- **Session** — a planned testing moment for one team: one test, one date.
  Staff enter one value per player against it.
- **Target** — a per-age-group band (green / amber) for a test. A recorded
  value is flagged green, amber, or red against the band for the player's
  age group, respecting the test's direction.
- **Status levels** — for the **status** value type only: an operator-defined,
  ordered set of coloured levels (e.g. *At risk* red, *Watch* amber, *On
  track* green). A status test records a level per player rather than a
  number, and the player's latest level shows as a coloured chip on their
  profile.

## Status tests (a manual player status)

A **status** test is a simple, manually maintained, dated player status — a
stopgap until the computed player-status signal is rich enough to maintain
directly. It rides the measurement framework, so it gets dated history and
profile surfacing for free.

- Choose **A status (coloured levels)** as the value type when creating the
  test. The wizard then lands you on the test's edit screen.
- On the edit screen, define the **status levels** from lowest to highest:
  each level has a label and a colour picked from a curated palette (green,
  lime, yellow, amber, orange, red, cyan, blue, grey). Clear a level's label
  to remove it;
  the row order is the saved order.
- Record a status the same way as any other test — *Record measurements*
  shows a coloured **status picker** per player instead of a number field:
  a dropdown whose closed control and every open option show the level’s
  colour square next to its label, sized so the longest label never clips.
  The picker is fully keyboard- and touch-operable (open with Enter/Space or
  the arrow keys, move with ↑/↓, type-ahead, Escape to close); with
  JavaScript off it falls back to a plain native dropdown.
- On the player profile, the latest level appears as a coloured chip in the
  **Measurements** tab, painted in that level's colour. Status tests have no
  green/amber target band — their colour comes entirely from the picked
  level.

Every status change is a dated entry on the player record, so the player's
status history is queryable and visible over time. A seeded **Player status**
category is available to group these tests.

## Who can see what

Visibility follows the authorization matrix — no role is hard-coded:

| Persona | Sees |
| --- | --- |
| **Player** | Only their own measurements and trend. |
| **Parent** | Only their own child's measurements. |
| **Assistant / head coach, team manager** | Their team's results and sessions. |
| **Head of development, academy admin** | Every team's results, academy-wide. |

Coaches enter and edit results for their own team. The test catalogue
(definitions and targets) is set up by the head of development or an academy
admin. An academy admin or head of development can change any value.

## Recurrence values

| Value | Meaning |
| --- | --- |
| `annual` | Once a season |
| `biannual` | Twice a season |
| `quarterly` | Four times a season |
| `monthly` | Monthly |
| `adhoc` | No fixed cadence |

## Viewing a player's measurements

Players and parents get a **My measurements** tile that opens the
*Metingen* view: every test grouped by category, each showing its latest
value, a green/amber/red flag against the player's age-group target, a
small trend line, and how often it runs. A parent sees their child's view.

Staff see the same thing **in context** on the player's profile: open a
player and switch to the **Measurements** tab (beside Evaluations). The
tab badge counts how many tests the player has results for.

The player's **At a glance** panel also carries a **Measurements** signal
beside Avg rating, Attendance and Goals: the number of tests the player
currently has a value for, with a hint of how many sit *below target*
(amber or red against the age-group band) — or *on target* when none do.
It links straight into the Measurements tab for the full per-test
timeline. The signal only shows for viewers who can read measurements, so
the standing never leaks to a role that can't open the underlying tests.

## Recording results

Staff get a **Record measurements** tile. Pick a team, a test, and a date,
then enter one value per player and **Save all** — it saves the whole
roster in one go (blank players are skipped) and ties the values to a
testing session for that date. Numeric tests show a number field with the
unit; pass/fail tests show a dropdown. A coach can only record for their
own teams; the head of development and academy admin can record for any
team.

## Testing coverage (who's due)

Staff also get a **Testing coverage** tile. Pick a team and the screen
shows, for every test that has a recurrence, how many of the squad are
**up to date** versus the gap — and names the players who are **overdue**,
**due soon**, or have **never** been tested. It's player-centric: it starts
from the roster and surfaces exactly who still needs a test this cycle, so
you can plan a session. Tests with no recurrence (*ad hoc*) don't count
toward coverage. A coach sees their own teams; the head of development and
academy admin see every team. The same data is available over REST at
`GET /wp-json/talenttrack/v1/teams/{team_id}/measurement-coverage`.

## Creating a test

The head of development (or an academy admin) creates tests with the
**+ New test** wizard — reachable from the *Record measurements* screen.
It walks through three steps:

1. **Details** — the category, a name, and the value type (a number, a
   scale score, pass/fail, or a status with coloured levels).
2. **Unit & recurrence** — the unit (from the unit list or a custom one),
   whether higher or lower is better, and how often the test runs.
3. **Targets** — optional per-age-group green and amber bands; a recorded
   value flags against the band for the player's age group. You can leave
   these blank and add them later.

Finishing creates the test and its targets in one go.

## Managing the test catalogue

The head of development (or an academy admin) gets a **Manage tests** tile
under *Configuration*. It opens a list of every test your academy has set
up — name, category, unit, direction and cadence — with its **Active** or
**Inactive** state, and three actions per row:

- **Edit** — open the test in a flat form. You can change the name,
  category, value type, unit (from the list or a custom one), scale bounds,
  direction, cadence, and the active toggle, and edit the per-age-group
  green/amber target bands inline. **Save** commits; **Cancel** takes you
  back to the list (or to wherever you came from). Pass/fail tests have no
  target bands.
- **Activate / Deactivate** — an inactive test stays in the catalogue and
  keeps its history, but is hidden from the *Record measurements* picker so
  staff can't log new results against it.
- **Export to Excel** — downloads every recorded result for this test as a
  formatted `.xlsx` workbook (see below).
- **Archive** — soft-deletes the test into the recycle bin. Nothing is
  lost; an admin can restore it.

### Exporting a test's results

Each test row — and the test's edit view — carries an **Export to Excel**
action. It produces a formatted workbook for that one test: a header block
(test name, unit or *status*, date range and club) over a frozen, bold
column-header row, then one row per recorded result with the **player,
team, recorded date, value, age group and recorded-by**. Results are
grouped per player so a player's longitudinal series reads together.

For a **status** test the value column shows the recorded **level label**
(e.g. *On track*), and the cell is filled with that level's colour so the
sheet reads at a glance the same way the player-profile chip does. Numeric
tests show the number with its unit.

The workbook has a second **Trends** sheet that shows each player's results
**over time**: one row per player, one column per recorded date (in
chronological order), with the recorded value in each cell. A **line chart**
below the table plots every player as a series over the shared date axis, so
you can see who is improving, plateauing or declining at a glance. Numeric
and scale-score tests are charted; a **status** test lists each player's
recorded level per date for reference but has no chart (there is no numeric
axis to plot).

The export reuses the central export pipeline and is gated on the same
`measurements` *read* permission as the rest of the module — only staff who
may see a test's results may export them.

Creating a test still runs through the **+ New test** wizard, reachable
from the top of this list as well as from *Record measurements*. The same
catalogue is available over REST at
`/wp-json/talenttrack/v1/measurement-definitions` for integrations and the
SaaS front end.

## Browsing results — the Test results surface

The **Test results** tile (in the **Analysis** group of the dashboard)
opens a browser for reading every recorded result in one place, organised
per player. It answers "how is each player doing on this test, right now?"
without opening profiles one by one.

1. **Pick a test.** Until you choose one, the grid prompts you to. The
   picker lists every test in the catalogue, grouped by category.
2. **Optionally narrow** by **team**, **age group**, and a **date range**
   (from / to). The filters re-run the grid when you press *Show*.
3. **Read the grid.** One row per player who has a value for the test,
   showing their **latest value in the window**:
   - **Status tests** show the level's **colour chip and label** (e.g. a
     green *On track*), the same colours the player-profile chip uses.
   - **Numeric and scale tests** show the **value with its unit**, a small
     **trend arrow** (▲ improved, ▼ declined, ▬ unchanged) versus that
     player's previous result, and a **flag** — green *on target*, amber
     *below target*, red *well below target* — against their age-group
     band.

The grid is **sortable** (tap a column header on tablet and desktop) and
every **player name links to their profile**, arriving with a back-pill so
one click returns you to the browser. An **Export to Excel** button
downloads the current test (honouring the team and date filters) through
the same formatted workbook the *Manage tests* export produces.

Team-scoped staff (coaches who hold *read* on their own teams only) see
results for their teams only; academy-wide readers see everyone. The same
rows are available over REST at
`/wp-json/talenttrack/v1/measurement-results?definition_id=…` (filters:
`team_id`, `age_group`, `from`, `to`), gated on the same `measurements`
*read* permission, for integrations and the SaaS front end.

## Moving between the surfaces

**Tests & measurements** has four staff surfaces — *Manage tests* (set up
the catalogue), *Record measurements* (enter results), *Testing coverage*
(review who's due), and *Test results* (browse everyone's results) — and
the management surfaces cross-link so you don't have to return to the
dashboard:

- *Record measurements* shows a **Manage tests** link beside **+ New test**,
  so you can jump to editing a test's cadence or bands and come straight
  back.
- *Manage tests* shows **Record measurements** and **Testing coverage**
  links at the top of the list.
- *Testing coverage* shows a **Manage tests** link (only for staff who can
  edit the catalogue).

Each link carries a contextual back-pill on arrival, so the destination
offers a one-click route back to where you came from.
