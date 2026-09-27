# Minutes report: a half-time change shows in Came on and Went off, and a new alert for games without a match record (#4060)

A change made at half time now counts in both columns: the player who comes on
at the break shows a substitute appearance, the player they replace a
substitution. Both are read from the same match timeline as the starts and the
minutes; nothing is written to the substitution log to achieve it, so the log
stays a record of what the coach tapped during the match.

A game whose minutes were typed in afterwards has no match-execution record
and so no timeline to read; its substitution columns stay at 0. The new
**Match played without a match record** alert tells the team's coaches about
such a game two days after it was played, and clears itself once the record
exists. Tournaments are left out, and academies without match execution never
see it.
