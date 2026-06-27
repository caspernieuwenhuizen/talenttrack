My PDP redesigned to a timeline-first player development view (#1990)

Bump: minor

The player's *My PDP* surface is rebuilt around the season as a timeline. The
development conversations now sit on a horizontal rail as markers — completed,
the next planned talk, and later talks — with a progress fill up to the most
recent completed conversation. Tapping a marker expands that conversation's
detail in place (notes, agreed actions, agenda, goals discussed, saved
reflection and the acknowledgement button), so there is no long scroll.

Below the timeline the player sees their active focus goals with goal-specific
status labels, then a single self-reflection input for the one next-planned
conversation only — past and future talks never show an input, and there is
never more than one form. Any previously saved reflection appears to the right
of the input on wider screens and stacked below it on mobile. The 2-week
pre-talk window guard, the coach sign-off display, the acknowledgement flow and
the end-of-season verdict card are preserved.

The "which talk is next planned" and "is its reflection window open" decisions
live in the PDP domain layer (PdpCycleState), so the REST API and the rendered
view derive the same answer.
