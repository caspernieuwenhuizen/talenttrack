Security: the single-evaluation routes now verify the evaluation's player is
within the caller's scope. `GET /evaluations/{id}` checks the same pair the
`players/{id}/evaluations` route has always enforced, and the update and
archive routes check the row's existing player, not only a submitted one.
