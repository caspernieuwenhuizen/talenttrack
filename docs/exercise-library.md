---
title: Exercise library
group: performance
summary: Every drill the academy can build a training from, in one place.
audience: [user, admin]
views: [exercises, exercises-import]
module: TT\Modules\Exercises\ExercisesModule
order: 95
---

# Exercise library

Every drill the club can build a training from lives in one place. Open the
**Training** tile and choose **Exercises**.

Until recently TalentTrack held two catalogues that could not see each other —
the general exercise library and VCT's own conditioning exercises. They are now
one. A VCT exercise carries a **From VCT** label, and the ones that ship with
TalentTrack are marked **Built in**.

## Finding a drill

Search by name, code or description, and narrow with the filters:

- **Category** — warm-up, rondo, positional play, small-sided game, and so on.
- **Visible to** — whole club, one team, or only you.
- **Intensity** — 1 through 7, for the exercises that carry a band.
- **Status** — active or archived.

## Adding your own

Open **Add exercise**. Only the name is required; everything else helps the
generator pick well later, so fill in what you know:

| Field | What it does |
| --- | --- |
| Category | Groups the drill and tells the generator which slot it fits. |
| Typical duration | The default length when it goes into a plan. |
| Smallest / largest group | Filters the drill out when the squad is the wrong size. |
| Intensity | 1–7. Used to keep a training inside the age-safe ceiling. |
| Description | Organisation, rules, scoring. |
| Diagram image URL | An image of the setup. |

### What the intensity bands mean

The scale runs **1 to 7**, and it is worth rating consistently: this number is
what the age-safe ceiling is compared against, so a drill rated too low can slip
past a warning it should have triggered.

| Band | What it describes |
| --- | --- |
| 1–2 | Recovery and technique. Little or no physical load. |
| 3–4 | Steady work. A rondo or a possession game at a comfortable tempo. |
| 5 | A normal training block — the intensity most of a session sits at. |
| 6 | Genuinely demanding. Pressing games, repeated sprints, small-sided at full tempo. |
| 7 | As hard as any age group in the academy should ever go. |

There is no band above 7. The highest ceiling any age profile carries is 7
(U13 and U14; U10 stops at 3), so a higher number would describe a session no
group is permitted to do.

### Who sees what you add

**A new exercise belongs to your team by default, and you can use it in your
plans immediately.** Nothing waits on approval.

Whether the *rest of the club* gets it is a separate decision, made by the head
of development. Your team keeps using it either way.

You can also mark a drill **Only me** if it is a work in progress.

### Bringing a lot of drills at once

If the club already has its drills in a spreadsheet, you do not have to retype
them. Above **Add exercise** there is a link to **Import exercises from CSV**.

Save the spreadsheet as a `.csv` file with a header row naming the columns, then
upload it. Nothing is saved yet: you get a check screen first, listing every row
that has a problem and why. Only when you press **Import exercises** is anything
written.

A row that fails does not stop the rest. Every good row is saved, and the failed
rows come back as a file you can correct and upload again — the reason is added
as an extra column, so you can fix them in the spreadsheet and re-upload.

The columns the file may contain are listed on the import screen itself under
**Accepted columns**. Three things are worth knowing before you start:

- **Fill in `principle_codes` wherever you can.** This is the column that
  decides whether the drill is useful. A drill with no principles can still be
  chosen for a session, but the planner can never *prefer* it — so a large
  library tagged with nothing behaves like an empty one. Separate several codes
  with a semicolon.
- **A number outside its range fails its row.** It is not rounded into range.
  If a column was filled in on the wrong scale, you are told, rather than every
  row being quietly rewritten.
- **Drills arrive belonging to your team**, exactly as they do when you add one
  by hand. Publishing to the whole club is still the head of development's
  decision.

If your spreadsheet has an **organisation** column it is added to the end of the
description, matching the single field the form offers. There is no place for
per-drill **coaching points**: those belong to a training plan's block, because
the same drill is coached differently depending on what the session is for.

## Classifying the library

An exercise with no principles is **never suggested by the generator**, and time
spent on it **does not count towards what your players have been taught**. Both
consequences are invisible from the library list, which is why an unclassified
library tends to stay that way.

When exercises are waiting, the library says how many and offers **Classify
them**. That screen is built around one action: **tick several exercises, choose
the principles they train, apply once.** A classified exercise carries around
eight principles, so doing this one at a time is hundreds of separate saves —
selecting a whole category and applying in one go is the difference between an
afternoon and a fortnight.

The screen is grouped by category, because exercises of a kind usually train the
same principles. **Select all shown** takes a whole group at once.

| Choice | What it does |
| --- | --- |
| Add to what they already have | Leaves existing principles alone. The safe default for bulk. |
| Replace what they have | Clears and sets. Only for the methodology you are working in — another methodology's principles are never touched. |
| None apply | Marks the exercise as looked at, with no principles. |

**"None apply" is the one that lets you finish.** Warm-ups, cool-downs and
conditioning drills mostly should not carry a tactical principle — a warm-up
does not train building up from the goalkeeper. Marking them keeps them out of
the list, so the count actually reaches zero instead of showing the same
warm-ups forever.

The screen names the methodology it is writing to. If your academy has more than
one, principles are tagged against the active one.

## Making an exercise club-wide

If you are the head of development or an academy admin, the library shows an
**Added by teams** panel listing the drills coaches have written for their own
teams, with how many plans already use each one. Press **Make club-wide** on
the ones that fit the academy's methodology.

Once club-wide, the exercise appears for every team and enters the pool the
training generator draws from.

## Editing

Editing an exercise creates a **new version** rather than overwriting the old
one. Plans and trainings that already used the previous version keep showing
it exactly as it was. That is what lets you keep improving a drill without
rewriting your own history.

Exercises marked **Built in** or **From VCT** cannot be edited in place. Copy
one to make a version of your own.

## Drawing a scene

A **scene** is a small animated diagram of the drill: players, opponents, the
ball, cones and goals on a pitch, with the movements you want them to make.
Open an exercise and press **Draw a scene**.

The one gesture the editor is built around is this: **drag a marker on the
pitch and it records where that marker is at the moment the playhead is on.**
Scrub to two seconds, move the left-back forward, and the left-back now runs
forward over those two seconds. Nothing else is needed to make a scene move.

The rest of the surface is there for the times dragging is not what you want:

- **Add a marker** — pick a player, opponent, keeper, ball, cone or goal on
 the left, then tap the pitch. The tool stays selected, so you can place a
 whole team without going back to the palette.
- **Lines** — pick a line type (pass, dribble, run, shot, press), then tap two
 markers. The line is drawn between where those two markers *are* at that
 moment, so it stays right when you reposition either of them later.
- **The timeline** — one row per marker, one diamond per recorded position.
 Tap a diamond to travel to that moment; drag it sideways to change when it
 happens.
- **Selected marker** — the shirt number, the exact position, and buttons to
 duplicate or remove.
- **Undo** takes back the last change, up to forty of them. Ctrl+Z works too.

Arrow keys move the selected marker a step at a time, so a scene can be built
without a mouse. Nothing is saved until you press **Save**.

**Pitch** and **Length** decide what the scene is drawn on and how long it
runs. A rondo drawn on a full pitch is six players in a corner, so pick the
half pitch or the grid square when that is what you actually set up.

### Where a scene shows up

Once saved, the same scene appears in three places, drawn by the same code, so
it always looks the same:

- on the **exercise page**, with play controls;
- in the **sideline view** while you are running the training;
- on the **printed A4 sheet** — as a still picture, since paper cannot animate.
 The still is the scene's final frame.

An exercise can hold more than one scene, which is how you show a drill that
has phases. The first one you draw is the one the three surfaces show; press
**Add a scene** for the next.

Drawing works best on a tablet or a desktop. On a phone you can watch a scene
and move a marker, but the timeline wants more room than a phone has.

## Who can do what

| | View | Add and edit | Make club-wide |
| --- | --- | --- | --- |
| Coach / assistant coach | yes | yes | no |
| Head of development | yes | yes | yes |
| Academy admin | yes | yes | yes |

The right to decide what the whole club trains from follows the right to edit
the academy's methodology, because the club-wide library is part of it.

## A note for administrators

Before this release the VCT capability `tt_vct_admin_library` covered three
things: the exercise catalogue, the VCT age profiles, and the macro-blocks.
Now that there is one library, the **library** moved to the exercises
capability that coaches already hold, and the rest kept a
head-of-development-only capability under a clearer name,
`tt_vct_admin_config`.

Nobody gained or lost access in the move. In particular the **age profiles**,
which set the age-safe intensity ceilings for U10–U14 players, remain
head-of-development and academy-admin only.
