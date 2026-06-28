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
   scale score, or pass/fail).
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
- **Archive** — soft-deletes the test into the recycle bin. Nothing is
  lost; an admin can restore it.

Creating a test still runs through the **+ New test** wizard, reachable
from the top of this list as well as from *Record measurements*. The same
catalogue is available over REST at
`/wp-json/talenttrack/v1/measurement-definitions` for integrations and the
SaaS front end.
