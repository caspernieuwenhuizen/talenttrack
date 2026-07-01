# Minutes reports: one source of truth — actual recorded minutes only (#2193)

Bump: patch

The minutes reports now agree with each other. Every minutes report reads
only the minutes that were actually recorded for a match — persisted on the
player's attendance row when the match was finalised or when a coach entered
the minutes by hand. Reports no longer estimate, calculate, or reconstruct
minutes from a planned line-up: a match that was played but never finalised
now shows a truthful 0 / — everywhere, instead of one report inventing an
estimate (e.g. 70′) while another correctly shows nothing.

Concretely, the Analytics "Gespeelde minuten per team" report dropped the
report-time recompute-from-line-up fallback it still carried, bringing it in
line with the Player · Minutes and Team · Minutes-distribution reports and
the minutes audit REST endpoint, which already counted recorded minutes only.
Matches that do have recorded minutes are unchanged.
