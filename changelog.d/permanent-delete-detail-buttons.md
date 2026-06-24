# Reachable "Delete permanently" on detail/editor pages (#1784 follow-up)

Bump: patch

The referential-integrity permanent delete now has a UI control on the
bespoke (non-list) management surfaces, not just the list views. Adds a
**Delete permanently** button to the trial-case detail page, the trial-track
editor, and each archived row in the VCT exercise library. All three reuse
the shared archive-button handler, so a blocked delete shows the same
"still referenced by …" reason on screen. Admin-gated (`tt_edit_settings`;
VCT: `tt_vct_admin_library`); built-in trial tracks stay non-deletable.

Surfaces without a management page of their own — test trainings
(create-only), custom widgets (no front-end view) and injuries (read-only
on the player timeline) — keep their delete at the REST/admin layer; a
dedicated UI for those is out of scope here.
