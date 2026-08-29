---
title: Match analysis
group: performance
summary: Review a match per team function, name who stood out and who fell short, then print or share it.
audience: [user]
views: [match-analysis]
module: TT\Modules\MatchAnalysis\MatchAnalysisModule
order: 40
---

# Match analysis

A **match analysis** is what you made of a game. Match preparation holds the
plan and match execution holds what happened; this is the third side of the
same match — how each phase went, and which players did what.

Open it from a match activity's detail page. The first time, you are walked
through it step by step; after that the same analysis opens as one page you
can edit directly.

## When it opens

Only for match activities, and only once the match has been played (the
date has passed, or you finalized it on the sideline screen). Before that
the action is not offered — an analysis written before kick-off is a
prediction.

Tournament days are not covered yet. A tournament is several games and one
analysis cannot say which of them it is about.

You do **not** need a match plan or a live-match record. A game you ran off
a paper team sheet gets the same analysis; there is simply nothing to
pre-fill.

## What you write

### Overall

A few sentences on how the match went. This is the part people read first,
so write the thing you would say in the dressing room.

### The two chains

Six phases in the academy's own methodology vocabulary, in two columns that
each read top to bottom as a chain:

**With the ball**

- **Aanvallen**
- **Omschakelen naar verdedigen** — the instant we lose it
- **Set pieces — ours**

**Without the ball**

- **Verdedigen**
- **Omschakelen naar aanvallen** — the instant we win it
- **Set pieces — theirs**

A transition only means something read next to the phase it comes out of,
which is why these are two chains rather than one list of six.

Each phase carries a rating — **Went well**, **Mixed** or **Needs work** —
beside its name, and up to four short points beneath it. The three choices
show as ▲ ● ▼ rather than as words: with five phases on a screen the same
three words printed fifteen times crowded out the phase names. What each
glyph means is stated once, in a small legend on the line that introduces
the phases, and every button still names itself to a screen reader and on
hover. Both the rating and the points are optional: a phase you leave alone
simply shows as *Not rated* and counts as nothing, rather than as a middling
score. Once you have set a rating, a small **Clear** appears next to it.

On the printed sheet and the share page the rating is still written out in
words — there it appears once per phase, so there is nothing to crowd.

#### Marking a point good or bad

In front of every point sit two small buttons: **+** and **−**. Use them to
say which of your points were the good half and which were the problem.

A phase rated *Mixed* with four points under it used to read as four
undifferentiated sentences six weeks later, and to whoever you sent the
share link. You knew which was which while typing; this is how the surface
keeps it.

- **Leaving a point unmarked is normal**, and is the state every point
  starts in. "Played at right-back" is an observation, not a verdict, and
  nothing forces you to grade it.
- Once you have marked a point, a small **×** appears beside it to take the
  mark off again.
- The **+** and **−** are deliberately not the ▲ ● ▼ of the phase rating.
  Those grade the whole phase; these qualify one sentence, and giving them
  the same shapes would make the two impossible to tell apart in one card.
- The marks show on the printed sheet and the share page, in front of the
  point they belong to.

The marks are stored as their own field, not as characters typed into the
sentence — so a point beginning with a hyphen stays a hyphen, and the
trends further down this page can count them.

Where the match plan asked for something in that phase, it appears as
**Planned**, so you are reviewing against what you asked for rather than
against memory. Each of the plan's four goal boxes lands beside its own
phase; the two transitions have no counterpart on the match-prep screen, so
they never show a planned line.

### Players

Everyone who played is listed — as a **grid of names**, not a stack of
forms. Tap a name and pick one of three:

- **▲ Stood out**
- **● As expected**
- **Below par ▼**

The name then takes that colour and glyph, and the player appears below
under **Notes**, where you write the specific thing they did ("kept taking
the ball in the half-turn under pressure" — not a verdict) and, if it
belongs to one, the **phase** it belongs to. Tap the name again to change
your mind, or choose **Clear** to take the mark off.

**Two note lines per player**, each with its own **+** / **−**, for the
common case: a player who did one thing well and one thing badly in the
same match. Before, one note had to serve both and the marker above it
could only be one or the other, so you had to pick.

Two is the limit on purpose — a fourteen-player squad with an open-ended
list would put twenty-eight text boxes on a phone screen, which is what
this roster was rebuilt to avoid. Leave the second line empty whenever
there is only one thing to say.

The ▲ ● ▼ marker stays what it always was: your read on the player's whole
match. The notes are the evidence under it.

Only the players you marked get note fields, so a squad of fourteen fits
on one phone screen and an analysis you have not started yet has no text
boxes on it at all.

Leaving a player untouched is the normal case and says nothing about them.
**As expected** is different: it says you looked and were satisfied, which
is worth recording for a player whose parents ask why they are never
mentioned.

If you flagged a player on the match plan — a specific goal, or an
attention note — that line shows on their row, so you can answer the thing
you asked for.

The roster lists everyone on purpose. Picking two names from a search box
is quicker and quietly skips the children nobody thinks to search for.

## Where the player notes end up

Every player note also lands on that player's own **timeline**, dated to the
match, as *Observed in a match*. That is the point of writing them: six
weeks later the question is not "what happened in that game" but "what has
this player been showing", and the timeline is where that is answered —
next to their evaluations, goals and PDP entries.

These notes are **staff-only**, like an evaluation's internal notes.
Deciding what the player is told is a separate, deliberate act.

Clear a note and the timeline entry goes with it. Rewrite it and the entry
is rewritten, not duplicated.

A player with two notes still gets **one** timeline entry for the match,
with both notes on it and each carrying its mark — one game is one moment
in a player's season, and two entries would count them twice in every view
built on the timeline.

## Reading it back

Once written, the analysis reads as one page: the match and the score
across the top, your overall read beneath it, then the two chains side by
side and the players you mentioned in a column of their own. It is the same
page whether you open it, print it, or send someone the link — so what you
see is what they get.

Phases you left unrated still appear, marked as such. The page should show
what you said and, just as plainly, what you did not.

### The goals

Between your overall read and the phase tiles, the page lists **the goals
that made the result**, in the order they happened — minute, who scored,
and the assist where one was recorded. Minutes run straight through both
halves, so a second-half goal reads as `52'` rather than restarting at
`22'`, and the list shows the shape of the game rather than two separate
clocks.

They sit above the phases on purpose. A run of three conceded inside ten
minutes is the context for the defending-phase rating underneath it, and
until now that context lived on a different screen.

Our goals show the scorer; where you logged the goal without one it reads
**Scorer not recorded**, and an **Own goal** says so rather than borrowing
somebody's name. The opponent's goals appear as timed marks with no
scorer — their squad isn't in the system.

The list is **read-only**. Goals are logged and corrected on the
match-execution screen, and a second place to type them would be a second
place for them to be wrong. A match with no logged goals shows no goal
section at all: an empty list would claim nobody scored, when usually it
just means the match was never run through the live screen.

## Reading a season of them

One analysis is a note. Ten of them are a trend, and there are two ways to
read the trend without opening every match.

**Per team** — Reports → **Team · Match analysis trends**. For the team and
period you pick, it shows how often each phase of play was rated *Went well*,
*Mixed* or *Needs work*. "Switching to defending was rated needs work in six of
the last eight" is the sentence that should drive next month's training theme.

**Per player** — the **Match analysis** tab on a player's file. How many
matches they have been marked in over the last twelve months, how often each
marker was used, and which phases those markers were tagged to. The individual
notes are already on their journey; this is the summary above them.

Three things both surfaces do on purpose:

- **They count; they never average.** *Went well* / *Mixed* / *Needs work* are
  three ordered words, not a score. Turning them into a number like 1.8 would
  invent a precision nobody typed.
- **A phase you left alone counts as nothing.** Not neutral, not a middle
  value — excluded. Leaving a phase unrated is a real answer and the reports
  honour it.
- **Below three rated matches there is no trend.** You get an explicit "not
  enough matches yet" instead of a line drawn through one data point. On a new
  academy that is what you will see first, and it is telling you the truth:
  the data is being collected, and this is the threshold.

## Print, or save as PDF

**Print or save as PDF** opens the analysis as a clean **landscape A4** in
a new tab — no menus, no navigation — and your browser's own print dialog
turns it into a PDF. It is built to fit one sheet, and the text stays
selectable and searchable.

Players you did not mention are left off the printed sheet. The roster on
screen is there so nobody is forgotten; a printed list of names with
nothing beside them says something you did not mean.

## The staff share link

A share link does not exist until you ask for one. **Create share link**
makes it; until then the analysis cannot be opened by anyone outside the
app, however hard they guess.

Once it exists you get the URL itself with **Copy link** beside it — send
it to an assistant coach, a scout, or whoever else on the staff needs to
read it. It opens the analysis read-only, without a login.

Three things to know before you send one:

- It shows the analysis **only once you have marked it final**. Send the
 link whenever you like — until then it says the analysis is not finished
 yet, and starts showing the document the moment you publish it.
- It shows the **player notes in full**, including who fell short. It is a
 staff document, and the page says so on its face. It is not indexed by
 search engines.
- **Anyone holding the link can read it** until you replace it.
 **Replace link** mints a new URL and shuts every previous one
 immediately — use it when a link has travelled further than you meant, or
 after someone leaves the staff.

### Has anyone opened it?

Once someone has, a line appears under the link: *Seen by 4 people · last
opened 2 days ago*. Before the first visit it says nothing at all, so an
empty space means "not yet", not "broken".

What it counts, and what it does not:

- **Browsers, not names.** There is no login on a share page, so there is
 nothing to put a name to. One person on a phone and a laptop counts as
 two; two people sharing one computer count as one.
- **Not what anyone read, or when they read it.** There is no per-visit
 log and no way to ask who opened it. A document you share with
 colleagues should not double as a record of who looked at it.
- **Not link previews.** WhatsApp, Slack and the like fetch a URL the
 moment it is pasted into a chat. Those fetches are ignored.
- **Replacing the link does not reset the count.** The count belongs to
 the analysis, not to the URL that addressed it.

**How it recognises a returning reader.** A small cookie is set in the
reader's browser on their first visit, holding a random number and nothing
else. It exists only so that opening the page twice does not count twice.
It is first-party, carries no identifier that works anywhere else, and
needs no consent banner for that reason. Where a browser refuses cookies,
a one-way, salted fingerprint of the connection stands in — the address and
the browser version are used to compute it and neither is stored.

**Everything is deleted after 90 days.** The way a returning reader is
recognised is derived from their connection, so it is not kept
indefinitely: after 90 days those records are removed and only the totals
you see on screen remain. The count therefore never goes down; a reader who
comes back long afterwards is simply counted as a new one.

The share page is also never cached, which is why the count is reliable —
and why a page naming children is not sitting in a shared cache somewhere.

## Saving

**There is no Save button.** The analysis saves itself as you write it. A
status line at the foot of the form says where it got to — *Unsaved
changes…*, *Saving…*, *All changes saved* — and it is the same line, in the
same words, as every other screen in TalentTrack that saves as you work.

This is the surface most worth protecting: you are usually writing it on a
phone, after the final whistle, over several minutes. Losing a paragraph to
a tapped Back button is the failure that matters here, not saving a
sentence you were not sure about.

Beside the status line sit two ways back:

- **Undo** takes back the last change that was saved.
- **Revert changes** puts the whole form back to how it was when you opened
 the screen. It asks first, and says how many fields it will restore.

Both are described in full in
[how saving works](save-model.md) — they behave identically here.

There is no **Cancel**, because there is nothing uncommitted to cancel.
Leaving the page leaves a draft, which is a real and readable state: see
below.

### Draft and final

Every analysis starts as a **draft**. Autosave only ever writes the draft —
nothing you type is published by typing it.

**Mark as final** is the one deliberate action left on the form. It is a
publish, not a save: it says the write-up is finished and lets the staff
share link show it.

- Until you mark it final, the share link stays valid but shows *This
 analysis is not finished yet* rather than a half-written sentence about a
 named child.
- An analysis that is already final **stays final** if you go back in and
 fix a typo. Reopening a published document does not unpublish it from the
 people who were sent the link.

### If someone else is writing the same one

A head coach in the stand and an assistant on the touchline can both open
the same analysis. If the other person saves while you are writing, your
next save is **refused rather than merged**, and the status line says
*Someone else changed this analysis. Reload the page to see their version.*

Nothing you typed is removed from the screen — but nothing more is saved
either, so copy anything you want to keep before you reload. Refusing is
deliberate: quietly overwriting a colleague's write-up of a child, sentence
by sentence, with neither of you told, is the worse outcome.

## What you can't do here

- Change the score, the goals or the substitutions — those belong to
 **match execution**, and a second place to type them is a second place for
 them to be wrong.
- Write an analysis for a training. Trainings have their own observations.
- Tell the player something. Player-facing feedback goes through their
 evaluations, where it is written for them to read.
