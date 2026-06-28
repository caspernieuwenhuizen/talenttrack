# Strava integration — schema foundation (#2055)

Bump: minor

Adds the database foundation for the per-player Strava integration (epic
#2002): a `tt_player_strava_connections` table holding one encrypted-token
connection per player, and a player-scoped `tt_player_activities` table for
the personal training (runs, rides, conditioning) those connections import.
Both carry the `club_id` + `uuid` tenancy scaffold. Activities store
distance, duration, pace and elevation only — no heart-rate data, by design.
Schema-only; no behaviour change until the connect flow ships.
