# Academy admins can switch individual export tiles off (#1762)

Bump: patch

Academy admins can now disable individual export tiles — for example to hide
the Audit log, the Full club-data backup, or Federation registration — from
the Modules management page, under the Export module. There's one toggle per
bulk export tile, all enabled by default, so nothing changes until one is
turned off. Disabling a tile both hides it from the Exports page (for everyone
in the academy, admins included) and rejects that export at the endpoint, so it
can't be run via a direct link either. Toggles are per-academy (club-scoped)
via FeatureRegistry and audit-logged; they only ever narrow access — a user
still needs the underlying capability to see an enabled tile.
