Head coaches can open the Trial cases tile again (#2005)

Bump: patch

The Trial cases list view gated entry on `tt_manage_trials`, which maps to
`trial_cases:create_delete`. Head coaches hold `trial_cases [read, change]`
at team scope in the authorization matrix but not `create_delete`, so the
tile let them in but the view returned a "no permission" page. The view now
gates entry on a matrix read check (matching the tile), scopes the list to
the players on the head coach's own teams, and keeps the "New trial case"
create action plus the create/delete write paths gated on `tt_manage_trials`.
Head coaches can now view and edit trial cases for their teams; only managers
can create or delete them. Scout, head-of-development and admin behaviour is
unchanged.
