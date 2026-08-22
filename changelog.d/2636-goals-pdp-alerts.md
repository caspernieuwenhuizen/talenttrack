# Alerts: two new Goals and PDP alerts (#2636)

Bump: minor

This release adds two alerts about a player's development plan. They are
switched on from the moment you update, for everyone who can act on them, so
here is exactly what you will start seeing:

- **Goal past its target date** — a development goal has passed the date it
  was aimed at and is still open. Goes to whoever set the goal and to the head
  coach of the player's team, from three days after the date until a year
  after it. Either the player got there and nobody recorded it, or the plan
  needs changing; both are answers, leaving the goal untouched is not.
- **No PDP conversation this cycle** — a player's PDP file for this season is
  open but no conversation has actually been held. Goes to the coach who owns
  the file and to the team's head coach, from 45 days after the file was
  opened. Conversations that were scheduled but never held do not count: a
  cycle is created with all of its conversation rows already written, so
  counting rows would mean the alert could never appear.

Both thresholds are academy settings rather than fixed numbers:
`alerts_goal_overdue_grace_days`, `alerts_goal_overdue_lookback_days` and
`alerts_pdp_no_conversation_days`.

Only the current season's PDP cycles are considered. Last season's untouched
cycle is history, not a gap anyone can still close.

This is the second instalment filling out the alert catalogue, after the
Evaluations alerts. They ship one module at a time so that every release can
tell you what it added.
