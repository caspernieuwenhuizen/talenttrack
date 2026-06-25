<!-- audience: user -->

# Match execution — the live match-day surface

The match-execution screen is the phone-first surface an assistant coach
runs from the sideline during a match. It opens from a match activity's
detail page once the match has been prepared (see *Match preparation*) and
keeps the score, the timer, and the per-player tracking in one place.

## Line-up — the vertical pitch

At the top of the screen, below the score and timer, a vertical pitch
shows the **first-half starting eleven laid out by position**. Each player
sits on the spot their match-prep line-up slot maps to, using the bound
formation's shape (4-3-3, 4-2-3-1, 4-4-2, and the other supported shapes).

- A filled spot shows the player's shirt number (or position label when no
  number is set) and a short name.
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

Both require the `tt_edit_activities` capability, the same permission that
gates the match-execution screen itself.
