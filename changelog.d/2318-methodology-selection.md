# Methodology sets — per-team selection + install default (#2318)

Bump: patch

Adds the resolution layer for selectable methodologies (epic #2316): an install-wide default set stored in `tt_config` (`active_methodology_id`) plus an optional per-team override (`tt_teams.methodology_id`). A new `ActiveMethodologyResolver` picks the set for a given team — team override, then install default, then the club's default set — degrading gracefully to legacy behaviour before the tables exist. No user-visible surface yet; the read view and admin selector consume this in follow-ups.
