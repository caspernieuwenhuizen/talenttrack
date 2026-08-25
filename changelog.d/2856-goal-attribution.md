# Goals can carry an assist, and no longer have to name a scorer (#2856)

Bump: minor

A logged goal now records who assisted it as well as who scored it, and
either can be left unrecorded. Until now a goal for our team was refused
outright unless it named a scorer, which left a coach who did not see the
final touch — or an own goal, which has no scorer on the side it counts
for — with nowhere to put it.

The REST surface follows: `POST` and `PATCH /match-execution/{activity}/goal-event`
accept `assist_player_id` and `is_own_goal`, and the PATCH corrects the
attribution as well as the minute. The two halves of that payload are
independent, so correcting a minute cannot silently drop a scorer, and
attributing a goal cannot reset its minute. A scorer or assist naming
somebody outside the match squad is refused, as is a player assisting
their own goal.

Erasing a player now clears them from the goals they were involved in
rather than deleting those goals. The behaviour was always documented as
such, but the goal's scorer column is `NOT NULL` and so fell through to a
cascade delete — which, with the score about to derive from these events,
would have quietly rewritten the result of a match already played.

Existing goals read back exactly as before: attributed, with no assist.
