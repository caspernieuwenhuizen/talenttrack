# REST routes that take a record id now check that record (#4002)

A capability says whether somebody does a kind of thing; every capability in
TalentTrack is held club-wide, so on its own it never says *to whose record*.
A family of routes checked only the capability and then acted on whatever id
they were handed, which let a coach of one team reach another team's records:

- the activity routes for guests, status transitions, the evaluation-skipped
  flag and single attendance rows now resolve the activity's team and refuse
  with the same `403 forbidden_team` the activity update already used;
- every match-analysis route — read, write, sections, per-player items, the
  share link, its rotation, the view count and the team trends — resolves the
  match's team, the way match prep and match execution already did;
- every by-id training-plan and training-run route resolves the plan's or the
  run's team, and recording an observation also checks the player. A
  club-wide plan belongs to no team and stays open to everyone who may build
  plans;
- restoring, binning or permanently deleting an evaluation applies the
  per-player check its own delete already applied;
- `PUT /people/{id}` answers 404 for a person that is not in the club instead
  of reporting success for a row it never touched;
- a player's message log answers only for a player the caller may see;
- the recycle bin's cascade preview answers for a record on its way out, not
  for a live one.

Refusals answer 404 — the same answer a missing record gets, so the routes
can't be walked to map which records exist — except where a sibling route on
the same record already answers 403, which they match.
