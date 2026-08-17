# Demo data: scouting, trials and tournaments (#2467)

Bump: minor

The intake pipeline was invisible on a demo install: no prospects, no scouting
visits, and trial cases only if you happened to upload a workbook containing
them. All three are generated now, along with tournaments.

Most generated players carry a historical trial case, closed with an admit
decision and dated before they joined the roster. That matters more than it
sounds: without it a demo academy's players appear fully signed from nowhere,
and the player journey the product is built around has no beginning. A couple
of players keep an open case so the surface a scout works on every week has
something on it. Each case has a staff panel of two or three, assessments from
most of them, and extensions on some of the open ones.

Trial cases fire the same hooks the Trials module fires, so the timeline gets
its trial-started and decision events in exactly the shape production writes
them.

Scouting visits run across the window in all three states — completed, planned
and cancelled — with prospects attached to the completed ones, named from the
same Dutch pools the roster uses so the pipeline reads like the same club.

Tournaments get a squad with target minutes, four short fixtures, and
per-period assignments that rotate through the squad so nobody sits out —
which is the point of a youth tournament planner.
