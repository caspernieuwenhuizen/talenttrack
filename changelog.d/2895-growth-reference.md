# Groundwork for BMI-for-age: the WHO growth reference (#2895)

Bump: minor

A raw BMI says very little about a growing child — the same figure is
unremarkable at seventeen and high at nine. To mean anything it has to be read
against a growth curve for the player's own age and sex.

This adds that curve: the WHO 2007 reference for 5 to 19 year olds, generated
directly from the tables WHO publishes rather than typed in by hand, together
with the arithmetic that turns a BMI into a percentile.

It also adds the part that pairs up measurements. A BMI point comes from a
weight and a height taken within a month of each other; a weight with no recent
height produces no point at all, rather than being combined with a height from
last season and quietly reported as current.

Nothing is visible on screen yet — the report itself follows. Recording a
player's sex is what unlocks it, and that stays optional.
