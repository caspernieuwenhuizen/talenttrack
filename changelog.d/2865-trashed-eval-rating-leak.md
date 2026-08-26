# Deleted evaluations stop feeding the team squad rating (#2865)

Bump: patch

A team profile could show a squad rating — *Selectiebeoordeling 8,3* — for a
team whose evaluations list was empty in every state, including archived. The
evaluations a coach had moved to the recycle bin were hidden from every list
they could open and were still counted in the number on the team's profile.

The list and the number disagreed about what "deleted" means. The list has used
the shared recycle-bin filter since the bin shipped; this KPI still carried its
own older check, written before the recycle bin existed, which knew about
archiving but not about the bin. Both now ask the same question.

A team whose evaluations have all been deleted shows a dash rather than a
number, and restoring one from the recycle bin brings the rating back.
