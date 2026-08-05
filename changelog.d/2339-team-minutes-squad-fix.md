# Team · Minutes distribution: fix "18 matches / 0 players" (#2339)

The Team · Minutes distribution standard report resolved its squad from
`tt_players.team_id` while counting matches from the team's activities, so a
team whose players had no `team_id` set showed a match count but zero players
and no minutes. The squad is now derived the same way the rest of analytics
resolves a team — players with recorded attendance on the team's
match / game / tournament activities — so the player list and the match count
share one team-membership definition, and a player appears even with 0 recorded
minutes. Minutes still come only from persisted `record_type='actual'`
attendance rows (never estimated), so a match with no recorded minutes
contributes 0.
