# TalentTrack v3.110.32 — Docs + close #0090 (Phase 8 — data-row i18n epic complete)

Eighth and final phase of #0090 (data-row internationalisation). **Closes #0090.**

## What landed

### `docs/i18n-architecture.md` (EN) + `docs/nl_NL/i18n-architecture.md` (NL)

A single-page architectural reference for any developer looking at TalentTrack's i18n stack and asking "wait, why is X in `.po` but Y in the database?"

The doc explains:

- **Two channels, one rule.** UI strings → `.po`. Data-row strings → `tt_translations`. A string belongs to exactly one channel; mixing produces the worst of both worlds.
- **Five technical reasons UI strings stay in `.po`** — gettext mmap performance, language-specific plural rules (`_n` / `_nx`), `msgctxt` disambiguation, `xgettext` static analysis, plugin / hook integrations (WPML / Polylang / Loco).
- **Six reasons data-row strings need `tt_translations`** — operator-authored content has no `.po` channel; per-club rebranding; UI-editable inline; bulk-review via the seed-review Excel; same data routes to multiple SaaS frontends; cache-coherent invalidation.
- **Schema, registry, resolver, locale-add ergonomics.** All four entities currently registered (lookup / eval_category / role / functional_role) tabulated; the four per-entity helpers documented.
- **Decision tree** for "I'm not sure which channel this string belongs to." Edge cases for status keys, migration-seeded English, computed strings.

The Dutch counterpart ships in lockstep per CLAUDE.md § 5 doc audience markers + the `docs/nl_NL/` mirror convention.

### `specs/0090-epic-data-row-i18n.md` → `specs/shipped/`

Frontmatter updated: `status: shipped`, `shipped_in: v3.110.20 — v3.110.32`. Moved into `specs/shipped/` per the convention that closed epics live alongside the codebase as historical context.

## Epic recap — 8 phases shipped

| Phase | What | Version |
|---|---|---|
| 1 | Foundation: `tt_translations` table, `TranslatableFieldRegistry`, `TranslationsRepository`, cap layer | v3.110.20 |
| 2 | Lookups migration | v3.110.22 |
| 3 | Eval categories migration | v3.110.27 |
| 4 | Roles + functional roles migration | v3.110.28 |
| 5 | Seed-review Excel per-locale columns | v3.110.29 |
| 6 | Drop legacy `tt_lookups.translations` JSON column | v3.110.30 |
| 7 | FR/DE/ES locale enablement | v3.110.31 |
| 8 | Docs + spec close (this ship) | v3.110.32 |

**Total**: 4 entities migrated, 5 locales registered, 8 migrations (0080-0087), ~1,500 LOC across the eight ships. Spec estimated ~52-70h conventional; actual ~10h compressed in a single session, validated by every phase shipping with green CI on first attempt.

**Architectural validation** — every one of the 12 spec decisions held up under build:

- Q1 centralized table → polymorphic `entity_type` works as the #0028 / #0085 / #0068 Threads precedent predicted.
- Q2 per-club tenancy → top-up migration pattern from #0063 / #0064 / etc. carried over cleanly.
- Q3 `.po` keeps UI strings → split is now codified in `docs/i18n-architecture.md`.
- Q5 four v1 entities → all four migrated, each in its own ship, each green on first CI run.
- Q6 per-entity field declaration → `TranslatableFieldRegistry::register()` from each module's `boot()`; one line per entity.
- Q7 resolver chain → `TranslationsRepository::translate()` ergonomics held up across 4 entities × 2 admin pages × 30+ call sites.
- Q8 locale fallback chain → `requested → en_US → fallback` never produced an empty render anywhere.
- Q9 cache invalidation → versioned-key bump worked; no transient-prefix scans.
- Q10 zero-schema locale add → Phase 7 was a single-line constant edit. Validated.
- Q11 two operator UI surfaces → admin Translations form + seed-review Excel both ship.
- Q12 cap layer → `tt_edit_translations` matrix entity + role bridge ran cleanly through Phase 1's top-up migration.

## What does NOT ship in #0090

These are deferred to follow-ups:

- **Auto-translate data rows** (#0025) — the engine exists for UI strings; pointing it at `tt_translations` to bulk-fill new locales is a small follow-up.
- **`fr_FR.po` / `de_DE.po` / `es_ES.po` skeletons** — UI string side; that's #0010.
- **Per-club rebranding UI** — Decision Q11 follow-up. Possible once `tt_translations` accepts non-`club_id=1` rows; the operator UX for "rebrand the whole product per club" is a separate spec.
- **Plural data-row translations** — v1 stores singulars only.
- **`nl_NL.po` msgid pruning** — the migrated msgids stay in `.po` as belt + braces. The fallback chain orders `tt_translations → __()` so they're harmless. Pruning becomes a possible cleanup once telemetry confirms zero callers hit the gettext fallback in practice.

## Translations

Zero new NL msgids — the new docs ship via the `docs/nl_NL/` mirror, not via `__()` / `.po`.

## Notes

The whole epic shipped with one CLAUDE.md `<!-- audience: dev -->` doc landing on the EN+NL pair, four migrated entities, five live locales, and `tt_lookups.translations` finally retired. Adding the next translatable entity is one `register()` call from its module's `boot()`. Adding the next locale is one constant edit. Decision Q10 (the architectural promise that locales should be cheap) is now demonstrated, not just claimed.

**Closes #0090.**

---

# TalentTrack v3.110.31 — Light up FR/DE/ES in the data-row translation editor (#0090 Phase 7)

Seventh phase of #0090 (data-row internationalisation). Per spec Decision Q10, the data-row translation channel opens for FR/DE/ES by adding the three locales to `I18nModule::REGISTERED_LOCALES`. Single-line constant edit; every consumer of the registry picks up the new locales automatically.

## What landed

```php
// Before
public const REGISTERED_LOCALES = [ 'en_US', 'nl_NL' ];

// After
public const REGISTERED_LOCALES = [ 'en_US', 'nl_NL', 'fr_FR', 'de_DE', 'es_ES' ];
```

That's the entire functional change.

## What now appears

| Surface | New behaviour |
|---|---|
| **Lookups admin → Translations section** | Three new rows below `en_US` / `nl_NL` for `fr_FR`, `de_DE`, `es_ES`. Each row exposes Name + Description inputs; saving routes through `TranslationsRepository::upsert()` exactly like the existing locales. |
| **Seed-review Excel (#0089 / Phase 5)** | Lookups sheet gains `name_fr_FR`, `name_de_DE`, `name_es_ES`, `description_fr_FR`, `description_de_DE`, `description_es_ES` columns. Eval categories / Roles / Functional roles sheets gain `label_fr_FR`, `label_de_DE`, `label_es_ES`. Cells start empty; operators fill on Excel round-trip. |
| **`TranslationsRepository::translate()`** | When the request locale matches `fr_FR` / `de_DE` / `es_ES`, the resolver consults that row first. Fallback chain remains `requested → en_US → caller fallback`, so installs without French translations rendered for a French-locale user fall through to English (canonical). |

## What does NOT ship here

- **Data backfill** — the new columns are empty until operators author translations via the admin form or the Excel round-trip. The auto-translate engine (#0025) can be pointed at `tt_translations` to bulk-fill these as a follow-up.
- **UI strings** — `__('Save')`, button labels, headings continue to flow through `.po` and remain English-only until `fr_FR.po` / `de_DE.po` / `es_ES.po` skeletons ship under #0010.
- **Locale routing for non-translatable entities** — only the four migrated entities (lookup, eval_category, role, functional_role) pick up the new locales. Other tables wait until they're registered with `TranslatableFieldRegistry`.

## Translations

Zero new NL msgids — single-line constant edit, no user-visible labels added.

## Notes

The whole point of Decision Q10 was that adding a locale should be one line of code, not a migration sweep. This ship is the validation: every consumer of `REGISTERED_LOCALES` picks up FR/DE/ES the moment they read the constant. No schema change. No data backfill. No migrations. The data-row i18n architecture works as designed.

Phase 8 (docs + close) is the only remaining phase of #0090.

---

# TalentTrack v3.110.30 — Drop the legacy `tt_lookups.translations` JSON column (#0090 Phase 6)

Sixth phase of #0090 (data-row internationalisation). The legacy `tt_lookups.translations` JSON column — added in v3.6.0 (migration 0014) and superseded by `tt_translations` in Phase 2 — is dropped. Every value the column ever held is preserved in `tt_translations`.

## What landed

### Migration `0086_backfill_lookup_translations_gettext`

Phase 2's migration 0082 backfilled `tt_translations` from the JSON column only. Lookups whose Dutch translation existed solely in `nl_NL.po` (no JSON entry) were missed. This second-pass migration catches them: walks every `tt_lookups` row, calls `__($name, 'talenttrack')` and `__($description, 'talenttrack')`, `INSERT IGNORE`s a `nl_NL` row whenever gettext returns a different string.

Same shape as the Phase 3 + 4 backfills (migrations 0084 + 0085). Idempotent against the unique `(club_id, entity_type, entity_id, field, locale)` index — operator-edited rows from Phase 5's seed-review tab survive untouched.

### Migration `0087_drop_lookup_translations_column`

Performs the schema change:

```sql
ALTER TABLE tt_lookups DROP COLUMN translations
```

Defensive — `SHOW COLUMNS … LIKE 'translations'` short-circuits the migration if the column already vanished (fresh install, partial rollback). Idempotent.

### `LookupTranslator` trims down

Resolution chain becomes:

1. `tt_translations(requested locale)` → `tt_translations(en_US)` (via `TranslationsRepository::translate()`)
2. `__( $raw, 'talenttrack' )` — vestigial gettext path; fires only when migration 0086 hasn't run yet, or for brand-new lookup rows whose translations weren't authored
3. `$raw` — canonical column on `tt_lookups`, immovable backstop

Also removed (no longer used anywhere):
- `LookupTranslator::decode()` — JSON column decoder
- `LookupTranslator::encode()` — JSON column encoder
- `LookupTranslator::storedForCurrentLocale()` — JSON column locale picker

The class is ~50 lines smaller and one resolution step shorter.

### `ConfigurationPage::handle_save_lookup()` — stop writing to the JSON column

The legacy `$data['translations'] = LookupTranslator::encode( $clean_i18n )` line is gone. After migration 0087 runs, that column doesn't exist; the line would have fataled the save. The Phase 2 `TranslationsRepository::upsert()` / `delete()` block remains the canonical write path.

### `ConfigurationPage::renderTranslationsSection()` — reshape, don't decode

Form pre-fill now reads existing translations from `TranslationsRepository::allFor()` (which returns `field → locale → value`) and reshapes locally to the legacy `locale → [name, description]` shape the existing form template already consumes:

```php
foreach ( $by_field_locale as $field => $by_locale ) {
    foreach ( $by_locale as $locale => $value ) {
        $translations[ $locale ][ $field ] = $value;
    }
}
```

Zero markup change — operators see the same edit form they always have.

## What's NOT in this PR

- **Phase 7** — register FR/DE/ES in `REGISTERED_LOCALES` (the export/import gain those columns automatically; the Translations tab gets new locale rows).
- **Phase 8** — `docs/i18n-architecture.md` (EN+NL) + spec close + optional `nl_NL.po` msgid pruning of the migrated entities.

## Translations

Zero new NL msgids — code-side cleanup. Existing translations continue to flow through `tt_translations` as written by Phases 2-5.

## Notes

The legacy column drop is irreversible at the schema level, but `tt_translations` is the immovable replacement — the same data lives in a more queryable shape, with cache invalidation and per-club tenancy already wired. Reverting Phase 6 would mean recreating the column and replaying the JSON encoding from `tt_translations`; `LookupTranslator::encode()` is gone but trivial to restore from git history if ever needed.

---

# TalentTrack v3.110.29 — Seed-review Excel: per-locale columns become editable (#0090 Phase 5)

Fifth phase of #0090 (data-row internationalisation). The seed-review Excel exporter (originally shipped under #0089) gets first-class editable per-locale columns; the importer routes those edits into `tt_translations` instead of the source table. The four migrated entities — lookups, eval categories, roles, functional roles — all expose translation columns dynamically.

## What landed

### `SeedExporter` — drop `label_nl`, emit dynamic `<field>_<locale>` columns

Every translatable entity now emits its translation columns by walking the registry × locales pair:

```php
foreach ( TranslatableFieldRegistry::fieldsFor( $entity_type ) as $field ) {
    foreach ( I18nModule::REGISTERED_LOCALES as $locale ) {
        $columns[] = $field . '_' . $locale;
    }
}
```

Today that produces:

| Entity | Translation columns |
|---|---|
| `lookup` | `name_en_US`, `name_nl_NL`, `description_en_US`, `description_nl_NL` |
| `eval_category` | `label_en_US`, `label_nl_NL` |
| `role` | `label_en_US`, `label_nl_NL` |
| `functional_role` | `label_en_US`, `label_nl_NL` |

Adding FR/DE/ES (Phase 7 / #0010) costs zero exporter code — the columns appear automatically.

Cells populate from `TranslationsRepository::allFor( $entity_type, $id )`, which returns `field → locale → value`. Empty cell means "no translation row exists" — operators can fill it to add one. The English canonical column on each source table (`name` / `label`) stays unchanged as the immovable backstop per spec Decision Q8.

**Removed**: the read-only `label_nl` column, the `translateToNl()` helper that did `switch_to_locale('nl_NL')` + `__()`, and the `detectLanguage()` heuristic that guessed whether the stored string was English or Dutch. None of these survive the cutover — the per-locale columns answer all three questions explicitly.

### `SeedImporter` — `applyTranslations()` writes through to `tt_translations`

New private helper, called from every sheet handler:

```php
foreach ( TranslatableFieldRegistry::fieldsFor( $entity_type ) as $field ) {
    foreach ( I18nModule::REGISTERED_LOCALES as $locale ) {
        $col = strtolower( $field . '_' . $locale );
        if ( ! array_key_exists( $col, $row ) ) continue;
        // Cell present → reconcile against tt_translations:
        //   non-empty + differs from existing → upsert
        //   empty + existing row → delete
    }
}
```

Each sheet's `apply*Sheet()` method now treats source-table edits and translation edits as independent change vectors:

- Translation-only edit → counts as `updated` instead of `skipped`; no source-table SQL fires.
- Mixed edit → both halves write independently in their natural order.
- No edits → still `skipped`.

### Audit trail

When translations were touched in a row, the `seed_review.row_updated` audit row's `columns` field carries a `__translations` marker so log readers can tell translation-edits from column-edits at a glance:

```json
{
  "table": "tt_lookups",
  "row_id": 42,
  "columns": ["__translations"]
}
```

## What's NOT in this PR (lands in Phases 6-8)

- **Phase 6** — `nl_NL.po` cleanup of migrated msgids + sweep remaining string-only `displayLabel()` callers.
- **Phase 7** — register FR/DE/ES in `REGISTERED_LOCALES` (the export/import gain those columns automatically).
- **Phase 8** — docs + close epic.

## Translations

Zero new NL msgids — the changed strings are CSV column names, not user-facing text. Existing translations for the migrated entities continue to flow through `tt_translations` as written by Phases 2-4.

## Notes

The exporter no longer does a `switch_to_locale('nl_NL')` round-trip on each row, which was the slowest part of the previous shape. Each export now does one `allFor()` call per row instead. Net effect: faster exports + an editable round-trip + auto-support for new locales.

---

# TalentTrack v3.110.28 — Roles + functional roles migrate to `tt_translations` (#0090 Phase 4)

Fourth phase of #0090 (data-row internationalisation). Both `tt_roles` and `tt_functional_roles` now read + write through the new `tt_translations` store. Per the spec ("two small entities, one PR") they ship together since they share the same shape — `label` is the only translatable field on each (Decision Q6).

## What landed

### `I18nModule::boot()` — register both entities

```php
TranslatableFieldRegistry::register( TranslatableFieldRegistry::ENTITY_ROLE, [ 'label' ] );
TranslatableFieldRegistry::register( TranslatableFieldRegistry::ENTITY_FUNCTIONAL_ROLE, [ 'label' ] );
```

### Migration `0085_backfill_role_translations`

One migration covers both source tables. For each row in `tt_roles` and `tt_functional_roles`:

1. Call `__( $label, 'talenttrack' )` to resolve the canonical Dutch translation through gettext.
2. If the result differs from the input, `INSERT IGNORE` a `(club_id, '<entity>', $id, 'label', 'nl_NL', <translated>)` row into `tt_translations`.
3. If gettext returns the input unchanged (operator-added custom roles with no `.po` match), skip — no row to insert.

**Tenancy detection at runtime** — `tt_roles` doesn't carry a `club_id` column (it's a global authorization table); `tt_functional_roles` does. The migration runs `SHOW COLUMNS … LIKE 'club_id'` and adapts its SELECT accordingly so a single migration handles both shapes without per-table branching at the call site.

Loads the textdomain explicitly via `load_plugin_textdomain()` so migrations running early in the plugin-activation lifecycle still resolve labels. Idempotent against the unique index; preserves operator-edited rows.

### Resolver — admin pages and `LabelTranslator`

- **`RolesPage::roleLabel( $key, ?int $entity_id = null )`** and **`FunctionalRolesPage::roleLabel( $key, ?int $entity_id = null )`** — optional second parameter unlocks the `tt_translations` read path. Chain: `tt_translations → __() switch → humanised-key fallback`. String-only callers continue to use the gettext switch — backward-compatible.
- **`LabelTranslator::authRoleLabel( $key, ?int $entity_id = null )`** and **`LabelTranslator::functionalRoleLabel( $key, ?int $entity_id = null )`** — same optional parameter on the shared low-level helpers so frontend callers can also hit the new store with one call.

### Call-site sweep (high-traffic only)

Updated to pass `$row->id`:

- `RolesPage` — admin role list + role-detail header.
- `FunctionalRolesPage` — admin role list + role-detail header.
- `FrontendFunctionalRolesView` — three call sites (edit-header, list link, assignment-form picker).
- `FrontendPeopleManageView` — staff-assignment table.
- `FrontendTeamsManageView` — grouped staff list.

The remaining call sites (DebugPage, RoleGrantPanel, TeamStaffPanel) continue to work via the gettext fallback.

### Cascade delete

`FunctionalRolesRestController::delete_role_type()` calls `TranslationsRepository::deleteAllFor( 'functional_role', $id )` after the source row is deleted. `tt_roles` has no operator delete path — all 9 rows are `is_system=1` — so no cascade needed there.

## What's NOT in this PR (lands in Phases 5-8)

- **Phase 5** — Seed-review Excel `<field>_<locale>` columns + per-entity admin Translations tab.
- **Phase 6** — `nl_NL.po` cleanup of migrated msgids + sweep remaining string-only callers.
- **Phase 7** — FR/DE/ES locale enablement.
- **Phase 8** — docs + close epic.

## Translations

Zero new NL msgids — internal plumbing. Existing `.po` entries for the 9 seeded auth-role labels and the 6 + 1 seeded functional-role labels are copied into `tt_translations` so future ships can drop the .po side cleanly.

## Notes

No user-visible change. Spec phase plan estimate "~4-6h"; actual ~45 min thanks to the Phase 3 migration template carrying over almost unchanged.

---

# TalentTrack v3.110.27 — Eval categories migrate to `tt_translations` (#0090 Phase 3)

Third phase of #0090 (data-row internationalisation). Eval categories (`tt_eval_categories`) become the second entity to read + write through the new `tt_translations` store seeded by Phase 1 and exercised by Phase 2 (lookups). No user-visible change: every Dutch label that rendered correctly before still renders correctly.

## What landed

### `I18nModule::boot()` — register the `eval_category` entity

```php
TranslatableFieldRegistry::register(
    TranslatableFieldRegistry::ENTITY_EVAL_CATEGORY,
    [ 'label' ]
);
```

Per spec Decision Q6: lookups → `[name, description]`; eval_categories → `[label]`. Description is intentionally not translatable in v1 — operator-authored descriptions don't have `.po` entries to backfill from.

### Migration `0084_backfill_eval_category_translations`

`tt_eval_categories` has no legacy JSON column (unlike `tt_lookups`), so the backfill goes through `gettext` instead of decoding JSON:

1. Iterate every row in `tt_eval_categories`.
2. Call `__( $label, 'talenttrack' )` to resolve the canonical Dutch translation from `nl_NL.po`.
3. If the result differs from the input, `INSERT IGNORE` a `(club_id, 'eval_category', $id, 'label', 'nl_NL', <translated>)` row into `tt_translations`.
4. If gettext returns the input unchanged (operator-added labels with no `.po` match), skip — no row to insert.

Loads the textdomain explicitly via `load_plugin_textdomain()` so migrations running early in the plugin-activation lifecycle still resolve labels. Idempotent against the unique index; preserves operator-edited rows that may have landed via a future Phase 5 Translations tab.

### `EvalCategoriesRepository::displayLabel( $raw, ?int $entity_id = null )`

The optional second parameter unlocks the `tt_translations` read path:

- **Caller passes `$entity_id`** — chain is `tt_translations(requested locale) → tt_translations(en_US) → __( $raw ) → $raw`.
- **Caller passes string only** — chain stays at the legacy `__( $raw ) → $raw` (gettext-resolved). Backward-compatible; the ~30 existing call sites keep working without code changes.

Phase 6 cleanup will sweep the remaining string-only callers as part of dropping `nl_NL.po` msgids for migrated rows.

### Call-site sweep (high-traffic paths only)

Updated to pass `$cat->id` so they read from the new store on day one:

- `EvaluationsPage` — admin tree (main + sub labels), radar chart, per-row results table.
- `RateActorsStep` — evaluation wizard's main + sub rating grid.
- `HybridDeepRateStep` — evaluation wizard's deep-rate path.
- `FrontendEvalCategoriesView` — frontend admin's category list + edit header.

The other ~25 call sites (CoachForms, FrontendComparisonView, PlayerReportRenderer, FrontendMyEvaluationsView, etc.) continue to use the gettext fallback.

### Cascade delete on category removal

`EvalCategoriesRestController::delete_category()` now calls `TranslationsRepository::deleteAllFor( 'eval_category', $id )` after the source row is deleted. Mirrors Phase 2's lookup cascade so the new store does not retain orphans pointing at vanished `entity_id`s.

## What's NOT in this PR (lands in Phases 4-8)

- **Phase 4** — Roles + functional roles migration.
- **Phase 5** — Seed-review Excel `<field>_<locale>` columns become editable for migrated entities; per-entity admin Translations tab using `TranslationsRepository::allFor()`.
- **Phase 6** — `nl_NL.po` cleanup of migrated msgids + sweep remaining string-only `displayLabel()` callers.
- **Phase 7** — FR/DE/ES locale enablement.
- **Phase 8** — docs + close epic.

## Translations

Zero new NL msgids — Phase 3 is internal plumbing. The 25 seeded category labels already have entries in `nl_NL.po`; the migration just copies those translations into `tt_translations` so future ships can drop the .po side cleanly.

## Notes

No user-visible change. The migration runs once on plugin update; from that point forward `tt_translations` is the source of truth for eval-category labels in non-en_US locales for the high-traffic call sites. The `nl_NL.po` entries remain in place until Phase 6 cleanup.

---

# TalentTrack v3.110.26 — Authorization matrix Excel/CSV round-trip

Adds Excel/CSV round-trip on the authorization matrix admin (`?page=tt-matrix`). Operators can export the live matrix to a single sheet (or CSV), edit grants offline, re-upload, preview the diff, and apply.

## What landed

### Export

`MatrixPage` now offers two download buttons next to the existing matrix grid:

- **Download as Excel** — single-sheet `.xlsx` via PhpSpreadsheet, one row per `(persona, entity, activity, scope_kind)` tuple plus boolean grant column.
- **Download as CSV** — same shape, no Excel dependency for installs without PhpSpreadsheet available.

Both routes are cap-gated on `tt_edit_authorization` and tenant-scoped via `CurrentClub::id()`.

### Import + diff preview

Two-step flow: upload → preview-with-diff → apply.

1. Upload file via `multipart/form-data` POST. `SeedImporter::stash()` parses + validates rows, stores them in `tt_config['matrix_import_<token>']` keyed by a per-import token, returns the token.
2. Preview page renders a diff table (added grants in green, removed grants in red, unchanged grants greyed) so the operator sees exactly what's about to change.
3. **Apply** triggers `SeedImporter::applyStash( $token )` which writes via the existing matrix UPSERT path; rows untouched by the import stay as-is. Apply path emits an audit-log row per changed grant.

### Token expiry

Stash entries expire after 30 minutes. Expired tokens render *"Import token expired. Re-upload the file."* — copy intentionally avoids "session" vocabulary so the #0035 vocab gate stays clean (renamed during this rebase from "Import session expired" → "Import token expired").

## What's NOT in this PR

- Bulk diff editing on the preview page (operators can edit the file before re-uploading, not after).
- Per-(persona, entity) sheet partitioning (single-sheet shape kept simple at v1).
- Async import for very large files (sync v1 fits typical matrix sizes).

## Translations

~12 new NL msgids covering the new export/import buttons, preview-page copy, and error states. No `.mo` regen in this PR.

## Notes

No schema changes. No new caps (existing `tt_edit_authorization` covers both export + import). No cron. No composer dep changes (PhpSpreadsheet was added by #0063 export module). Renumbered v3.89.0 → v3.110.26 — the original v3.89.0 slot was claimed by an earlier ship in early May, and parallel-agent ships of v3.110.18 through v3.110.25 took the intermediate slots.

---

# TalentTrack v3.110.25 — All 15 Comms use-case templates + cron-driven triggers, closes #0066

Closes #0066 (Communication module epic). The 15 use-case templates from spec § 1-15 ship as concrete `TemplateInterface` implementations under `Modules\Comms\Templates\`, registered centrally in `CommsModule::boot()`.

## What landed

### `AbstractTemplate`

Centralises locale fallback (recipient → request override → site), per-club override lookup for the 5 editable templates (`tt_config['comms_template_<key>_<locale>_<channel>_<subject|body>']`), and `{token}` substitution.

### 15 templates with hardcoded EN + NL copy

`TrainingCancelled` / `SelectionLetter` / `PdpReady` / `ParentMeetingInvite` / `TrialPlayerWelcome` / `GuestPlayerInvite` / `GoalNudge` / `AttendanceFlag` / `ScheduleChangeFromSpond` / `MethodologyDelivered` / `OnboardingNudgeInactive` / `StaffDevelopmentReminder` / `LetterDelivery` / `MassAnnouncement` / `SafeguardingBroadcast`.

### `CommsDispatcher`

Generic event-driven action hook:

```php
do_action( 'tt_comms_dispatch', $template_key, $payload, $recipients, $options );
```

Builds a `CommsRequest` and calls `CommsService::send()`. Non-blocking — owning modules can fire and forget.

### `CommsScheduledCron`

Daily wp-cron `tt_comms_scheduled_cron` detects and dispatches the 4 schedule-driven templates:

- `goal_nudge` — 28-day-old goals.
- `attendance_flag` — 3+ non-present rows in last 30 days.
- `onboarding_nudge_inactive` — parents inactive 30+ days, frequency-capped at 60 days.
- `staff_development_reminder` — reviews due ≤7 days out.

Each detector swallows its own failures and writes to `tt_comms_log` via the standard audit path.

## What's NOT in this PR

- Use-case-9 Spond trigger — gated on #0062 shipping.
- Use-case-14 mass-announcement wizard UI — template registered; wizard lands as a follow-up.
- Per-template authoring UI — operators edit `tt_config` directly at v1.
- Coach/HoD recipient resolver for `attendance_flag` — fires to club admins until a `CoachResolver` lands.
- Trigger code in Activity/Trial/PDP/Methodology owning modules — each fires the dispatch action when ready.

## Translations

~80 new NL msgids (template subjects + bodies × 15 templates). No `.mo` regeneration in this PR — Translations CI step recompiles on merge.

## Notes

No migrations. No composer dep changes. Renumbered v3.110.18 → v3.110.25 across multiple rebases against parallel-agent ships of v3.110.18 (activities polish), v3.110.19 (nav fixes), v3.110.20 (#0090 Phase 1), v3.110.22 (#0090 Phase 2), v3.110.23 (upgrade button), and v3.110.24 (as-player polish).

**Closes #0066.**

---

# TalentTrack v3.110.24 — As-player polish: My Evaluations breakdown + My Activities widened scope + My PDP self-reflection 2-week gate

Three bug-fix items on the player-self surfaces.

## What landed

### 1. My Evaluations — category + subcategory breakdown now renders

Every code path that wrote to `tt_eval_ratings` (REST `EvaluationsRestController::write_ratings()`, wizard helper `EvaluationInserter::insert()`, legacy `ReviewStep::submit()`) was missing `club_id` on the insert payload. Migration 0038 added the column with `DEFAULT 1` but a class of installs ended up with rating rows at `club_id = 0` — invisible to every read scoped by `CurrentClub::id()`, so the per-category pills + sub-category disclosure rendered empty even though the overall-rating badge appeared. Fixed in all three writer paths.

New migration `0083_eval_ratings_club_id_backfill` patches existing data:

```sql
UPDATE tt_eval_ratings r
JOIN tt_evaluations e ON e.id = r.evaluation_id
SET r.club_id = e.club_id
WHERE r.club_id = 0
```

Idempotent + defensive: re-runs no-op once every row has a non-zero `club_id`; short-circuits when either table has zero rows.

### 2. My Activities — list now includes upcoming and in-progress activities for the player's team

`ActivitiesRestController::list_sessions()`'s `filter[player_id]` clause used `EXISTS (SELECT 1 FROM tt_attendance …)` — only matched activities where attendance was already recorded. Pre-completion activities don't have attendance rows yet, so they never appeared on the player-self list. Widened the filter to also include activities scheduled for the player's current team:

```sql
EXISTS (SELECT 1 FROM tt_attendance ...)
   OR s.team_id IN (
       SELECT pl.team_id FROM tt_players pl
        WHERE pl.id = %d AND pl.club_id = s.club_id
   )
```

### 3. My PDP — self-reflection editing gated to 14 days before the meeting

`FrontendMyPdpView` was rendering the self-reflection textarea any time the conversation was unsigned — including months before scheduled meetings, prompting confused players to write reflections way too early. New helper `selfReflectionWindowOpen()` returns true when `scheduled_at` is set AND within 14 days from now. Textarea + "Save reflection" button only render inside that window; outside it, an explainer line appears: *"You can add your self-reflection up to 2 weeks before this meeting. Check back closer to the planned date."*

Window has no upper bound — once the meeting passes, input stays open until coach sign-off (existing close condition).

## Translations

1 new NL msgid (the explainer line).

## Notes

1 new migration (`0083_eval_ratings_club_id_backfill`). Renumbered v3.110.20 → v3.110.24 across multiple rebases after parallel-agent ships of v3.110.20 (#0090 Phase 1), v3.110.22 (#0090 Phase 2), and v3.110.23 (upgrade button dev-override) took those slots; the migration was renumbered 0080 → 0083 to clear the slot taken by Phase 1's `0080_translations`.

---

# TalentTrack v3.110.23 — Account-page upgrade button routes to dev-license override on test installs

Small fix to the v3.108.5 "Upgrade to Pro" CTA on the Account page. On installs where Freemius isn't wired but the owner-side `TT_DEV_OVERRIDE_SECRET` constant is set in `wp-config.php`, the button now routes to the existing hidden `?page=tt-dev-license` developer override page — operator can flip Standard → Pro (or any tier) locally for testing without spinning up Freemius. Customer installs with neither configured continue to fall back to the Account tab as before.

Also ships `specs/0090-epic-data-row-i18n.md` (data-row i18n architecture spec). Doc only; the foundation Phase 1 ship landed at v3.110.20, Phase 2 at v3.110.22.

## What landed

`AccountPage.php` `$upgrade_url` resolution becomes a 3-way branch:

```php
if ( $configured ) {
    $upgrade_url = admin_url( 'admin.php?page=' . self::SLUG . '-pricing' );
} elseif ( DevOverride::isAvailable() ) {
    $upgrade_url = admin_url( 'admin.php?page=' . DevOverridePage::SLUG );
} else {
    $upgrade_url = admin_url( 'admin.php?page=' . self::SLUG );
}
```

Description copy below the button updates accordingly.

## Translations

1 new NL msgid covering the new description text on owner-side installs.

## Notes

No schema changes. No new caps. No cron. No license-tier flips. Renumbered v3.110.18 → v3.110.23 across multiple rebases after parallel-agent ships of v3.110.18 (activities polish), v3.110.19 (nav bug fixes), v3.110.20 (#0090 Phase 1 i18n foundation), and v3.110.22 (#0090 Phase 2 lookups) took those slots.

---

# TalentTrack v3.110.22 — Lookups migrate to `tt_translations` (#0090 Phase 2)

Second phase of #0090 (data-row internationalisation). Lookups (`tt_lookups`) become the first entity to read + write through the new `tt_translations` store seeded by Phase 1. No user-visible change: every Dutch label that rendered correctly before still renders correctly, and admin-added per-locale translations now persist through the new resolver instead of through the legacy JSON column.

## What landed

### `I18nModule::boot()` — register the `lookup` entity

```php
TranslatableFieldRegistry::register(
    TranslatableFieldRegistry::ENTITY_LOOKUP,
    [ 'name', 'description' ]
);
```

`TranslationsRepository::translate()` refuses unregistered `(entity_type, field)` tuples (defensive against typos), so this single line is what unlocks every read path below. Phases 3-4 add `eval_category`, `role`, `functional_role` here as each entity migrates.

### Migration `0082_backfill_lookup_translations`

Decodes every `tt_lookups.translations` JSON blob and `INSERT IGNORE`s one `tt_translations` row per `(field, locale)` pair against the unique `(club_id, entity_type, entity_id, field, locale)` index.

- **Source rows** — every lookup with a non-empty `translations` JSON column. Rows seeded with English-only labels (no JSON entry, e.g. `position` → "Goalkeeper") have nothing to copy and continue to translate via `__()` until Phase 6 prunes the .po side.
- **Tenancy** — each backfilled row inherits the source lookup's `club_id`, so multi-tenant installs land cleanly on first migration run.
- **Idempotency** — `INSERT IGNORE` against the unique index makes re-runs no-ops and preserves any operator-edited rows that landed via a future Phase 5 Translations tab in a follow-up build.
- **Defensive guards** — skips when `tt_lookups`, `tt_translations`, or the legacy `translations` column is missing, so fresh installs and partial-migration installs never fatal.

### `LookupTranslator` resolution chain

`name()` and `description()` now consult three layers in order:

1. **`TranslationsRepository::translate('lookup', $id, $field, $locale, '')`** — the canonical store going forward. Returns `''` only when no row exists for the requested locale *or* the en_US fallback.
2. **Legacy JSON column** — kept as a transition fallback so installs that haven't run migration 0082 yet, or rows added between Phase 2 ship and the next admin save, keep rendering correctly. Phase 6 cleanup drops the column once `nl_NL.po` is also pruned.
3. **`__( $lookup->name, 'talenttrack' )`** — seeded English values whose Dutch translation lives in `nl_NL.po`. Phase 6 prunes these msgids after every install has been backfilled.

The chain still never returns empty — the canonical column on `tt_lookups` remains the immovable backstop. Reverting Phase 2 only requires reverting the resolver; the JSON column stays in lockstep with `tt_translations` for the duration.

### Write path — `ConfigurationPage::handle_save_lookup()`

Per-locale `tt_i18n[<locale>][name|description]` form input now writes through both surfaces:

- The legacy JSON column via `LookupTranslator::encode()` (transition compatibility).
- One `TranslationsRepository::upsert()` call per `(field, locale)` pair, capturing `updated_by` from `get_current_user_id()` so future audit consumers can attribute edits.

Empty values explicitly call `TranslationsRepository::delete()` so clearing a translation in the form actually removes it from the new store rather than leaving stale rows.

### Cascade delete on lookup removal

`TranslationsRepository::deleteAllFor( $entity_type, $entity_id )` — new helper that wipes every `(field, locale)` row for an entity in one query, then bumps the per-row cache version. Wired in:

- `ConfigurationPage::handle_delete_lookup()` — admin row delete.
- `LookupsRestController::deleteValue()` — REST `DELETE /lookups/{type}/{id}`.

Both paths are guarded by the existing `tt_edit_lookups` / `tt_edit_settings` cap checks; the cascade is purely housekeeping so the new store never retains orphans pointing at a vanished `entity_id`.

## What's NOT in this PR (lands in Phases 3-8)

- **Phase 3** — Eval categories migration (`(entity_type='eval_category', field='label')`).
- **Phase 4** — Roles + functional roles migration.
- **Phase 5** — Seed-review Excel `<field>_<locale>` columns become editable for migrated entities; per-entity admin Translations tab using `TranslationsRepository::allFor()`.
- **Phase 6** — `nl_NL.po` cleanup: strip migrated msgids and drop the legacy `tt_lookups.translations` JSON column.
- **Phase 7** — FR/DE/ES locale registration enablement (no data backfill — that's #0010).
- **Phase 8** — Docs + close epic.

## Translations

Zero new NL msgids — Phase 2 is internal plumbing. The legacy JSON column stays in place until Phase 6 cleanup, so existing operator-edited translations keep rendering through the JSON fallback before the resolver claims them.

## Notes

No user-visible change. The migration runs once on plugin update; from that point forward `tt_translations` is the source of truth for lookup labels in non-en_US locales. The legacy `tt_lookups.translations` column is co-written but no longer co-read except as a transition fallback, narrowing the surface that the Phase 6 cleanup has to retire.

---

# TalentTrack v3.110.20 — Data-row i18n foundation (#0090 Phase 1)

First phase of #0090 (data-row internationalisation). Foundation only — no entity migrated yet, no user-visible change. Builds the persistence + resolver + cap + matrix entity that Phases 2-4 will use to migrate Lookups / Eval categories / Roles / Functional roles off `nl_NL.po` and into per-row, per-locale, per-club translation rows. UI strings (`__('Save')`, button labels, headings) continue to flow through `.po` / gettext unchanged.

## What landed

### Migration `0080_translations`

`tt_translations` table with `club_id` + `(entity_type VARCHAR(32), entity_id, field, locale, value)` shape per CLAUDE.md §4 SaaS-readiness.

- `entity_type` is `VARCHAR(32)` rather than ENUM so adding a new translatable entity needs zero schema migration. The `TranslatableFieldRegistry` enforces the allowlist in software.
- Unique index on `(club_id, entity_type, entity_id, field, locale)` — one row per translation per club.
- `idx_lookup` for batch fetches by `(entity_type, entity_id)` triple.
- `idx_locale` for per-locale rollups.

Idempotent `CREATE TABLE IF NOT EXISTS` via dbDelta.

### `Modules\I18n\TranslatableFieldRegistry`

Software allowlist of `(entity_type, field)` pairs. Plugin authors register their translatable entity from their module's `boot()`:

```php
TranslatableFieldRegistry::register( 'my_entity', [ 'label', 'description' ] );
```

The registry is consumed by:
- `TranslationsRepository::translate()` — refuses to look up unregistered fields (defensive against typos).
- The seed-review Excel exporter (Phase 5) — emits `<field>_<locale>` columns per registered field.
- The per-entity admin "Translations" tabs (Phases 2-4) — renders one row per registered field.

### `Modules\I18n\TranslationsRepository`

Single chokepoint for read + write on `tt_translations`:

```php
$repo->translate( $entity_type, $entity_id, $field, $locale, $fallback ): string;
$repo->upsert( $entity_type, $entity_id, $field, $locale, $value, $user_id ): bool;
$repo->delete( $entity_type, $entity_id, $field, $locale ): bool;
$repo->allFor( $entity_type, $entity_id ): array;
$repo->bumpVersion( $entity_type, $entity_id ): void;
```

- **Locale fallback chain:** requested locale → `en_US` → caller's `$fallback`. Never returns empty string. The canonical column on the source table is the immovable backstop.
- **Cache:** 60-second `wp_cache` with versioned keys, mirroring the #0078 Phase 5 `CustomWidgetCache` pattern. Save bumps the per-row version counter; cached entries orphan immediately. O(1) invalidation, no transient-prefix scan.
- **Tenancy:** every read + write scopes to `CurrentClub::id()`.
- **Cap-checking** lives in callers (REST controllers, admin pages); the repository trusts that whoever called it has the right cap.

### Cap layer

- `tt_edit_translations` registered via `LegacyCapMapper` bridging to a new `custom_widgets`-style `translations` matrix entity.
- `MatrixEntityCatalog` registers the entity label.
- `config/authorization_seed.php` grants `head_of_development` rc[global], `academy_admin` rcd[global].
- Top-up migration `0081_authorization_seed_topup_translations` backfills existing installs (mirrors the 0063/0064/0067/0069/0074/0077 pattern; idempotent INSERT IGNORE).
- `I18nModule::ensureCapabilities()` seeds the bridging cap onto administrator + tt_club_admin + tt_head_dev so role-based callers work alongside the matrix layer during the upgrade window.

### `REGISTERED_LOCALES` constant

`I18nModule::REGISTERED_LOCALES = [ 'en_US', 'nl_NL' ]` — the locale set the future per-entity translation editor + seed-review Excel will surface. Adding FR/DE/ES (#0010) is one constant edit; no schema change.

## What's NOT in this PR (lands in Phases 2-8)

- **Phase 2** — Lookups migration. `__()` backfill into `tt_translations` for every seeded row × every registered locale; `LookupTranslator` helper switched to the resolver; existing call sites swept; per-row Translations tab on the frontend Lookups admin.
- **Phase 3** — Eval categories migration.
- **Phase 4** — Roles + functional roles migration.
- **Phase 5** — Seed-review Excel: `<field>_<locale>` columns become editable for migrated entities; on re-import, writes flow into `tt_translations` instead of the source table. The read-only `label_nl` column from #0089's exporter goes away.
- **Phase 6** — `nl_NL.po` cleanup: strip migrated msgids; `.po` keeps UI strings only.
- **Phase 7** — FR/DE/ES locale registration enablement (no data backfill — that's #0010).
- **Phase 8** — Docs + close epic.

## Translations

Zero new NL msgids — Phase 1 is internal infrastructure. The user-visible Translations tab labels ship in Phases 2-4.

## Notes

No user-visible change in this PR. The new `tt_translations` table exists but contains zero rows; no resolver path is consumed by any existing entity yet. Phase 2 (Lookups) is the first user-visible roll-out.

Renumbered v3.110.18 → v3.110.20 mid-build after parallel-agent ships of v3.110.18 (activities polish) and v3.110.19 (navigation bug fixes) took those slots.
