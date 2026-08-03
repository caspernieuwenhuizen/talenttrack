# Methodology sets — schema foundation (#2317)

Bump: patch

Internal schema groundwork for selectable methodologies (epic #2316). A new `tt_methodologies` table makes a methodology a first-class, named set, and every methodology entity (principles, vision, formation, phases, learning goals, influence factors, set pieces, football actions, framework primer) gains a `methodology_id` linking it to one. Existing shipped content is backfilled into a default "JO14-1 Hedel" set, so nothing is orphaned and the read view is unchanged. No user-visible behaviour yet — selection and the second methodology land in follow-ups.
