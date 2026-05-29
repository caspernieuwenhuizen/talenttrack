# TalentTrack v4.11.0 — Lookup admin rework (closes #985)

Bundles four related pilot fixes on the Configuration → lookup-category admin surface into one ship because they all touch the same files. Faithful port of `.local-mockups/lookup-admin/index.html`. The umbrella issue's six open questions were locked 2026-05-29 — this ship implements those decisions, not the questions in the original body.

## Friction the rework addresses

| # | Friction in v4.10.x baseline | Redesign response |
|---|---|---|
| 1 | Most lookup rows have no `tt_translations` entries for the supported locales; operator opens a row and sees only the canonical English value with empty translation fields | Migration 0131 backfills `tt_translations` for every existing row across en_US + nl_NL + de_DE + es_ES + fr_FR (en_US from the canonical `name` column; other locales from the loaded .po) |
| 2 | Save button on the lookup edit form did not trigger a network request, did not show an error, did not reload | The inline IIFE has been extracted into `assets/js/components/lookup-admin.js`; the new module owns the submit listener, POSTs on add and PUTs on edit, surfaces server errors in `[data-tt-lkp-msg]` |
| 3 | Opening a tile dropped the operator straight into a half-rendered Add-new form on the right pane; they wanted to scan the list first | List-first layout: the default view is a clean list of values, `+ Add value` opens an empty form, clicking a row opens the form populated with that row's data and translations |
| 4 | On a Dutch install ~half the Configuration tile labels still rendered English because the msgids existed but msgstrs were empty in `nl_NL.po` | Separate `chore(i18n)` commit backfills the missing msgstrs for the ~12 tile labels added in v3.110.201 + v3.110.205-213; same backfill for de_DE / es_ES / fr_FR |

## Locked decisions (open questions 2026-05-29)

| # | Question | Decision |
|---|---|---|
| 1 | Mobile coverage dots | Shrink to a smaller pill; do not hide |
| 2 | Coverage dot order | Site-locale first, remaining locales follow |
| 3 | `/translations/preview` shape | Single bulk call returning all locales in one response (existing endpoint already does this) |
| 4 | Internal-key edit on existing rows | Disable entirely — once a row is created its `name` is immutable from the admin UI |
| 5 | Coverage dot meaning | "Name set" only — description is optional and does not gate the dot |
| 6 | Locked rows | Hide delete only; keep edit click-through |

## What ships

**PHP** — `src/Shared/Frontend/FrontendConfigurationView.php`

- `renderLookupCategoryEditor()` rewritten end-to-end. The old master-detail markup, the inline IIFE, and the inline `masterDetailStyles()` block are deleted. The new function enqueues `assets/css/frontend-lookup-admin.css` + `assets/js/components/lookup-admin.js`, builds a JS config blob (rest_base, nonce, locales, i18n), and delegates rendering to three new helpers: `renderLookupListView()`, `renderLookupFormViews()`, `renderLookupForm()`. The form views are still emitted server-side so a `?edit=N` deep-link still works.
- `translationTargets()` now returns all five locales **including `en_US`**. Order: site locale first per Q2, then `en_US`, then the remaining installed locales in stable order. The historical `en_US` skip is gone — the `name` column is now framed as an immutable internal key, the canonical English display value lives in `tt_translations`.

**PHP** — `src/Infrastructure/REST/LookupsRestController.php`

- `persistTranslations()` accepts `en_US` in the request body. Same `TranslationsRepository::upsert()` chokepoint, same field allowlist, same cap gate.

**Schema** — `database/migrations/0131_lookup_translation_seeds.php`

- Idempotent. Backfills `tt_translations` rows for every existing `tt_lookups` row x every supported locale via `INSERT IGNORE` against the unique `(club_id, entity_type, entity_id, field, locale)` index.
- en_US is seeded from `tt_lookups.name` (and `.description` where present). Other locales follow migration 0109's per-locale unload + reload pattern to read `__( $name )` against the right loaded textdomain.

**CSS** — `assets/css/frontend-lookup-admin.css` (new)

- Mobile-first per CLAUDE.md §2. Scoped to `.tt-lkp-admin` so the rules never leak. Base styles target 360px; 480px and 640px breakpoints scale up.
- Coverage-dot pill shrinks but stays visible on mobile (Q1). Every interactive target ≥ 48×48 with 8px spacing between adjacent targets. Honours `prefers-reduced-motion`.

**JS** — `assets/js/components/lookup-admin.js` (new)

- Vanilla JS, no framework. Reads its config from `data-tt-lkp-config` (a JSON blob written by the PHP renderer) so there is no globals leak.
- Owns view switching, row population, save, delete, translate-from-source, and live coverage-dot repainting.
- Internal-key input is forced `readonly disabled` on edit per Q4 — no confirm modal needed because the affordance is gone.

**i18n** — `languages/talenttrack-{nl_NL,de_DE,es_ES,fr_FR}.po`

- Separate `chore(i18n)` commit (per CLAUDE.md ship-along rule). ~12 Configuration tile labels added in v3.110.201 + v3.110.205-213 had empty msgstrs in `nl_NL.po`; backfilled with Dutch translations consistent with the existing vocabulary. The de_DE / es_ES / fr_FR files receive parallel backfills.

## Backend untouched

Same `LookupsRestController` endpoints + same routes + same cap gates. Same `tt_lookups` schema. Same `tt_translations` schema. Same `DragReorder` wp-admin endpoint for sort-order persistence. Same `?tt_view=configuration&config_sub=lookups&category=<slug>` URL contract; existing deep-links still resolve.

## Mobile-first per CLAUDE.md §2

Base CSS at 360px viewport. 480px and 640px breakpoints scale up to the desktop list+form layout. Coverage-dot pill shrinks on mobile but stays visible (Q1). Tap targets ≥ 48×48; numeric inputs carry `inputmode`; no hover-only functionality. The new JS module respects keyboard navigation (Enter / Space on a focused row triggers edit, Tab order leads Cancel → Save).

Minor bump — operator-visible surface rework + new schema seed migration.

Closes #985.
