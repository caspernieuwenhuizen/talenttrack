# Positions your academy adds itself now read as their label, not their key (#3276)

A position added through **Configuration → Lookups → Positions** printed its
internal database key on every screen that showed it — a profile reading
*Verdedigende middenvelder · rechter_middenvelder*, with the seeded positions
translated and the academy's own ones not. The label the operator typed was
stored correctly all along; no read surface asked for it.

Twelve surfaces are fixed by one resolver: the profile header, identity card,
sidebar and player card, the teammate card, my profile, the overview, the coach
and player dashboards, the rate-card hero, the blueprint roster, the goal
wizard's link step, journey position-change events, and human-facing CSV / XLSX
exports.

An academy that renames a **seeded** position to its own vocabulary is now
obeyed too, and a position with no label anywhere reads as words rather than as
a key. The stored code is unchanged and stays the matching key, so renaming a
position moves nothing between chemistry buckets, formation slots or squad
selection.
