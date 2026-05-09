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
