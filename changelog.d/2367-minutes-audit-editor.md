# Minutes-audit per-match editor (#2367)

Bump: minor

The Minutes-audit tool gains an editable per-match surface. From the audit
overview, admins and head coaches can now open a match and correct the
recorded minutes per player, writing the authoritative recorded value — the
same source the reports and the match-execution screen read, so a match
opened in match-execution after an audit edit reflects the changed numbers.

The editor routes each write automatically: a match with a match-execution
takes an explicit per-player override that survives every recompute and
stays correctable once finalized; a paper match writes the recorded minutes
directly. Editing is conservative — an emptied field is an explicit clear,
never a silent zero, and untouched rows are never written. The overview's
edit link is hidden for users who cannot save.

The audit overview now reflects effective minutes
(`COALESCE(minutes_override, minutes_played)`), consistent with the minutes
report and the minutes-authority arbiter.

This first version edits total minutes per player; editing the starting
line-up per half and the substitution log is a planned follow-up.
