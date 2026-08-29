---
title: Training plans
group: planning
summary: Build a reusable training from blocks, then run it against an activity.
audience: [user]
views: [training-plan, training-plans, training-run, training-coverage, training-photo]
module: TT\Modules\Training\TrainingModule
order: 20
---

# Training plans

A **training plan** is the content of one training: the blocks you run, in
order, with a duration each. It lives on its own rather than inside a single
date, so you can reuse it, adapt it for another team, or keep it as a club
template.

Open the **Training** tile in the **Planning & tactics** group.

## Plans and templates

Two kinds of record share the list:

- A **team plan** belongs to one team. It is the normal case: the training
 you are building for Tuesday.
- A **club template** belongs to no team. It is a starting shape — "standard
 MD-3, 75 minutes" — that any coach can copy and adapt.

Filter between them with the **Kind** control. Everything else on the list
behaves the way the other lists in TalentTrack do: search by name, sort by
column, and switch between **Active** and **Archived** with the status pills.

## What a plan holds

Open a plan to see:

- **The key numbers** — total duration, how many blocks, whether it is a team
 plan or a club template, and the theme it works on.
- **The time strip** — a proportional bar showing how the training splits
 across its blocks, colour-coded by block type. The same six colours are
 used everywhere training appears, so the shape of a training is
 recognisable at a glance.
- **The blocks** — each one in order with its type, duration, the exercise it
 draws on, the organisation, and the coaching points.
- **Times this plan was run** — every training this plan has actually been
 used for.

## Editing a plan never rewrites history

This is the part worth understanding, because it is what makes the training
record trustworthy.

When you attach a plan to a training, that **run** takes its own permanent
copy of the blocks exactly as they were that day. Afterwards you can rename
the plan, change a block's duration, delete a block, add a new one, or
archive the plan entirely — and the training that already happened still shows
what it actually contained.

The same applies one level down: a block points at a specific version of an
exercise. Editing the exercise in the library creates a new version and
leaves every plan that already used the old one untouched.

So you are free to keep improving a plan without worrying about damaging your
own history.

## Archiving

Archiving a plan takes it out of the active list. It does **not** touch the
trainings already run with it — a plan going away must never take a training
that happened with it. Switch the status filter to **Archived** to find it
again.

If a team is deleted, its plans are not deleted with it. They lose their team
and become club-wide, so a coach's work survives a season rollover.

## Making a plan

Press **New plan**. Four short questions, then a finished session:

1. **When** — the team and the date. The age group, how many days until the
 next match and where you are in the season are worked out for you.
2. **Theme** — what the session is about. Each option shows how many exercises
 your library can offer for it, so you are never sent down a path with
 nothing behind it.
3. **Shape** — how long, and how many players you expect. The number of
 players comes from this team's recent attendance rather than its squad
 list, because a sixteen-player squad rarely puts sixteen on the pitch.
 Change it whenever you know better — a school trip is not in the data.
4. **Proposal** — the draft. Go back and change anything; nothing is saved
 until you say so.
5. **Review** — which players' open goals this session works on, then save.

### What the generator will and will not do

- **Every exercise comes from your library.** Nothing is invented.
- **Nothing goes above the age group's intensity ceiling.** A U13 session
 never proposes an exercise harder than U13 allows.
- **The same answers always give the same session.** It is not shuffling.
- **A drill never appears twice in one session.**
- **If your library has nothing suitable for part of the session, that block
 is left blank and says so** rather than being padded out with something
 that does not fit.

### Which age groups the generator drafts for

Drafting needs an **age profile** — the maximum training length and intensity
ceiling for that age group. Without one there is nothing safe to plan inside, so
the generator stops rather than guessing. Two different reasons it can stop, and
they mean different things:

- **The youngest groups have no load model, on purpose.** Training load is not
  planned in numbers at that age. The generator will never draft for them, and
  it says so rather than implying a setting is missing. Build the training
  yourself; the plan holds it like any other.
- **An age group above that range has no profile yet.** That one is fixable.
  Whoever holds the VCT configuration permission — normally the head of
  development — adds it under **VCT configuration → Age profiles**, and drafting
  works for those teams from then on.

Out of the box the modelled range is U10–U14. Adding U15 and up is a decision
about your own academy's load ceilings, which is why the numbers are yours to
set rather than shipped.

### Why some sessions suit your players better than others

The generator prefers exercises that train a principle your players actually
have open goals on — a drill six of them need beats one nobody is working on.

That needs two things linked: exercises tagged with the principles they train
(the library's **trains which principles** field, filled in automatically for
exercises that already had a theme), and goals that name a principle. Until a
squad's goals name principles, the review step says so plainly rather than
showing a confident zero.

## Changing a plan

The generator gives you a draft; your judgement turns it into a session.
Open a plan and press **Edit blocks**.

### Reordering

Every block has **↑** and **↓** buttons. They are the main way to reorder, on
every screen size and by keyboard — tab to a button and press Enter. On a wide
screen you can also drag a block by the handle on its right, but you never
have to.

Nothing is written until you press **Save plan**, so rearranging is free until
you commit to it.

### Changing how long a block runs

The **−** and **+** buttons change a block in five-minute steps. The time
strip and the total above it update as you go, so you can see the shape of the
session change rather than doing the arithmetic yourself.

The total is what the plan ends up as. There is no target it has to hit —
if you have the pitch for an hour, build an hour.

### Swapping an exercise

**Swap exercise** opens your library. On a phone it slides up from the bottom
so it sits under your thumb; on a desktop it appears bottom-right.

The list is **sorted by how many of this team's open player goals each
exercise would serve**, and each row says its number. An exercise that serves
six players your squad is actually working on sits above one that serves
nobody, and you can see why.

Search narrows the list by name or code.

### Adding, removing, and coaching points

**Add a block** puts an empty block at the end — choose its kind, then swap an
exercise into it. A block with no exercise is fine: a team talk or a
walk-through has no drill behind it.

Each block has a **coaching points** box for what you want to say on the
night.

### Player goals this plan touches

Beside the blocks (below them on a phone) is the panel that makes the whole
module worth using: **which players this plan actually works on, by name**.

Players with an open goal on a principle the plan trains are listed
individually. Underneath, the players with an open goal the plan *misses* are
named too — because that is the list you can do something about before
Tuesday.

The panel updates every time you save, so you can swap a block and see who it
gained or lost.

If it says there is nothing to compare against, that means no player in the
squad has an open goal tied to a principle yet. See *Why some sessions suit
your players better than others* above.

### Reusing a plan

Two buttons under the block list:

- **Save as club template** makes a club-wide copy with no team on it — the
 session that worked becomes a starting shape anyone can build from.
- **Copy to a new plan** makes an independent copy for the same team, which is
 the quickest route to next week's session.

Both copy the **saved** plan, so save your changes first — the buttons will
tell you if you have not.

A copy is genuinely independent: editing it later never changes the plan you
copied it from.

## Running a plan

A plan becomes a training when you attach it to one.

### Attaching

Open the training in the calendar and press **Run this training**. Pick the
plan and press **Attach plan**.

The plan is **copied onto the training as it is at that moment**. Everything
that follows reads that copy, so editing the plan afterwards — even the same
evening — never changes what the training recorded.

If the training already has a plan the button says **Continue this training**
instead, and takes you straight to it. Attaching twice is not an error and
never replaces the first copy: a training that already happened keeps its own
record.

### The sideline view

This is the screen you hold on the pitch, so it is built differently from the
rest of TalentTrack: dark, one block at a time, big controls at the bottom
where your thumb already is.

- **Start the training** when you begin. The first block opens with a timer.
- The timer counts **up**, against the block's planned length. Nothing moves
 on by itself — you decide when a block is done.
- **Running over is fine.** The screen turns amber and tells you how far over
 you are and what the block will be recorded as if you finish now. It does
 not nag and it does not stop you.
- **Finish this block** records how long it actually ran and opens the next.
- **Skip this block** records that it did not happen. The block stays in the
 plan; only this training records the skip.
- **‹ and ›** move between blocks without recording anything, for when you
 want to look ahead.
- **Finish the training** closes the session. Blocks you never ran are
 recorded as skipped.

At the end you get the totals: minutes actually trained against minutes
planned, how many blocks ran, how many were skipped.

**This screen needs a connection.** If you lose signal mid-session it tells
you the write failed rather than pretending it saved. Offline is coming
separately.

### The paper version

Press **Print** on a plan for an A4 sheet you can carry: every block with its
start time, its length, its organisation and its coaching points, on one page
for a normal session.

If any player on the team has a growth-spurt ceiling below the hardest block
in the plan, the sheet says so by name — the person holding the paper is the
person who has to act on it.

The sheet is the plan, not the record. What you actually run is recorded on
the training.

### When the signal drops

Pitches are where signal is worst, so the sideline view keeps working without
it. Tap a block done, skip one, or write an observation with no bars, and it is
stored on the phone instead of being lost. A line at the top of the screen says
how many changes are waiting — *"2 wijzigingen wachten op bereik"* — and they
send themselves the moment you have a connection again.

It survives locking the phone, switching apps and reloading the page. Nothing
is lost by walking inside.

Two things worth knowing:

- **Opening the page still needs signal.** What is protected is a session
 already underway; you cannot start one from nothing with no connection.
- **Nothing is recorded twice.** If a change is sent and the reply never comes
 back, the phone tries again — and the second attempt lands on the same
 record rather than creating a duplicate. That matters because these numbers
 become each player's training minutes.

If a change still cannot be saved after you reconnect — because you were away
so long that your login expired — it stays queued rather than being thrown
away. Reload the page and it will go.

## What a player has actually been taught

This is the part the rest of the module exists for.

### On the player's file

Open a player and choose the **Training** tab.

- **The headline numbers** — minutes trained, how many of the club's
 principles have been touched out of the total, and when they last trained.
- **Minutes per principle** — every principle in your methodology, with what
 this player has spent on it. **The principles they have never trained are
 listed too, at the top, marked.** That is deliberate: an empty row is the
 most useful thing on the page, and a list that quietly dropped them would
 look complete while hiding what you opened the tab to find.
- **Recent observations** — what coaches noted about them during trainings.

The minutes come from trainings the player actually attended. Present and late
count; excused, absent and injured do not. A skipped block contributes nothing,
and a block that ran twenty-seven minutes contributes twenty-seven rather than
the twenty-two someone typed into the plan.

If a player guested for another team, those minutes are on their own file too.

### Who can see it

Coaches see it for players on their own teams. The head of development and
academy admins see it for everyone. **A parent sees it for their own child
only** — and a player can switch it off for their parent entirely, under
*My settings → what your parent can see*, alongside evaluations, goals,
measurements and their PDP.

### Academy-wide: the coverage matrix

Head of development and academy admins get a **Coverage** action on the
Training page: every principle down the side, every team across the top, and
how many trainings that team has spent on each.

Only "never" is marked. Four shades of nearly-fine would bury the one thing
worth acting on.

### Recording an observation

During a training, the sideline view lists everyone who is there with a scale
under their name and a box for a note.

- You do not have to score anyone. A note on its own is a complete
 observation, and on a wet Tuesday it is the usual one.
- Tap a number again to clear it.
- The scale is your academy's own — whatever range and step you have
 configured for evaluations.
- A score outside that range is refused rather than rounded into it.

Each observation appears on the player's **Journey** timeline straight away,
dated to the training rather than to when you typed it up.

### When the numbers update

Immediately when you finish a training, for the players who were there. And
fully every night, which is what picks up a plan edited after the fact, an
exercise re-tagged with a different principle, or attendance corrected the
next morning.

## Photographing a plan you wrote out

If your academy has this switched on, **From a photo** on the training plans
page turns a hand-written sheet into a draft. Photograph the sheet, check what
was read, and press **Concept aanmaken**. Nothing is saved until you do — close
the page at the checking step and there is no plan and no photo anywhere.

The checking step is the point of the screen, so it shows you how sure it is
about each line:

| | What it means |
| --- | --- |
| Green | Matched an exercise in your library with confidence. |
| Amber | Close to more than one exercise. Worth a look. |
| Red | Not recognised at all. |

An unrecognised line stays as a loose block if you leave it — and **then it does
not count towards what your players have been taught**, because that count is
built from matched exercises. Linking it, or adding it to the library, is what
makes the minutes land on the players' records.

You can change any name or duration before creating the draft, and remove a line
that was never really there.

### Where the photo goes

The screen tells you, next to the shutter, before you take it. Your academy's
administrator decides where photographs are sent to be read, and that choice is
written into the setup rather than assumed — until it has been made, this screen
refuses to open and says so. Player names are not copied into notes.

If you have no signal, nothing is sent and the screen says so.

### Out of range

The photo is kept on your phone and read as soon as you are back in range —
you do not have to retake it. It waits through a reload and a browser restart,
and the screen tells you how many are waiting. So does the training plans page,
if you have walked away from the camera in the meantime.

When the connection returns, the photo is read and you land on the same
checking step as always. **No plan is ever created without you** — the promise
that nothing is saved until you press **Concept aanmaken** holds for a photo
that waited exactly as it does for one taken with full signal.

A photo that waits stays on the phone and nowhere else. It is deleted as soon
as it has been read and checked, and **after seven days it is deleted whether
or not it was read** — the screen tells you when that has happened, so you know
to photograph the sheet again rather than discovering the training missing weeks
later.

Opening the page still needs signal. It is the photograph that can wait, not
the app.

## What is not here yet

Still to come:

- photographing a whiteboard rather than a sheet of paper, which needs a
 different kind of reading
