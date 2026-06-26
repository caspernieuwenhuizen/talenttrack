# Player comparison selectors now respect coach context (#2006)

Bump: patch

The Player comparison team and player selectors no longer expose the whole
academy roster to a team-scoped coach. Both the frontend tile and the
wp-admin Player Comparison page now narrow the selectors to the coach's own
teams, exactly like the standard reports surface and the `reports/player-radar`
REST endpoint: staff with academy-wide reporting access (head of development,
academy admin, scout) still see every team and player, while a team-scoped
coach sees only their assigned teams and the players on them. The scope is
also enforced on players addressed directly by `?pN=` link, so an
out-of-context player can't be pulled into a comparison.
