<!-- audience: admin -->

# Cohort decision board

The **Cohort decision board** is a single read-only screen for end-of-season
decisions. Pick a team or age group and you get one row per active player,
with everything you need to make a retain / promote / release call side by
side. It lives under **Analytics** and is available to anyone with the
analytics capability; coaches see only the teams they coach, while academy-wide
roles (Head of Development, administrators) see every team.

## Which player question does this answer?

*Where is this player going next season?* The board pulls each player's recent
form, attendance, and development conversations into one place so the Head of
Development can decide who stays, moves up, or moves on — without opening a
dozen profiles.

## What's on each row

- **Player** — links to the player's profile.
- **Status** — the player's current status, shown as a coloured dot plus its
  label.
- **Rating** — the player's rolling-average overall rating from their
  evaluations. A dash means there are no rated evaluations yet.
- **Trend** — an arrow comparing the player's recent ratings against their
  earlier ones: up, down, or stable. Players with little rating history show a
  stable arrow.
- **Attendance** — the player's attendance percentage across the current
  season. Below 70% is highlighted. A **(low)** marker means the figure is
  based on only a handful of activities, so treat it with caution.
- **PDP talks** — how many development conversations have actually been
  conducted for the player.
- **Verdict** — the current verdict recorded in the player's PDP file, or
  **Pending** if none has been set yet.
- **PDP file** — a link straight into the player's PDP file (or to start one if
  none exists this season).

## Read-only by design

This board never sets a verdict. Verdicts stay where they belong — in each
player's PDP file. The board is a lens for making the decision; you record the
decision in the PDP file itself, and it shows up here.

## Sorting and export

Every column header is a sort toggle: click once to sort ascending, click again
to flip to descending. The current sort works without JavaScript, so it's
reliable on any device.

The **Export CSV** button downloads the current team's board as a spreadsheet —
player, status, rating, trend, attendance, PDP talks, and verdict — for sharing
or archiving alongside the season review.

## What you need first

A current season must be configured (under **Configuration → Seasons**) for the
board to work, because attendance and PDP verdicts are scoped to the current
season.

## Switching it off

The cohort decision board is a per-tile feature, **off by default**. An
academy admin turns it on (or off) under **Modules → Analytics → Cohort
decision board**. While it is off the tile is hidden and the
`?tt_view=cohort-board` URL returns the standard "not available" notice. The
central Analytics surface and the analytics engine are unaffected.
