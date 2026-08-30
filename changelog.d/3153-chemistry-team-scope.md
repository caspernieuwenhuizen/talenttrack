# Team chemistry routes check which team, not just whether (#3153)

Bump: patch

Five chemistry endpoints took a team id in the path and gated on *"do you hold
this permission anywhere"*. A head coach's `team_chemistry` grant is scoped to
their own teams, and that question answers yes for every team — so changing one
number in the URL returned another squad's suggested XI, depth chart and
coach-marked pairings, with player names attached.

The board, the lineup preview, the pairings list and the per-player fit scores
now ask whether the caller holds the grant **on that team**. Adding and removing
a pairing get the same treatment on the write side, with the delete resolving
the pairing's own team first, since its URL names the pairing rather than the
team. The per-player fit route resolves the player's team the same way.

An academy-wide `team_chemistry` grant — scout, Head of Development, Academy
Admin — still reads everything, and the sub-feature toggle still takes all of
these dark when team chemistry is switched off. The on-screen board was already
checking team membership; this brings the API into line with it.
