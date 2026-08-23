# Photo-capture DPIA: precise subject-access position (#2695)

Bump: patch

Follow-up to the DPIA correction. The previous pass called the subject-access
position a "gap — either register the tables or state the limitation", which
offered an option that does not exist: `tt_activity_exercises` carries no player
identifier, and the export mechanism can only follow a column that joins to a
player.

The document now states the position exactly. Who attended a session **is**
covered, through `tt_attendance`. The extraction is not, and cannot be by that
mechanism. The real residual risk is narrower and sharper than "a gap": a player
name transcribed into the free-text `notes` column is reachable by neither a
subject-access export nor an erasure request, because erasing one player does not
delete a session that belongs to a whole team.

That is now prerequisite 7 in "Before this can be signed", with three ways to
close it: instruct the model not to transcribe names, strip them before save, or
accept and document the limitation.
