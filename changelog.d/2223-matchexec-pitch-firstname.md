# Match execution: pitch labels players by first name + last initial (#2223)

Bump: patch

The vertical pitch on the match-execution screen now labels each player by
first name plus last initial (e.g. "Daan P.") instead of the surname —
matching how a coach names a player from the sideline while staying
unambiguous when two players share a first name. Single-word names render
as-is with no stray dot. Display formatting only; the label still fits the
360px pitch slot.
