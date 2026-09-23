# A new team with no head coach says so (#4007)

Create a team and leave the head coach empty — the wizard's staff step is
skippable and the flat form has no staff fields — and the next page now says
so: *"JO14-1 has no head coach yet. Assign one under Staff on the team."* It is
one dismissible flash on whichever page the creating surface lands on.

Worth saying at that moment because almost every notification in the plugin is
addressed to a team's head coach, so a team without one quietly receives
nothing about its players. The standing *Team has no head coach* alert
deliberately covers only teams that already have players; this prompt covers
the window a brand-new team sits in, while it is still one click to fix. That
alert's rule is unchanged.

Team creation also gains its first post-insert extension point,
`tt_team_created` (`int $team_id`), fired by both the wizard's review step and
`POST /teams` — after the wizard's staff assignments, so a subscriber sees the
finished team. The create response carries `needs_head_coach` for a client that
renders its own confirmation.
