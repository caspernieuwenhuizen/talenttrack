# Record a match result without the live match sheet (#3530)

Bump: minor

A match that never went through the live match sheet now has a **Result**
card on its own page: what your team scored, what the opposition scored,
Save. Until now the scoreline had exactly one writer in the whole plugin —
the end-of-match copy off the live sheet — so a club doing its admin on a
Sunday evening could not record that a match finished 3–1, and could not
record the opposition's goals at all. Those are not attributable to a
player, so the minutes grid's goals column could never reach them.

The two numbers are always yours then theirs, whichever ground you played
on. Where it was played is a separate fact: the activity form gains
**Opponent** and **Home / Away** fields, which eight surfaces already read
(the detail hero and facts strip, match prep on screen and in print, the
team-sheet PDF, the weekly planner, the match sheet's own score labels and
the player's My-team fixture line) and which nothing had ever been able to
write.

A match the live sheet *did* run keeps a read-only card: the derived score,
its goal log, and a link to the post-match review, because that scoreline is
counted from the goals rather than typed. One writer per match, decided by
the match rather than by the coach.

An empty result saves as empty, never as 0–0 — a match with no result
recorded is counted as played-without-a-result rather than as a goalless
draw. Under the score the card reconciles attributed goals against your own
scoreline ("2 of 3 goals have a scorer"), which is a statement and never a
rule: it neither blocks the save nor rewrites the result.

Also fixes a latent bug this would otherwise have activated: the player's
My-team form line framed a result by venue and swapped the two scores for an
away fixture, which would have shown a 1–3 defeat as a 3–1 win the moment
anything started populating Home / Away.
