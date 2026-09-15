# Deleting an activity now cleans up after itself (#3376)

Three modules were listening for `tt_activity_deleted` and nothing ever
fired it, so none of the cleanup hung off it had ever run. A deleted
activity left its bound VCT session marked published against a row that no
longer existed — the coach could neither re-publish nor archive it — and
left the media attached to that activity linked to nothing, quietly
accumulating on every install that ever deleted one.

Both hard-delete paths now announce the delete: the wp-admin delete and the
recycle bin's purge / permanent delete. Archiving and trashing deliberately
stay silent, because those keep the row and restore has to work.

Two of the media module's cleanup subscriptions turned out to be registered
inside the media-retention tile, behind its enabled check, so on any academy
with retention switched off neither ran — a deleted player's media kept its
links and its bytes as well. They now register with the module itself.

Existing drift is not repaired retroactively; a VCT session already
orphaned by a past delete keeps its status until someone touches it.
