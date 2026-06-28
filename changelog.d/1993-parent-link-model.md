# tt_player_parents is now the single source of parent → child links (#1993)

Bump: patch

The `tt_player_parents` pivot is now the only live answer to "which children
does this parent have". The parent dashboard child switcher, the parent KPI
resolver, and the goal-thread participant graph previously matched
`tt_players.guardian_email` against the parent's WordPress email — a second,
divergent model that could disagree with the authorization layer (which
already read the pivot). They now all resolve through the new
`ParentChildResolver`, so the switcher and the me-view authorization list the
same children, club-scoped, with no email matching.

`guardian_email` is demoted to an invite/seed hint: it may still create a
pivot row when a parent is invited, imported, or seeded, but is never queried
to decide access. This is a code-only change with no migration — a parent
linked solely via `guardian_email` will surface once re-linked through the
invite/seed path or by an admin.
