<!-- audience: user -->

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

### Why some sessions suit your players better than others

The generator prefers exercises that train a principle your players actually
have open goals on — a drill six of them need beats one nobody is working on.

That needs two things linked: exercises tagged with the principles they train
(the library's **trains which principles** field, filled in automatically for
exercises that already had a theme), and goals that name a principle. Until a
squad's goals name principles, the review step says so plainly rather than
showing a confident zero.

## What is not here yet

Still to come, in order:

- editing a generated plan block by block
- attaching a plan to a training, the sideline view and the A4 print
- the per-player training history that all of it feeds
