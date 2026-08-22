# Alerts: two new data-quality alerts (#2636)

Bump: minor

This release adds two alerts about records that are simply incomplete. They
are switched on from the moment you update, for everyone who can act on them,
so here is exactly what you will start seeing:

- **Player has no team** — an active player belongs to no team, a week or more
  after being added. A player with no team has no attendance, no minutes, no
  evaluation-coverage row, and no head coach receiving any of the other alerts
  about them; TalentTrack genuinely cannot say where they are. This one is
  quiet: it appears on the bell, not as a banner.
- **Team has no head coach** — a team with players has nobody assigned as head
  coach. Most alerts go to the head coach, so a team without one quietly stops
  receiving any of them. A coach whose assignment has an end date in the past
  does not count. Teams with no players are ignored, and so are trial groups.

Both go to whoever looks after the records rather than to a coach, because
there is no coach to send them to — that is the condition. And both are
treated as player data: an alert that names a child is only shown to someone
already allowed to see that child's record.

The one threshold is an academy setting,
`alerts_player_without_team_grace_days`. Assigning a squad is usually the next
step in the same sitting as adding the player, so a brand-new record does not
appear immediately.

This is the fifth instalment filling out the alert catalogue.
