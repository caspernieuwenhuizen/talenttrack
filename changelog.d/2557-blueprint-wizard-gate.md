# Head coaches can create team blueprints again (#2557)

Bump: patch

The "+ New blueprint" button on a team's blueprint list was a dead link for
head coaches: the list rendered it from the `team_chemistry` matrix (which
grants head coaches manage on their own teams) while the wizard behind it
still gated on the raw `tt_manage_team_chemistry` capability, which only
administrators, heads of development and club admins hold. Clicking the
button just reloaded the page.

The blueprint wizard now resolves its entry gate through the same
`TeamChemistryAccess::canManage()` decision the list, the editor and the REST
writes already use, via a new optional `isAvailableFor()` hook on the wizard
registry. The other seven wizards are unchanged. A read-only viewer no longer
sees an empty-state message pointing at a button they don't have.
