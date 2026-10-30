---
title: Product vision
group: developer
summary: Where TalentTrack is going beyond the roadmap — the product an academy owner would want that does not exist yet, as six bets with a first step each.
audience: [dev]
order: 18
---

# Product vision

Status: **proposed** by the product owner on 2026-10-30, written from the
academy owner's chair. It becomes the product's direction when the owner
adopts it; the bets it starts are then scheduled through the
[roadmap](roadmap.md) like any other work.

## The problem nobody's software solves

Every academy has software. All of it records. A coach rates a player, a
physio logs an ankle, a parent signs a form. The data piles up, and the
decisions that matter most are still made the way they were thirty years
ago: in a room at the end of the season, on memory, by the people who
shouted loudest about the last six weeks.

Three things go wrong as a result, and every academy owner knows them:

- **We release the wrong children.** A boy born in October, small at
  thirteen, looks behind a boy born in January who has already grown. We
  call it talent. It is a birth date and a growth spurt. Some of the players
  we let go would have been the best we ever had.
- **We cannot tell whether our coaches agree.** One coach's 7 is another's
  5. A player's file reads like progress or decline depending on who held
  the clipboard that month.
- **We do not learn.** Nobody checks, three years later, what became of the
  players we released or kept. The same mistakes repeat every season,
  because the academy has no memory of its own decisions.

The software we use makes recording easier. None of it makes the academy
wiser.

## The vision

**TalentTrack becomes the academy's memory and its conscience: it
remembers every player, makes every judgement fair, and learns from every
decision the academy makes.**

The player stays at the centre, as `CLAUDE.md` §1 demands. What changes is
what the system does with what it holds. It stops being a filing cabinet
and becomes a colleague that notices: this judgement is skewed by birth
date, these two coaches disagree, this player is drifting away from the
game, this decision looks like one we got wrong in 2026.

## Six bets

Each bet is something no academy product does today. Each has a first step
that builds on what already exists, so the vision is reached by shipping,
not by a rewrite.

### 1. Fair judgement: birth date and growth built into every rating

The relative age effect and the timing of the growth spurt distort almost
every judgement made about a player between 11 and 16. TalentTrack shows
every rating beside the player's birth quarter and maturity estimate (from
height, sitting height and the parents' heights, a standard method),
flags a release candidate who is young for the cohort or late to mature,
and suggests training groups by maturity as well as by age.

*Why it does not exist:* the research is old and well known; the tools
coaches use never turned it into a number on the screen where the decision
is made.

*First step:* store dated height measurements per player (the journey
already holds dated entries) and show the birth quarter on the evaluation
and the release decision.

### 2. Calibrated coaches

When five coaches rate the same players, TalentTrack can see how far apart
they are. It shows each coach, privately, where their ratings sit against
the staff: harsher than average, generous to tall players, never using the
bottom of the scale. A head of development sees which categories the staff
cannot agree on and where a calibration session would help.

*Why it does not exist:* rating forms treat every number as truth. Nobody
measures the measurer.

*First step:* a calibration report on existing evaluations, for the head of
development only; no change for coaches until the owner decides how it is
shown to them.

### 3. The academy remembers its decisions

Every signing, promotion, release and graduation is recorded with its
reasoning and the evidence at the time. A year later, and three years
later, TalentTrack asks: where is this player now? The academy sees its own
track record, including the players it let go who went on to play at a
higher level, and the pattern behind its misses.

*Why it does not exist:* academies keep player records, not decision
records, and nobody follows a released child.

*First step:* make release and graduation journey events carry a short
reasoning and the evidence snapshot (the player report already assembles
it); a yearly "where are they now" review for the head of development.

### 4. The coach speaks, the record writes itself

A coach on the touchline does not fill in forms. They talk into the phone
for fifteen seconds: "Daan, good scanning before receiving, still drifts
inside when we lose the ball." TalentTrack turns that into an observation
on Daan's file, linked to the methodology principle it is about and to his
development goal, ready for the coach to confirm with one tap.

*Why it does not exist:* academy software is built for the office. The
observations worth keeping happen on grass, in the moment, and are lost by
the time anyone reaches a laptop.

*First step:* voice notes on the existing mobile surfaces, transcribed and
suggested as an observation the coach confirms; nothing saved without that
tap.

### 5. Every goal has a moment on video

A development goal says "improve first touch under pressure". The player
should see what that means: three clips from Saturday's match where it went
well and one where it did not, attached to the goal, visible in the PDP
talk. Match footage already exists at most academies. TalentTrack links the
moments to the player, the principle and the goal.

*Why it does not exist:* video tools and development tools live apart.
Clips end up in a chat group; goals end up in a form.

*First step:* attach a clip link with a timestamp to a goal, an evaluation
or a match-analysis note, and show it in the PDP conversation.

### 6. The whole child, and the dignified exit

A player is a child with school, sleep, growth pains and a family. A short
check-in the player fills in themselves (energy, mood, school load) shows a
coach who is struggling before it shows on the pitch, and flags the
pattern that comes before a child leaves the game. And when the academy
does release a player, the player leaves with their story: the
development record, the coaches' words and the clips, as a document that
belongs to them and their family.

*Why it does not exist:* academies are built around the players they keep.
The ones they release disappear from the system the day they leave.

*First step:* a player-owned export of the development record on release,
built on the player report; a check-in for the player persona once the
owner decides who may read it.

## The guardrails

These are minors. Every bet above sits inside these lines, and a bet that
cannot is dropped.

- **The coach decides.** The system notices, suggests and remembers. It
  never signs, releases or ranks a child on its own.
- **Every number explains itself.** A flag says why it was raised, from
  which data, and how sure it is.
- **No public ranking of children.** Comparisons stay inside the staff
  roles that need them.
- **Sensitive data stays sensitive.** Wellbeing, growth and medical data
  are permission-gated and audit-logged, as injuries are today.
- **The player and the family see their own story** in language made for
  them.

## How the bets reach the roadmap

The owner adopts the vision and picks which bets go first. Each chosen bet
becomes an epic with its first step as the first child, and enters the
roadmap's Next column. The rest stay here until their turn. This file is
reviewed once per season.
