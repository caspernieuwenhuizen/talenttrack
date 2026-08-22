<!-- audience: user, admin -->

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
- **Intensity** — 1 (recovery) through 5 (maximum), for the exercises that
  carry a band.
- **Status** — active or archived.

## Adding your own

Open **Add exercise**. Only the name is required; everything else helps the
generator pick well later, so fill in what you know:

| Field | What it does |
| --- | --- |
| Category | Groups the drill and tells the generator which slot it fits. |
| Typical duration | The default length when it goes into a plan. |
| Smallest / largest group | Filters the drill out when the squad is the wrong size. |
| Intensity | 1–5. Used to keep a session inside the age-safe ceiling. |
| Description | Organisation, rules, scoring. |
| Diagram image URL | An image of the setup. |

### Who sees what you add

**A new exercise belongs to your team by default, and you can use it in your
plans immediately.** Nothing waits on approval.

Whether the *rest of the club* gets it is a separate decision, made by the head
of development. Your team keeps using it either way.

You can also mark a drill **Only me** if it is a work in progress.

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
