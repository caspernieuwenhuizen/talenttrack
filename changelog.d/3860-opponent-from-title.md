# Opponents are read from the match title, on import and in a review screen (#3860)

Bump: minor

The monthly team report printed every fixture as "Unknown opponent", the live
scoreboard showed `OPP` and the minutes grid `OPP.`, on matches whose opponent
the coach could read on the activity itself. `tt_activities.opponent` was empty:
nothing wrote it until v4.126, and the Spond importer still did not — Spond has
no opponent field, so the other club arrives inside the event title, where no
downstream reader looks.

**Imported fixtures now fill the column in.** A shared parser reads the opponent,
and where the title gives a real signal the home/away, out of titles like
"Hedel JO12-1 - Ajax JO12-1" or "uit tegen DVVC". A title that says nothing
useful leaves both columns empty rather than guessing: a wrong club name printed
on five screens is worse than a blank one. The columns are written on first
import only, the way `notes` already is, so a coach's correction survives the
next sync. A tournament day is never given a single opponent — it is played
against several clubs.

**Existing fixtures are filled in from a review screen**, at
`?tt_view=opponent-backfill`. It lists every match with no opponent stored — its
date, its title as it stands, and a proposed opponent and home/away — each
editable, each with its own tick, and nothing is written until the screen is
saved. Suggestions the parser is unsure of are flagged. Coaches review the teams
they coach; academy-wide roles review the club. No silent migration over history
the monthly report is built on.

**What is still missing keeps asking.** The monthly report's Data quality section
now names every match with no opponent by date and title, on screen and in the
PDF, so the gap surfaces every month until somebody closes it.
