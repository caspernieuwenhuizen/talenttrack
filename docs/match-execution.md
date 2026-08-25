---
title: Match execution
group: match-day
summary: 'The phone-first live match surface: line-ups, substitutions and minutes as the game runs.'
audience: [user]
views: [match-execution, match-executions]
module: TT\Modules\MatchExecution\MatchExecutionModule
order: 20
---

# Match execution — the live match-day surface

The match-execution screen is the phone-first surface an assistant coach
runs from the sideline during a match. It opens from a match activity's
detail page once the match has been prepared (see *Match preparation*) and
keeps the score, the timer, and the per-player tracking in one place.

The breadcrumb above the screen links back to the parent activity:
**Dashboard / Activities / {activity} / Match execution**. Tapping the
activity crumb returns you to the match activity's detail page.

## Editing is opt-in

During play the mutating controls are already revealed — substituting a
player is the whole point of the sideline tool, so you never have to tap
Edit first. In the **post-match review window** the screen opens read-only
to guard against accidental taps: the score, goals, and substitutions are
shown but not editable until you tap **Edit** in the header.

## After the match — adjust every datapoint

Once the match ends it enters **pending review**. This is the full
review-and-edit state: with Edit turned on you can adjust **every measured
datapoint** — **add or undo a substitution**, **add or undo a goal**, and
correct **minutes** (by fixing the substitution log, or via the *Add late
goal / substitution* panels for events you forgot to tap live). The score
follows the goals you add or undo; it is not edited on its own.
Correcting a substitution re-runs the minutes calculation, so the recorded
minutes the reports read stay in step with what you change.

When you are done, tap **Finalize match** to lock it. A finalized match is
the record of what the players actually did, so the live controls stay
locked and the Edit button disappears. (The server enforces the same lock,
so a finalized match refuses goal and substitution changes
regardless of the screen.)

The post-match screen also offers **Write the match analysis** — the moment
the match ends is the moment you still remember it. See *Match analysis*.

### Re-opening a finalized match

Finalizing is a deliberate lock, but never a dead-end. A finalized match
shows a **Re-open for corrections** action. Tapping it (you'll be asked to
confirm) returns the match to *pending review* so you can correct any
datapoint — subs, goals or minutes — then finalize again. Every
re-open is recorded in the audit log, and re-opening re-runs the minutes
calculation so the reports stay consistent.

Both finalizing and re-opening need the `tt_edit_activities` capability,
the same permission that gates the rest of the match-execution screen.

## Tracked players

Any player you flagged in the match plan — with a specific goal or an
attention note — appears in the **Tracked players** section with a live
counter. Tap **+ action** each time that player does the thing you're
watching for (a run in behind, a duel won, a shot on target — whatever the
note says); long-press to remove the last one you counted.

These tallies are **development actions, not goals**. They are recorded as
their own timed events and never change the scoreline. Goals that make up
the score are logged separately, through the goal button on the scoreboard
(see *Logging a goal*). Keeping the two apart means a player's development
tally can't accidentally inflate the result.

## Logging a goal

Each side of the scoreboard has a **+** button. It doesn't nudge a number —
it opens the **goal sheet**, which is how every goal gets recorded.

For one of our goals the sheet asks **who scored**, offering the players
currently on the pitch first, with the bench and the rest of the squad
behind a toggle. Pick the scorer and it asks **who assisted**; pick an
assist, or tap **No assist**. If you don't care about assists, **Save goal**
is available as soon as you've named the scorer, so a goal is two taps.

The minute is filled in from the match clock and the half from where you
are in the match. Both are editable in the sheet — coaches routinely tap
half a minute after the ball goes in, and the sheet is also how you add a
goal after the whistle, when the clock no longer says which half it was.

Two escape hatches sit under the players:

- **Scorer not recorded** — you didn't see who got the final touch. The
  goal counts, and the review screen will remind you to attribute it later.
- **Own goal** — on our side this is the opponent putting it into their own
  net, and nobody on our side is credited. On theirs, it opens our squad so
  you can say which of ours it was.

Nothing in the sheet is mandatory. A goal you can't attribute is still a
goal, and recording it with a gap is better than not recording it at all.

**The scoreline is a readout of the goals you've logged** — it is not
separately editable, and there is no way to set it to a number the goals
don't add up to. To remove a goal, undo it in the **Live progress** feed
(see *Undoing a goal or substitution*); the score follows.

## Who owns the minutes

Minutes played are **derived automatically** from the starting eleven and
the substitution log — you never have to type them. Because they're
derived, the way to fix a wrong figure is normally to correct the
substitution that produced it.

When a genuine correction can't be expressed through the sub log — a player
who left with a knock and no substitution was logged, say — the review
screen's **Recorded minutes → Correct** panel lets you set an explicit
per-player override. An override wins over the derived figure and survives
every later recalculation; clear the field to fall back to the derived
value. This is the one place minutes are hand-set for a match that was run
through this screen, which is why the ordinary attendance minutes field
steps aside and points you here for those matches.

Matches you never run through match execution are unaffected: there's no
execution to own their minutes, so you record them the usual way on the
activity's attendance.

## Undoing a goal or substitution

Every logged goal and substitution in the **Live progress** feed carries an
inline **Undo** link while the match still accepts edits. Undo works after
a page reload — it is keyed to the stored event, not to a short-lived tap
memory — so a mis-tapped goal or a wrong substitution can be corrected at
any point up to finalize. A just-logged substitution also offers a quick
**Undo** in its confirmation toast.

## Correcting a substitution's minute

Coaches often log a substitution a little late — the swap happened at 55'
but you tapped it in at 58'. With **Edit** on, every substitution in the
**Live progress** feed shows a **Correct minute** stepper (− / + and a
number field). Changing it saves the corrected minute and re-runs the
minutes calculation, so **both** players' recorded minutes move to match:
the player who came off gains (or loses) the difference, and the player who
came on loses (or gains) it. You never edit minutes directly — you fix the
*time* of the event, and the minutes follow. The corrected minute is range-
checked like any other (see below).

## Squad timeline — who played, when

After the match, the review screen shows a **Squad timeline**: one bar per
player across the whole match (0' to full time). A green segment is time on
the pitch; a hatched segment is time on the bench. Each substitution sits on
the boundary with its minute — `▲` where a player came on, `▼` where one came
off — and a `⚽` marks each of that player's goals. The minutes played sit at
the end of each row, read from the same recorded figure the reports use, so
the timeline can never disagree with the minutes report. Players are grouped
into **Started — XI** and **Started — bench**; a substitute who never came on
shows `0' · unused`. This is the at-a-glance answer to "who started on the
bench, when did they come on, for whom, and how long did each play?"

## Opponent goals

The scoreline is made of **match goals** for both teams, and **both sides
are counted from the goals you log** — neither score is a number you set
by hand. Our goals carry a scorer and, where you recorded one, an assist;
the opponent's are timed events with no individual scorer, since their
squad isn't in the system.

On the review screen the **Match goals** section lists both — ours with the
scorer, theirs as "Opponent goal" — and with **Edit** on you can add a goal
either side missed, correct a minute, or remove one. Match goals are
distinct from **Tracked players**, which count individual development
actions and never touch the score.

### Filling in a scorer afterwards

Each of our goals shows the scorer and, where you recorded one, the assist.
A goal you logged without one reads **Scorer not recorded**; an own goal
reads **Own goal**. Those are different facts and the screen keeps them
apart — only the first is something to go and fix.

With **Edit** on, every one of our goals carries a scorer and assist
picker. Set them and tap **Save attribution**; the goal moves onto that
player's record. The same picker is how you correct a goal you attributed
to the wrong player at the time.

When any of our goals still has no scorer, the section says so above the
list. It is a reminder, not a gate — **you can still finalize the match**.
A coach who genuinely never found out who scored has to be able to close
the match out, and an unattributed goal is a better record than no goal.

### Matches played before per-goal logging

A match recorded before goals were logged individually has a score with no
goal events behind it. Those results are kept exactly as they were
recorded — nothing is invented and no historical result changes. Where the
stored score and the logged goals disagree, the review says so in a short
note, so the screen never presents a figure the goal list cannot account
for as though the two agreed.

## Minute and roster checks

The screen refuses an impossible substitution — you cannot take off a
player who is not on the pitch, or bring on a player who is already on —
and a goal or substitution minute outside the match length (plus a short
stoppage allowance) is rejected rather than silently clamped. These checks
run on the server, so they hold for any client.

## Line-up — the vertical pitch

At the top of the screen, below the score and timer, a vertical pitch
shows the **first-half starting eleven laid out by position**. Each player
sits on the spot their match-prep line-up slot maps to, using the bound
formation's shape (4-3-3, 4-2-3-1, 4-4-2, and the other supported shapes).

- A filled spot shows the player's shirt number (or position label when no
 number is set) and a short name. The short name is the player's **first
 name plus last initial** (e.g. "Daan P."), the way a coach names a player
 from the sideline; a player with a single-word name shows it as-is.
- An empty spot — a slot with no player assigned in the prep — shows a
 dashed marker with the position label.

The pitch renders cleanly on a 360px phone screen; it scales up on larger
phones and tablets. Positions come straight from the match-prep line-up, so
fixing a position in the prep updates the pitch here.

## Live progress — the event log

Below the pitch, the **Live progress** feed (Dutch: *Live verloop*) lists
the match's goals and substitutions in chronological order. Each row shows:

- the **half and minute** the event happened (e.g. `H1 23'`);
- a **type chip** with an icon and a label — a ball for a goal, a swap
 arrow for a substitution (the chip always pairs colour with an icon and
 text, so it stays readable for colour-blind users);
- for goals, a **running score chip** showing the scoreline after that
 goal;
- the player involved — the scorer for a goal, or "{on} on for {off}" for a
 substitution.

The feed is built from the same goal and substitution events the live
surface already records as you tap them during the match (and from any late
goal or substitution added during the post-match review window). Red and
yellow cards are not tracked, so they do not appear in the feed.

## Correcting recorded minutes

Each player's **recorded minutes** are computed automatically from the
substitution log while the match is live and in the post-match review
window. Once the match is **finalized**, no further automatic recompute
runs — so a finalized match adds a **Correct recorded minutes** action
under *Recorded minutes*.

Tap it to reveal a numeric field per player, correct any figure that was
logged wrong, and **Save** (or **Cancel** to leave without changing
anything). This is a data correction on the attendance record only — it
does **not** reopen the locked match, so the score, goals, and
substitutions stay locked. The corrected value flows straight into the
minutes reports. Before the match is finalized, fix the substitution log
instead and the minutes recompute correctly.

Correcting minutes needs the `tt_edit_activities` capability, the same
permission that gates the rest of the match-execution screen.

## Where the data comes from

Both surfaces read from the data the live match already captures — the
match-prep line-up for positions, and the goal and substitution logs for
the feed. Nothing new needs to be entered to populate them.

The same data is available over the REST API for integrations and the
future web app:

- `GET /wp-json/talenttrack/v1/match-execution/{activity_id}/event-feed`
 — the merged, time-ordered goal + substitution feed with running score.
- `GET /wp-json/talenttrack/v1/match-execution/{activity_id}/pitch-lineup`
 — the first-half starting eleven with position coordinates.
- `DELETE /wp-json/talenttrack/v1/match-execution/{activity_id}/substitution/{event_uuid}`
 — undo a logged substitution (soft-delete; the minutes recompute).
- `POST /wp-json/talenttrack/v1/match-execution/{activity_id}/reopen`
 — re-open a finalized match for corrections (returns it to *pending
 review*; audit-logged).
- `PATCH /wp-json/talenttrack/v1/match-execution/{activity_id}/minutes`
 — set (`{player_id, minutes}`) or clear (`{player_id, minutes: null}`)
 a per-player minute override.
- `POST /wp-json/talenttrack/v1/match-execution/{activity_id}/tracked-event`
 and `DELETE .../tracked-event/{event_uuid}` — log or undo a tracked
 development action for a flagged player.

All require the `tt_edit_activities` capability, the same permission that
gates the match-execution screen itself.
