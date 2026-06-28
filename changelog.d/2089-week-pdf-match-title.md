# Week-PDF: match cards show the typed title (#2089)

Bump: patch

Match activities on the team-planner Week-PDF now print the title entered
on the activity form (e.g. "Candia 66 – Vv hedel 14-1") instead of
collapsing to just the team name. The card previously synthesized its
title as "Team — Opponent" and ignored the activity's own Title field;
since the form captures a required Title but no opponent, matches printed
only the team name. The card now prefers the entered title and falls back
to "Team — Opponent" (or the team name) only when no title is set. Match
location is unchanged — it already prints when the Location field is filled.
