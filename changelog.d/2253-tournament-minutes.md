# Tournament minutes: recordable and counted in the minutes reports (#2253)

Bump: minor

Tournaments are now treated as a minutes-bearing activity type just like matches
and games, everywhere. A single-game tournament can be planned and run through
the live match surface (match prep + execution) exactly like a match; a
multi-game-day tournament records minutes with the by-hand per-player minutes
entry on the attendance screen. Both write the recorded minutes to the attendance
row. The team and player minutes reports now use one consistent activity-type
set (match, game, tournament), so a player who played tournament minutes shows
those minutes instead of a 0. No fabrication: a tournament with no recorded
minutes still shows 0, and for a multi-game day the line-up-derived starts are
approximate — the recorded minutes are the meaningful figure.
