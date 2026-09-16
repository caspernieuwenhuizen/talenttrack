# A VCT session no longer stays published when its activity is purged (#3426)

Deleting an activity out of the recycle bin (or through
`DELETE /activities/{id}/permanent`) left the session that was planned on it
unbound but still marked published, which a coach met as a session they could
neither re-publish nor archive. The wp-admin delete already reverted it; the
purge did not, because the cascade clears the session's link to the activity
as part of the delete, so by the time the modules were told the activity was
gone there was nothing left to look up.

The binding is now read before the delete runs and handed to the modules
afterwards, so the session ends up unbound and back in draft on every delete
path. The delete itself is unchanged — it is still announced only after the
rows are actually gone, so a refused delete never reaches a cleanup handler.
