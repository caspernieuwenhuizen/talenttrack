# Evaluation detail, update and archive are scoped to the caller (#3566)

The single-evaluation routes now verify that the evaluation's player is within
the caller's scope. `GET /evaluations/{id}` checks the same pair the
`players/{id}/evaluations` route has always enforced, and the update and
archive routes check the row's existing player rather than only a submitted
one.
