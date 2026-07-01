# Match execution: completed matches are read-only, editing is opt-in (#2222)

Bump: patch

The match-execution screen now opens read-only and hides its mutating
controls (score steppers, +action / →on buttons, and the post-match
late-goal / late-substitution panels) behind an explicit **Edit** toggle in
the header. Editing is only offered while the execution still accepts
writes — during play, half-time, and the post-match review window. A
**finalized** match shows no Edit affordance and keeps its live controls
locked, matching the read-only state the REST layer already enforced. This
removes the confusing "the match is done but the buttons still work"
behaviour. Reuses the existing `tt_edit_activities` capability — no new
permission.
