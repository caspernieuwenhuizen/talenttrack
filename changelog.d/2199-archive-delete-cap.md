# Activities: Archive is now delete-class, not edit-class (#2199)

Bump: patch

Archiving an activity is a soft delete, so it now requires the activities
create/delete capability rather than the edit capability. An assistant coach
who can only edit activities no longer sees the Archive (or Restore) button and
no longer hits a 403 on click; a head coach who can create/delete still does.
Both the detail-header buttons and the archive/restore REST routes gate on the
`activities:create_delete` matrix entity via the new
`tt_delete_activities → activities:create_delete` legacy-cap mapping — no new
matrix entity or seed migration.
