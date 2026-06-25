# Archive lifecycle for activities (#1555)

Bump: minor

Activities now follow the same archive lifecycle as players, teams, evaluations
and goals. Deleting an activity soft-archives it instead of removing the row, so
its attendance and history are preserved. The activities list gains an
**Active · Archived · All** status control: the **Archived** view lists archived
activities with a **Restore** button and, for admins, a **Delete permanently**
button. Permanent deletion is gated behind the *edit settings* capability and is
blocked while the activity still has attached records, so nothing is erased by
accident. New REST routes back the flow: `POST /activities/{id}/restore` and
`DELETE /activities/{id}/permanent`.
