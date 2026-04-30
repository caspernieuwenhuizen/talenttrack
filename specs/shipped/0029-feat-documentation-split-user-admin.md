<!-- type: feat -->

# #0029 — Documentation split (user / admin / dev)

## Problem

`docs/` mixes content for very different audiences. A coach trying to find "how do I record an evaluation" lands in the same doc tree as an admin trying to understand "how do I configure custom fields and capability gates," and a developer wanting REST + hook references has nothing dedicated to them. The result: every audience hits noise, the in-product `tt-docs` page surfaces irrelevant slugs, and translation effort is spread thin across pages of wildly different value-per-reader.

#0024 (Setup Wizard, shipped in v3.16.0) hands new admins off into docs at the end. Today that landing experience is jarring — admins still in setup mode get pointed at a flat list that includes coach-flavored task guides and developer-flavored architecture notes side by side.

## Proposal

Tag every `docs/*.md` file with an audience marker in its frontmatter. The on-disk structure stays flat (no folder reorg). The `tt-docs` page filters the index by the current user's role automatically. User and admin docs get translations; dev docs stay English-only.

Decisions locked during shaping (25 April 2026):

- **Flat folder, audience tags in frontmatter.** No `docs/user/`, `docs/admin/`, `docs/dev/` subfolders. Tags allow cross-cutting topics to declare two audiences without duplication.
- **Bundled migration: move + rewrite as one PR.** Every existing doc gets reclassified and copy-edited for its audience(s) in a single change. Locks in cleanly, single review pass.
- **Auto-filter by current user's role** in the in-product viewer index. Coach sees user docs; HoD sees user + admin; WP administrator sees user + admin + dev. Direct URL access is not role-restricted (docs are educational, not sensitive).
- **User + admin translated; dev English-only.** User and admin docs continue the existing `nl_NL.po`-style translation discipline. Dev docs (REST API, hooks, architecture) stay English — that's the working language for plugin extenders regardless of locale.

## Scope

### Audience marker format

Add a single HTML-comment marker at the top of each `docs/*.md` file, mirroring the `<!-- type: feat -->` pattern already used in idea/spec files:

```markdown
<!-- audience: user -->
<!-- audience: admin -->
<!-- audience: dev -->
<!-- audience: user, admin -->     # cross-cutting
```

Allowed values: `user`, `admin`, `dev`. Comma-separated for cross-cutting docs. Marker is required on every file under `docs/` (validation in CI: a file with no marker fails the docs lint).

### Audience-to-role mapping (for the auto-filter)

| Role / capability | Audiences shown in tt-docs index |
| --- | --- |
| `tt_player`, `tt_readonly_observer`, `tt_staff` | `user` |
| `tt_coach` | `user` |
| `tt_head_dev` | `user` + `admin` |
| WP `administrator` | `user` + `admin` + `dev` |

Multi-audience docs surface in any list whose role includes any of their tags. Logic: doc visible iff `intersect(doc.audiences, role.allowed_audiences) ≠ ∅`.

### Audience reclassification of existing docs (working draft)

Initial classification — locked during the migration PR after a per-file review pass:

| File | Audience |
| --- | --- |
| `getting-started.md` | user, admin (landing — both audiences need it) |
| `coach-dashboard.md` | user |
| `player-dashboard.md` | user |
| `evaluations.md` | user |
| `goals.md` | user |
| `sessions.md` | user |
| `printing-pdf.md` | user |
| `player-comparison.md` | user |
| `rate-cards.md` | user, admin |
| `reports.md` | user, admin |
| `bulk-actions.md` | user, admin |
| `teams-players.md` | admin |
| `people-staff.md` | admin |
| `eval-categories-weights.md` | admin |
| `custom-fields.md` | admin |
| `configuration-branding.md` | admin |
| `access-control.md` | admin |
| `usage-statistics.md` | admin |
| `migrations.md` | admin, dev |

This list is the starting point. The migration PR's first task is reading each file and confirming/adjusting per its actual content.

### Dev docs — what's new

`docs/` currently has no dev-tier content. Spec creates the initial dev tier with the following slugs (carved out from inline code comments + `DEVOPS.md` + `AGENTS.md`):

- `rest-api.md` — endpoint reference, payload shapes, auth scopes.
- `hooks-and-filters.md` — every `apply_filters` / `do_action` the plugin exposes, with intended use.
- `architecture.md` — module pattern, Kernel boot order, capability model, design tokens.
- `theme-integration.md` — when #0023 lands: how to override plugin tokens from a theme; the `body.tt-theme-inherit` contract.

Each is `<!-- audience: dev -->` and English-only.

### `tt-docs` page changes (DocumentationPage.php)

- Read each doc's frontmatter audience marker on index render.
- Determine current user's allowed audiences via the role mapping above.
- Filter the index list to docs whose audience set intersects the user's allowed set.
- Show a small badge per index row indicating the audience(s) — e.g. `[user]`, `[admin]`, `[user · admin]`, `[dev]`.
- Direct URL access (e.g. `?doc=custom-fields`) bypasses the index filter — anyone with access to the page can read any doc by slug. (No 403; docs aren't sensitive.) The page renders as today.

Caching: the audience parsing is cheap (one regex per file on index render). No new caching layer needed; if it ever becomes slow, cache the index in a transient keyed on `filemtime` of `docs/`.

### Migration PR — what gets done

The bundled migration PR includes:

1. **Audience markers** added to all 19 existing `docs/*.md` files plus their `docs/nl_NL/*.md` counterparts.
2. **Content rewrite** per file for its audience: drop admin jargon from user docs, drop coach-task language from admin docs, keep cross-cutting docs balanced. This is the bulk of the work.
3. **New dev docs** seeded (4 files listed above) — initial drafts derived from existing inline comments / DEVOPS.md / AGENTS.md.
4. **`docs/index.md`** rewritten as an audience-layered table of contents (all three audiences listed; the in-product viewer overlays the role filter).
5. **`DocumentationPage.php`** updated to parse audience markers and filter the index.
6. **CI lint**: a small PHP script (or addition to an existing CI step) that fails the build if any `docs/*.md` lacks an audience marker. Lives alongside the existing PHPStan workflow.
7. **`docs/nl_NL/`**: user + admin docs translated; dev docs not added to nl_NL (English-only by design).
8. **In-product link audit**: every tile/help-link that points to a doc slug is verified to land on a doc the linking user can see (per the role mapping). Adjust slugs if any cross-layer mismatches surface.

### Setup wizard hand-off

The existing wizard's "Done" page links to `getting-started.md`. After this spec:

- `getting-started.md` is `<!-- audience: user, admin -->` — visible to both wizard runners (typically WP admin or HoD) and ongoing coaches.
- The wizard's link target stays the same slug. No change needed there.

### Translation rules going forward

Update the existing memory rule "Update translations after every user-facing change" with a corollary:

- Doc with `audience: user` or `audience: admin` (or includes either) → translation in `docs/nl_NL/<slug>.md` required in the same PR.
- Doc with `audience: dev` only → no translation required.

Documented in [docs/contributing.md](../docs/contributing.md) (or wherever the existing translation discipline is stated).

## Out of scope (v1)

- **Per-locale audience filtering.** The audience filter is uniform across locales — a doc visible in English is visible in Dutch, gated by role, not language.
- **Frontmatter beyond audience.** No `category`, `since`, `last_updated`, etc. fields. If they prove useful later, that's a separate addition.
- **Doc search / full-text indexing.** Out of scope; the viewer remains slug-based navigation.
- **A separate "developer portal"** site (Astro/Hugo). Dev docs live in the same `docs/` tree, just with the `dev` tag.
- **Versioned docs** (e.g., docs for v3.x vs v4.x). Single tip-of-main docs only. Breaking changes get release notes, not parallel doc trees.
- **Rewriting `DEVOPS.md` / `AGENTS.md` / `CHANGES.md`.** Those stay where they are. The dev docs in `docs/` reference them but don't supersede them.

## Acceptance criteria

- [ ] **Audience marker** present on every `docs/*.md` file (English + nl_NL where applicable). CI lint fails the build on any unmarked file.
- [ ] **Role mapping** implemented in `DocumentationPage.php` per the table above.
- [ ] **Filtered index**: a user with `tt_coach` capability sees only docs whose audience set includes `user`. A `tt_head_dev` sees `user` + `admin`. A WP `administrator` sees all three.
- [ ] **Audience badges** visible on each index row.
- [ ] **Direct URL access**: any logged-in user with access to the docs page can load any doc by slug regardless of audience (no 403 / no hide).
- [ ] **Setup wizard hand-off**: clicking "Done" in #0024's wizard lands on `getting-started.md`, which renders cleanly for both WP admin and HoD audiences.
- [ ] **Dev docs seeded**: `rest-api.md`, `hooks-and-filters.md`, `architecture.md` exist with at least the structural skeleton and key entries (full content can iterate post-merge).
- [ ] **Translations**: every `user`- or `admin`-tagged doc has a corresponding `docs/nl_NL/<slug>.md` translation. Dev docs do NOT have nl_NL counterparts.
- [ ] **In-product link audit**: no tile or help link points at a doc the linking user cannot see (according to the role mapping).
- [ ] **No regression**: existing doc URLs continue to work; existing readers' bookmarks unaffected.
- [ ] **`docs/index.md` rewritten** as a layered table of contents.
- [ ] **`docs/contributing.md`** (or equivalent) documents the audience-marker rule and the per-audience translation requirement.

## Notes

### Why frontmatter tags over folders

Folders force a single home per file. Cross-cutting topics (rate cards, reports, getting started) genuinely belong in two audiences. Folders would either duplicate them (drift risk) or pick a side (excludes one audience from the index). Frontmatter handles cross-cutting cleanly with a comma-separated value, costs nothing in tooling, and keeps URLs flat.

### Why auto-filter by role over a dropdown

A coach scanning the docs page should not see admin-flavored slugs they can't act on. A dropdown defaults to "all" and asks every coach to filter manually — they won't. Auto-filter delivers the right list by default. Edge case (an HoD wanting to peek at dev docs) is handled by direct URL access; the dropdown is unnecessary.

### Sizing the rewrite

Each existing doc averages ~80-120 lines. Rewriting for audience clarity is mostly subtractive (cut admin jargon from user docs, cut coach-task language from admin docs) plus voice-leveling. Estimate 30-45 min per doc on average × 19 docs = 10-14 hours of rewrite work alone. Plus the structural work (markers, filter logic, lint, dev-doc skeletons, link audit). Total in the sizing table below.

### Touches

Existing:
- All `docs/*.md` files (19 EN + Dutch counterparts) — add markers + rewrite content.
- `docs/index.md` — rewrite as layered TOC.
- `src/Modules/Documentation/Admin/DocumentationPage.php` (or wherever the `tt-docs` page lives) — frontmatter parsing + role-based index filter + audience badge rendering.
- CI configuration — add audience-marker lint step.
- `languages/talenttrack-nl_NL.po` — small additions for new audience-badge UI strings.

New:
- `docs/rest-api.md` — dev tier.
- `docs/hooks-and-filters.md` — dev tier.
- `docs/architecture.md` — dev tier.
- `docs/theme-integration.md` — dev tier (placeholder; concrete content waits for #0023 to land).
- `docs/contributing.md` — if not already present, codifies the audience marker rule.

### Depends on

- **#0024 (Setup Wizard) — already shipped (v3.16.0).** No blocker; just consumes the wizard's hand-off target.
- Nothing else blocks this; can ship anytime.

### Blocks (or unblocks)

- **#0010 (multi-language FR/DE/ES)** — much easier to scope when the user-docs surface is cleanly separated from admin docs. Translating ~12 user docs to FR/DE/ES is tractable; translating 19 mixed-audience docs is not.
- **#0023 (styling options)** — `theme-integration.md` becomes the canonical dev-doc home for the override-token contract. Worth coordinating: when #0023 ships, fold its theme-integration documentation into this dev doc rather than ad-hoc docs/ entries.

### Sequence position

Phase 1 follow-on. Slot before #0010 so the multi-language work has a clean target. Independent of #0011 / #0026 / #0023 / #0027.

### Sizing

~17 hours:

| Work | Hours |
| --- | --- |
| Per-file audience reclassification + rewrite (19 docs × ~40 min avg) | 12.0 |
| nl_NL counterpart sync (rewrites carried to Dutch versions) | 1.5 |
| New dev docs — initial skeletons (rest-api, hooks-and-filters, architecture, theme-integration) | 1.5 |
| `DocumentationPage.php` frontmatter parsing + role filter + badges | 1.0 |
| CI lint for missing audience markers | 0.5 |
| `docs/index.md` rewrite + in-product link audit | 0.5 |
| **Total** | **~17h** |

Single PR (reviewable as one bundle) or split into structure + content audit if review burden is too high. Let the implementing engineer decide based on diff size at midpoint.

### Cross-references

- Idea origin: [`ideas/0029-feat-documentation-split-user-admin.md`](../ideas/0029-feat-documentation-split-user-admin.md).
- Adjacent: #0010 (multi-language), #0023 (styling options theme-integration doc), #0024 (setup wizard hand-off — already shipped).
