---
title: Tournaments
group: planning
summary: 'Multi-game days: shared squad, shared playing-time goals, one planner.'
audience: [user]
views: [tournaments, tournament-match]
module: TT\Modules\Tournaments\TournamentsModule
order: 30
---

# Tournaments

A **tournament** in TalentTrack is a container for a set of matches you play in a single day or weekend, with shared squad and shared playing-time goals. The tournament planner is built to answer one question the coach asks every weekend:

> *Across these matches, who plays which position when, how does it compare to everyone else, and who haven't I started yet?*

## Who can see this

Head coaches, assistant coaches and team managers see and run the tournaments of the teams they are assigned to — the whole planner, including creating one. The Head of Development and Academy Admins see every tournament in the academy.

A tournament's squad can be drawn from more than one team, and access follows the whole set, not just the anchor team. So:

- You can open and plan a tournament when **any** of its teams is one of yours.
- You can delete one only when **all** of them are. Deleting takes the fixture away from every squad in it, so if a tournament includes a team you don't manage, TalentTrack refuses the delete and tells you why. The Head of Development or an Academy Admin can still delete it.

Players and parents do not see the tournament planner.

## Creating a tournament

1. Open the **Tournaments** tile and tap **+ New tournament**.
2. The wizard walks you through five steps:
 - **Basics** — name, anchor team, start date, optional end date. The tournament's format (7v7 / 9v9 / 11v11) is derived automatically from the anchor team's age group; no manual Format dropdown.
 - **Formation** — pick a default formation (e.g. `1-3-4-3`) from a radio-card grid. Each card shows a tiny dot glyph of the formation shape. You can override per match later.
 - **Squad** — tick the players in the squad from the anchor team's roster, and for each one tap chips for the **specific positions** they can play: GK · CB · LB · RB · DM · CM · AM · LW · RW · ST. Defaults are seeded from each player's preferred positions. Trial players appear in the list with a `Trial` badge but are unchecked by default — tap their row to add them. A live count above the list reads "X in squad · Y not picked".
 - **Matches** — each match is its own card with a sequence circle and a live headline ("vs Den Helder JO13", "Final", "New match — fill in opponent below"). Fields: label, opponent, level, formation override, duration, substitution windows. Subs use a chip editor: type a minute and press Enter or comma to add a chip; Backspace from an empty input pops the last chip; click × to remove. The hint "Values must be 1–N" live-updates as you change the duration. Tap **+ Add another match** to append a blank card; **Remove** drops the card and re-numbers the rest.
 - **Review** — one card per upstream step with an **Edit** link in the top-right corner that jumps the wizard back to that step (preserving everything you've entered). Then tap **Create tournament**.
3. You land on the tournament detail page.

### Adding a match after creation

The detail page carries a **+ Add match** button next to **Edit**. It opens a standalone form (the same field grid + chip editor as the wizard's matches step) with one extra select: **Position in sequence**. Pick "Insert at end" or any "Insert before Match N" option to slot the new match into the running order; existing matches shift their `sequence` accordingly.

## Substitution windows — what they mean

The number of minutes after kickoff at which a swap happens. They determine how many **periods** the match has: `N windows → N+1 periods`.

- A 20-min match with `[10]` → two periods of 10 minutes each.
- A 60-min match with `[20, 40]` → three periods of 20 minutes each.
- A 30-min match with `[]` (empty) → one period; no subs allowed mid-match.

## The planner detail view

The detail view of a tournament shows:

- **Facts strip** — team, dates, default formation, squad size, match count.
- **Matches** — one card per match. Tap **Open planner grid** to expand the per-match lineup grid.
- **Minutes ticker** — sticky bottom strip on mobile, right sidebar on desktop. Always visible. One card per squad player showing:
 - The minutes, played and planned kept apart: *0 played + 40 planned / 35 min*. Once a player has been on the pitch the first number moves; a player with nothing still planned reads simply *40 played / 35 min*. Before any match kicks off the whole squad reads **0 played**, which is the point — the planned column is the plan, not the record.
 - A green/amber/red bar for the two together, played as the solid part and planned faded behind it. The colour follows played **plus** planned against the equal-share target, so the ticker works as a planner before kick-off instead of showing the entire squad red.
 - ⚡ start count.
 - 🏆 full-match count.
 - Sort dropdown: **Default / Fewest minutes / Fewest starts / No full matches** so under-served players bubble to the front.

## Per-match planner grid

The grid lays out one row per formation slot (`GK`, `RB`, `CB`, …), one column per period.

- Tap a player chip — it highlights yellow.
- Tap another chip or empty cell — the two slots swap.
- Tap the same chip again to deselect.

The bench row at the bottom collects players not on the pitch in each period. Drag a player from the bench to a slot the same way: tap chip, tap target.

**Eligibility warnings**: a player placed in a slot they're not eligible for shows an amber dot. It's a warning, not a block — coach judgment wins.

## Auto-balance

The **Auto-balance** button on each match card runs a greedy assignment that fills the grid based on:

- Eligibility (only players whose position types match the slot can fill it).
- Equal-share fairness (the player furthest from their target minutes gets first pick).
- Starts distribution (for period 0, players with the fewest starts get priority).
- No back-to-back bench (a player benched in the previous period drops in the ranking).

Auto-balance is a **starting point**, not a constraint solver. Drag, swap, and tweak after.

### Turning Auto-balance off

Auto-balance is a per-academy toggle (**Tournament auto-balance**) on the
Modules management page, on by default. Switch it off and the Auto-balance
button disappears from every match card; the per-match planner grid and
manual click-to-swap planning keep working exactly as before. Some Heads of
Development prefer to plan minutes entirely by hand — this lets them remove
the shortcut without losing the planner.

## Opponent level

Each match has an opponent level — by default **weaker / equal / stronger / much stronger**. The pill on the match card is colour-coded green → grey → amber → red so you see at a glance which matches need your strongest lineup. The pill takes its colour from the level itself, so recolouring a level under Configuration recolours the pill; its text switches between dark and light to stay readable on whatever colour you pick.

A match can only carry a level the vocabulary actually holds. An import or an integration sending something else is refused with a message naming the levels that are allowed, rather than storing a word the planner would then show as-is. Leaving the level empty is still fine — that reads as "not recorded".

## Values the planner refuses

Two things a tournament can be sent are checked on the way in rather than
dropped, because the planner cannot work with them and a coach would only find
out from a grid that looked wrong.

**Positions.** A squad entry can hold `GK · CB · LB · RB · DM · CM · AM · LW ·
RW · ST`. Anything else — `DF`, `MF`, `FW`, a typo — is refused with a message
naming the code and the codes that are accepted, and nothing is stored. It used
to be removed in silence: a squad sent as `GK / DF / MF` was stored as `GK`
alone, and Auto-balance then filled the keeper slot and left everybody else on
the bench with no minutes. The older `DEF` / `MID` / `FWD` still work and read
as `CB` / `CM` / `ST`.

**Formations.** A tournament's default formation and a fixture's own formation
have to be one the academy actually has under Configuration → Tournament
formations. An unknown one is refused with the list of the ones that exist.
Leaving it blank is still fine on a fixture — that means "use the tournament's".

If your age group plays a shape the seeded list does not carry, add it under
Configuration → Tournament formations with its slot labels; then it is accepted
everywhere, planner included.

The level shows as its translated label everywhere you meet it — the pill on the match card, the level dropdown on the add-match form, the wizard's match step and the wizard's review summary. Rename a level under Configuration → Opponent levels and the new label follows to all four; what's stored on the match doesn't change, so existing matches keep their level.

The auto-balancer **does not** auto-weight by opponent level. That's coach judgment; the tool shows the data and you apply the judgment via manual swaps.

## The tournament day on the team's calendar

Creating a tournament puts **the day itself** on the team's activity list right
away, as a planned tournament activity carrying the tournament's name and its
start date. Rename the tournament or move it to another date and the calendar
entry follows.

That is what lets the team manager sort transport and kit, and the assistant
coach and the parents see the day coming, while the planner is still being
filled in. It used to appear only when somebody tapped **Kick off** on the
morning itself — so a tournament planned three weeks ahead was invisible to
everybody who works from the activity list, and there was nothing to register
availability against.

**Attendance is registered once, for the day.** A tournament day is several
matches and the register belongs to the day, not to each fixture.

A tournament created before this behaviour landed gets its calendar entry the
next time you edit it or add a match to it — there is nothing to re-run.

## Kicking off and completing a match

- **Kick off** — promotes the planned match to a real activity of its own,
  alongside the day. The match shows up on the player journey, on the team's
  activity list, and on the existing match-day team sheet exporter. The day
  entry is reused, never duplicated: one day, however many fixtures. The
  fixture needs an activity of its own because that is where its score and its
  minutes are recorded, while the day is a read-only roll-up of what its
  fixtures hold.
- **Complete match** — opens the completion step (below), then sets the match's
  completion timestamp and syncs the lineup to **attendance**: every player who
  started is marked `start` with their period-0 position, benched players are
  marked `bench`, and each one carries the minutes you confirmed. Played minutes
  flip from "expected" to "played" in the ticker.

You can **Complete** a match without explicit Kick off — the system will auto-kick-off first, so the common "the match just finished" flow is a single button tap.

### The completion step

Tapping **Complete match** no longer commits straight away. A sheet comes up
showing every squad member with the minutes the rotation plan gives them,
already filled in. Confirm them, or correct the ones that are wrong — the plan
is a plan, and a keeper who stayed on for the whole second period did not read
it. Then tap **Complete match** in the sheet.

It is pre-filled rather than blank on purpose: a coach standing next to a pitch
will check fifteen numbers and will not type them.

**A lineup that does not field a full team is called out there.** If any period
has fewer players on the pitch than the formation has positions, the sheet says
so at the top — "Period 1: 1 of 7 positions filled" — and warns that completing
anyway records those minutes as what was played. You can still go ahead; you
just cannot do it without being told.

This is the case that made it necessary. A U7 fixture was completed with one
goalkeeper per period and everybody else benched — a grid Auto-balance had
produced from a formation the squad could not fill — and it went into the record
as two keepers on ten minutes and thirteen children on nil, when they had all
played about twelve. Minutes were also never written at all, so every minutes
surface read the whole squad as nil.

Over the API, `POST .../complete` refuses such a fixture with `409
lineup_incomplete`, naming the short periods, unless `force=1` is passed.
Nothing is written on that path: no register, no completion timestamp, no
activity.

## Editing a completed match

By default a completed match's lineup is locked. The system blocks PATCHes to its assignments unless you pass `force=1`. If you spot a mistake after marking complete, an admin can re-open the match through the REST API; there is no screen for it.

## Recording the result of each fixture

Every fixture in the match programme carries two boxes: **Ours** and
**Theirs**. Type the goals in and they save as you move off the box.

**A tournament has no single scoreline.** A tournament day is several matches,
and one score cannot describe it — which is why a tournament does not get the
Result card a league match has, and gets no score boxes in the minutes grid.
The result belongs to the fixture, so that is where it is recorded.

**Leaving a box empty records no result**, not 0–0. A fixture you have not
played yet, or one nobody typed a score into, reads as played-without-a-result
rather than as a goalless draw.

**A score saves the score and nothing else.** Typing into either box leaves the
fixture's opponent, level, kickoff time, length and substitution windows
exactly as they were. The same holds the other way: shortening a fixture keeps
the substitution windows that still fit inside the new length instead of
clearing them.

> **Fixtures scored before this behaviour landed need a check.** Saving a
> score used to blank the fixture's opponent, level, kickoff time and notes,
> clear its substitution windows and reset its length to 20 minutes. Those
> fixtures have to be filled in again by hand — the values are not
> recoverable from the fixture itself. A fixture that was kicked off still
> has its opponent, formation and kickoff time on the match activity it
> created, so that is the quickest place to read them back. The release note
> in `CHANGES.md` says which releases were affected.

**The fixture is the only place the result is typed.** A completed fixture
carries its score across to the match activity it created, so the result shows
up wherever that activity is read — the minutes overview included. It shows
there **read-only**, pointing back here. The two used to be separate boxes for
one and the same fixture, so whichever was filled in last silently won.

**A tournament fixture is neither home nor away.** A game at a tournament has no
home leg, so nothing frames it as one. The minutes overview labels the two
numbers with your club's short code and *Opp.* rather than home and away. Before
this, a fixture was treated as a home game everywhere, because it had no
home/away marker and "not away" was read as "home".

A score typed in before the fixture is kicked off stays on the fixture and
travels to the activity the moment there is one, so recording results ahead of
the day loses nothing.

There is deliberately **no day total**. The team's record leaves tournaments
out entirely, so a goals-for/against figure across the day would have nothing
reading it. If that changes, it is cheap to add.

**Goals still count for the player.** A goal scored at a tournament has always
reached the scorer's record through the minutes grid's `G` column, and still
does — that half never depended on these boxes.

## What v1 doesn't do

- **Cross-team squad picks from the wizard** — you can pick from the anchor team's roster only in v1. Add players from another team via the REST API for now.
- **Constraint solver** — no "Casper must play GK every match" or similar. Use manual overrides; the manual layer is the constraint layer in v1.
- **Auto-weight by opponent level** — the tool shows the level, the coach picks the lineup.
- **Uneven substitution-window splits** — periods are derived from `duration_min ÷ (windows + 1)` assuming even splits.

## One player's tournament record

The planner answers "how did I divide this Saturday between sixteen
children". The **Tournaments** tab on a player's file answers the other
half: how a season of Saturdays has gone for one of them.

Open a player and choose **Tournaments**. You see what is coming up, the
headline figures — minutes, starts out of fixtures, full matches, minutes
against stronger sides — and then every tournament they were in the squad
for, each opening to its fixtures: the opponent and their level, the score,
whether the player started, came on or sat out, how many of the fixture's
minutes they got, and where they played.

**Each tournament is measured against that player's own minutes target**,
the one set on the tournament's squad list — never against a squad average.
A teammate's minutes are not this player's business, and a child's file is
the wrong place to learn how much more somebody else played. Only a
shortfall is coloured, and the numbers are written out beside the bar, so
nothing on the page depends on seeing a colour.

**Where the minutes come from is written on the page.** They follow the
rotation plan of the fixtures that have been completed: once a fixture is
completed the planner locks its assignments, so the plan of a completed fixture
*is* the rotation that was used. Minutes typed in afterwards on the minutes
overview live on the **Activities** tab, and the two are never added together.

Since the completion step (above), a fixture also records what the coach
confirmed on its own register, which is what the minutes overview and the
minutes reports show. Where a coach corrected the plan, this tab and those
reports can differ by that correction — the tab reads the plan. Which of the two
a player's tournament record should follow is being decided separately.

A fixture with no result recorded says **no result**, not 0-0. A goalless
draw and a game nobody typed in are different facts about a child's season.

A player who has never been in a tournament squad sees a line saying so, and
their tab carries no count. The tab still appears: a coach checking
fair-share play needs "never selected" to be visible rather than silently
absent.

**Who sees it.** A coach for the players in their own squads, a Head of
Development or academy administrator for anyone, the player for their own
record, and a parent for their child — unless the player has switched the
**tournaments** section off in their sharing settings, in which case the
parent sees a note saying so. The same answer is on the API at
`GET /players/{id}/tournaments`.

## Who can see this

In v1 the Tournaments tile, the planner, and every REST endpoint are gated to **Academy Admin** (WP `administrator` + `tt_club_admin`) only. Coach, Head of Development, Scout, Player, and Parent personas don't see the feature. The persona-expansion ship will open it to Coach + HoD.

## On your plan

Tournaments are a **Pro** feature. On Standard every tournament the club ran stays browsable — matches, squads, totals — and creating or editing one is locked. Auto-balance is sold separately again: a Standard club plans its grid by hand. See [Licence and account](license-and-account.md).
