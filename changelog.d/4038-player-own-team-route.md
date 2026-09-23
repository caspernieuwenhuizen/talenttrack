# A player can read their own team over the API (#4038)

`GET /players/{id}/team` answers with the squad the **My team** screen shows:
the team's name and age group, who runs it, and the roster — name, shirt
number, position. Nothing else. No ratings, no statuses, no medical data, no
contact details, and the team rank only when the academy has switched
`tt_player_visible_rank` on, exactly as the screen does.

Until now a player asking their own API for their own team got
`403 rest_forbidden`: `GET /teams/{id}` is a staff read, carrying the whole
team row and the squad counts, and it **stays one** — still 403 for a player
and a parent. This is the per-player read instead, gated on `canViewPlayer()`
like `players/{id}/goals` and `players/{id}/evaluations`, which is also what
lets a linked parent read it for their own child and nobody else's.

The route composes from the same query layer the screen does
(`QueryHelpers`, `TeamStatsService`), so the two cannot come to disagree.
