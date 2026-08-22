# Alerts: three new Evaluations alerts (#2636)

Bump: minor

This release adds three alerts about evaluations. They are switched on from
the moment you update, for everyone who can act on them, so here is exactly
what you will start seeing:

- **Player not evaluated recently** — nobody has recorded an evaluation for a
  player for longer than your academy's threshold (eight weeks out of the box).
  Goes to the head coach of that player's team. A player who has never been
  evaluated is counted from the day they joined, so a trialist who arrived on
  Tuesday will not appear.
- **Evaluation window closing** — an evaluation window is within three days of
  closing and players in your team have no evaluation in it. Goes to the head
  coach. It stops the moment the window closes: a gap nobody can still fill is
  not something worth nagging about.
- **Evaluation not shared with the player** — an evaluation was recorded but
  the player-facing feedback field was left empty, so the player and their
  parents see nothing. Goes to the coach who wrote it and to the team's head
  coach, from a week after the evaluation until sixty days after it.

All four thresholds are academy settings rather than fixed numbers, because
an academy that evaluates every block and one that evaluates twice a season
disagree about what "recently" means: `alerts_eval_stale_weeks`,
`alerts_eval_window_closing_days`, `alerts_eval_share_grace_days` and
`alerts_eval_share_lookback_days`.

As with every alert, you never mark these done. Record the evaluation, or add
the feedback, and the alert clears itself at the next hourly check. You only
receive one about a player you already have permission to see.

This is the first of several instalments that fill out the alert catalogue.
They ship one module at a time, and each release names the alerts it adds —
a release that quietly changed twelve things the app nags about would be an
ambush rather than an improvement.
