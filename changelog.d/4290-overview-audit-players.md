# Injuries overview: the audit log records which players were read (#4290)

Opening the squad injury overview wrote an audit row with a count but no
players, so the log could not answer "who has looked at this child's injuries"
for reads made there. The row now carries the ids of every player whose
injuries the page showed, after its team and status filters: one row per
read, in the same shape `GET /player-injuries` writes. Both now go through
one writer, `InjuryOverviewScope::recordRead()`.
