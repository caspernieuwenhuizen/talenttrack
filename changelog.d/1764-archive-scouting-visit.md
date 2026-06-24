# Archive a scouting visit from the UI (#1764)

Bump: patch

The scouting-visit detail view now has an **Archive visit** action. The
archive (soft-delete) capability already existed in the REST API
(`DELETE /scouting-visits/{id}`) but nothing surfaced it, so a visit could
never be cleared from the list. The button is shown to the visit owner (or
a scope admin), confirms before firing, calls the existing endpoint with a
nonce, and returns the user to the scouting-visits list with a "Scouting
visit archived." notice. No new business logic — the REST route already
enforced the capability and row-ownership check; this only wires it into
the UI.
