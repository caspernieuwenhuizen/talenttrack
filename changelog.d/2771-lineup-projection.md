# Saving an activity no longer empties its Line-up card (#2771)

Bump: patch

A match prep writes
its Starting XI and bench through onto the activity's attendance rows, and the
activity detail's Line-up card — and the match-day team sheet's fallback — read
nothing else. Saving the activity rewrote those rows to store status and notes
and silently dropped the two line-up columns along the way, so the card went
blank while the prep itself was untouched. Re-opening the prep still showed the
line-up, which is why this read as a card that disappeared rather than as data
that was lost.

The rewrite now carries the line-up across on both paths, planned and completed.
An explicit starter tick on the completion form still wins: the coach who ticked
the box on this save meant it.
