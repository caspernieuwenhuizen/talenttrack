# Head coaches connect their own team's Spond account (#2388)

Bump: minor

A head coach can now link their team's own Spond account themselves, from a
**Spond connection** action on the team's page — save the team email +
password, test the login, and trigger a sync — without waiting for an
academy admin. Previously only an admin could connect Spond, on the
club-wide page.

Access is scoped to the exact team via change authority on the
`spond_integration` matrix entity (admin globally, head coach for their own
team). This also **closes a scoping hole**: the per-team Spond credential
endpoints previously gated on the any-team `tt_edit_spond_credentials`
capability, which let a head coach write another team's credentials; they
now require change authority on that specific team, and the affordance is
hidden for anyone without it.
