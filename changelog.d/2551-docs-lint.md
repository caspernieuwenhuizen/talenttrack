no-user-facing-change

Adds `docs-lint.yml` + `tools/check-docs.php`: a CI gate over the documentation
corpus. It checks that every doc is either registered or explicitly dev-only,
that the front-matter keys resolve to real modules, features, tiers and
capabilities, that every routable screen is claimed by a help topic or listed
as deliberately unclaimed, that cross-references and deep links point at things
that exist, that reader-facing topics do not gain version stamps or issue
numbers, and that every file is valid UTF-8.

Fixed two broken cross-references the gate found on its first run:
`phone-home.md` linked to a doc that lives in another repository, and
`workflow-engine.md` linked to `sessions.md`, which was renamed to
`activities.md`.
