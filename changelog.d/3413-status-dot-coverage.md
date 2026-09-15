# The status dot now says when it was computed without potential (#3413)

Bump: patch

The traffic light is a weighted average over the inputs that have a value: a
player with no potential band recorded is scored 40/25/20 while the team-mate
next to them is scored 40/25/20/15. That is the right arithmetic, and the
squad table rendered both as the same coloured circle — so the one comparison
the dot exists for was not like-for-like for most of a roster.

`StatusVerdict` now carries `coverage` (the share of the enabled, weighted
inputs that actually contributed), `missing_inputs` and a `coverage_note`
sentence, and the REST payload carries all three. A dot computed on partial
evidence renders with a hollow centre and names the gap in its accessible name
— *"Extra attention — Computed without potential."* — rather than leaving the
difference in hue, which there was none of.

Grey (*Building first picture*) is unchanged: it still means every input is
missing, not one of them. An input the methodology weights at zero is not
counted as a gap, so an academy that has switched potential off does not read
as permanently under-covered.
