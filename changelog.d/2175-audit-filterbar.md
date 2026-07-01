# Audit log: filters moved to the shared FilterBar (#2175)

Bump: patch

The audit-log filter bar (Action, Entity, User #, date From/To) now uses
the shared FilterBar component: an inline single-line row on desktop and a
"Filters" button + bottom sheet on phones and tablets, with Clear as the
sheet's reset action. Filtering behaviour is unchanged — same parameters,
same results. The old hand-rolled, inline-styled form was removed.
