# A read-only observer can open one player's evaluations, not only export them all (#3644)

The Read-Only Observer could pull every team's evaluations, goals and player
lists into a spreadsheet and was refused the same data on a single player's
page. Both routes were asking whether the account may read the record, and they
were asking two different authorities: the exports resolve through the
capability matrix, the per-record path did not consult it at all.

It does now. A role whose access is granted purely by the authorization matrix —
the observer, and a scout with no team assignment, and any future persona in the
same position — resolves on the per-player routes as well as the bulk ones. The
fix is not specific to the observer seat, because the gap was not either.

Nothing widens on the write side: only read permissions are bridged, an account
with no matrix grant and no role assignment is refused exactly as before, and no
export's capability was narrowed.
