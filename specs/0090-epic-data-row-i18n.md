---
id: 0090
type: epic
status: ready
title: Data-row i18n — centralized tt_translations table for translatable seed + operator-authored data
shipped_in: ~
---

# 0090 — Data-row i18n

Today TalentTrack runs translation through a single channel: `nl_NL.po` resolved by gettext at request time. That works for UI strings (`__('Save')`, button labels, headings) but breaks down for two cases:

1. **Seed-row strings shipped in code** — lookup labels (*Present / Absent / Late*), eval-category names (*Technical / Tactical / Physical / Mental*), role labels, functional-role labels. These survive in `.po` today but the channel is awkward: operators can't edit translations from the UI without a `.po` round-trip, and `.po` becomes an oddly-shaped index of "data plus UI" strings mixed together.

2. **Operator-authored seed-row strings** — when an operator adds a new lookup row via the frontend Lookups admin, the row exists only in the language they typed it in. There is no translation channel at all. As FR/DE/ES (#0010) ramps up and the install becomes multi-tenant (CLAUDE.md §4), this gap becomes structural.

The fix: a single `tt_translations` table that holds per-(entity, field, locale) translations for data rows, with operator-facing edit UX, and a clear split from `.po` (which keeps UI strings only).

## Decisions

All twelve architectural forks resolved. Repeated here so future readers see the locked answer alongside its reasoning.

| # | Question | Decision |
|---|---|---|
| 1 | Schema shape | **Centralized `tt_translations`** keyed on `(entity_type, entity_id, field, locale, club_id)` over per-entity `_i18n` tables. Adding a new translatable entity is zero migrations — register the entity_type constant in code, declare the translatable fields, done. Lost FK integrity (entity_type is a string) is paid for in software via a `TranslatableField` registry that static analysis can verify. Matches the polymorphic-Threads precedent (#0028, #0085, #0068 Phase 3) the codebase has already validated three times. |
| 2 | Multi-tenant scope | **Per-club**. `tt_translations.club_id NOT NULL DEFAULT 1` per CLAUDE.md §4. Each club has its own translation set; the `.po` backfill writes `club_id=1` rows for the existing single tenant. When a second tenant onboards, a top-up migration (same pattern as 0063 / 0064 / 0067 / 0069 / 0074 / 0077) writes the .po backfill into their `club_id` at activation. |
| 3 | `.po` deprecation | **`.po` keeps UI strings only after backfill.** The data-row strings move out. Two clear channels emerge: `__('Save')` → `.po`; lookup-row label → `tt_translations`. Loco/Poedit workflow continues unchanged for the UI-string subset. C2 ("dual-channel resolver with .po fallback") was rejected because it preserves the very ambiguity this epic exists to remove. |
| 4 | Why UI strings stay in `.po` | **Five technical reasons** that survive even after dev-cost is set aside: (1) gettext mmaps `.mo` once per request, zero per-call query cost; (2) language-specific plural rules (`_n` / `_nx`) — Polish 4 forms, Russian 3, Arabic 6 — are baked into the `.po` format; (3) context disambiguation via `_x()` / `_nx()`; (4) `xgettext` static analysis catches missing translations automatically; (5) the `gettext` / `gettext_with_context` filters allow plugin / hook integrations (WPML, Polylang) which bypass DB-resolved strings entirely. The smell test: 20 years of WP plugins haven't moved UI strings to DB tables — there's a reason. |
| 5 | Translatable entities (v1) | **Lookups, eval categories, roles, functional roles.** All four are seed-shipped + operator-extensible, all four are surfaced in the seed-review Excel today. Other `tt_*` tables (positions, principles, formation templates, league names) follow per phase based on operator demand. |
| 6 | Translatable fields per entity | **Per-entity declaration in PHP** via `Modules\I18n\TranslatableFieldRegistry::register( $entity_type, $fields )`. v1: lookups → [`name`, `description`]; eval_categories → [`label`]; roles → [`label`]; functional_roles → [`label`]. The registry is the single source of truth for which fields are translatable; the seed-review Excel + the per-entity admin both consume it. |
| 7 | Read-path resolver | **`TranslationsRepository::translate( $entity_type, $entity_id, $field, $locale, $fallback )`** returns the row's translation if one exists, else the canonical column value (`$fallback`). 60-second wp_cache (group `tt_translations`, key per-`(entity_type, entity_id)` triple, multi-field). Resolver is the public API; per-entity helpers (e.g. `LookupTranslator::label( $row )`) wrap it for ergonomic call sites. |
| 8 | Locale fallback chain | **Requested locale → `en_US` → canonical column value.** Never fail with empty string; always return *something* readable. The canonical column on the source table is the immovable backstop. The `en_US` row in `tt_translations` is "the operator-authored English version" — distinct from the canonical column when a club has rebranded a row's English label without changing the seed value. |
| 9 | Cache invalidation | **Per-row, on every translation upsert.** Bumps a per-`(entity_type, entity_id)` version counter in `wp_options` (mirrors the #0078 Phase 5 `CustomWidgetCache` pattern); resolver reads/writes through versioned keys. Bulk imports (the seed-review Excel) wrap their writes in a single version bump per entity_type to avoid 100 cache misses on 100-row uploads. |
| 10 | FR/DE/ES rollout (#0010) | **Adding a locale = zero schema change.** Register the locale in `I18nModule::REGISTERED_LOCALES`; it becomes a column in the per-entity translation editor and a row option in the seed-review Excel. The `.po`-side rollout for UI strings (`fr_FR.po`, `de_DE.po`, `es_ES.po`) ships independently per the #0010 spec; this epic only touches the data-row channel. |
| 11 | Operator UI for editing | **Two surfaces:** (a) a "Translations" tab on each translatable entity's existing admin page (lookups, eval-categories, roles, functional-roles) — per-row edit grid with column per registered locale, row per translatable field; (b) the existing seed-review Excel (#0089's bulk-edit path) extended with `<field>_<locale>` columns. The operator picks per-row editing for one-off fixes and Excel for bulk review. |
| 12 | Cap layer | **`tt_edit_translations`** new cap, granted to administrator + tt_club_admin + tt_head_dev by default. Bridged to a new `translations` matrix entity via `LegacyCapMapper` so per-club admins can manage their own translations without touching install-wide settings. Top-up migration backfills existing installs with the matrix entity rows. |

## Architecture overview

```
┌─────────────────────────────────────────────────────────────────┐
│  Admin: Lookups / Eval categories / Roles / Functional roles    │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │  Tabs: [List][+ Add][Translations]                          ││
│  │     Translations tab: per-row × per-locale × per-field grid ││
│  │     ↓ save → upsert into tt_translations                    ││
│  └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                                │
                                ▼
                  ┌──────────────────────────────────┐
                  │  tt_translations                 │
                  │  PK: (entity_type, entity_id,    │
                  │       field, locale, club_id)    │
                  │  cols: value, updated_by,        │
                  │        updated_at                │
                  └──────────────────────────────────┘
                                │
                                ▼
                  ┌──────────────────────────────────┐
                  │  TranslationsRepository          │
                  │   translate(type,id,field,       │
                  │             locale, fallback)    │
                  │   60s wp_cache via versioned key │
                  └──────────────────────────────────┘
                                │
                                ▼
            ┌───────────────────┼───────────────────────────────┐
            │                   │                               │
        Frontend           REST controllers              Seed-review
        view layer         (e.g. Lookups REST)           Excel exporter
            │                   │                               │
            ▼                   ▼                               ▼
     `LookupTranslator::      payload includes            <field>_<locale>
      label( $row )` →        translated value at         columns become
      table cell              current request locale     editable on import

UI strings continue to flow through __() / _n() / _x() resolved by
gettext from .po — unchanged by this epic.
```

### Schema — `tt_translations`

```sql
CREATE TABLE tt_translations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    club_id         INT UNSIGNED NOT NULL DEFAULT 1,
    entity_type     VARCHAR(32) NOT NULL,
    entity_id       BIGINT UNSIGNED NOT NULL,
    field           VARCHAR(32) NOT NULL,
    locale          VARCHAR(10) NOT NULL,
    value           TEXT NOT NULL,
    updated_by      BIGINT UNSIGNED DEFAULT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uk_lookup
        (club_id, entity_type, entity_id, field, locale),
    KEY idx_lookup (club_id, entity_type, entity_id),
    KEY idx_locale (locale)
);
```

`entity_type` is `VARCHAR(32)` (not `ENUM`) so adding a new translatable entity needs zero migration. The `TranslatableFieldRegistry` enforces the allowlist in software.

### `TranslatableFieldRegistry`

```php
namespace TT\Modules\I18n;

final class TranslatableFieldRegistry {
    public const ENTITY_LOOKUP            = 'lookup';
    public const ENTITY_EVAL_CATEGORY     = 'eval_category';
    public const ENTITY_ROLE              = 'role';
    public const ENTITY_FUNCTIONAL_ROLE   = 'functional_role';

    /** @var array<string, list<string>> */
    private static array $fields = [];

    public static function register( string $entity_type, array $fields ): void {
        self::$fields[ $entity_type ] = array_values( array_unique( $fields ) );
    }

    public static function fieldsFor( string $entity_type ): array {
        return self::$fields[ $entity_type ] ?? [];
    }

    public static function isRegistered( string $entity_type, string $field ): bool {
        return in_array( $field, self::fieldsFor( $entity_type ), true );
    }

    public static function entities(): array {
        return array_keys( self::$fields );
    }
}
```

### Resolver

```php
namespace TT\Modules\I18n;

final class TranslationsRepository {
    public static function translate(
        string $entity_type,
        int $entity_id,
        string $field,
        string $locale,
        string $fallback
    ): string {
        // Cache lookup, then DB lookup, with locale fallback chain:
        //   $locale → 'en_US' → $fallback
        // Returns first non-empty value. Never returns empty string.
    }

    public static function upsert(
        string $entity_type,
        int $entity_id,
        string $field,
        string $locale,
        string $value,
        int $user_id
    ): void { /* ... */ }

    public static function delete(
        string $entity_type,
        int $entity_id,
        string $field,
        string $locale
    ): void { /* ... */ }

    public static function bumpVersion( string $entity_type, int $entity_id ): void {
        // O(1) cache invalidation per the #0078 Phase 5 pattern.
    }
}
```

## Phase plan

| Phase | Scope | Estimate |
|---|---|---|
| 1 | Foundation: migration `00XX_translations` + `TranslatableFieldRegistry` + `TranslationsRepository` + read-path resolver + locale fallback + cache layer + new cap `tt_edit_translations` + matrix entity `translations` + top-up seed migration. No entities migrated yet. | ~12-15h |
| 2 | Lookups migration: `__()` backfill into `tt_translations` for every seeded row × every registered locale (today: en_US + nl_NL); `LookupTranslator` helper switched to use the resolver; existing call sites swept. Per-row Translations tab on the frontend Lookups admin. | ~10-15h |
| 3 | Eval categories migration: same pattern. The `tt_eval_categories.label` column becomes the canonical English; per-locale translations move to `tt_translations`. Admin page gains Translations tab. | ~6-8h |
| 4 | Roles + functional roles migration: same pattern. Two small entities, one PR. | ~4-6h |
| 5 | Seed-review Excel update: `<field>_<locale>` columns become editable for migrated entities; on re-import, writes flow into `tt_translations` instead of the source table. The read-only `label_nl` column from #0089's exporter goes away. | ~6-8h |
| 6 | `.po` cleanup: strip migrated msgids from `nl_NL.po`. Tooling: a one-shot script that walks `nl_NL.po`, looks up each msgid against `tt_translations`, removes the row if the DB has it. Documentation: `docs/i18n-architecture.md` (EN+NL) explains the split. | ~6-8h |
| 7 | FR/DE/ES rollout enablement (#0010-adjacent): registering `fr_FR` / `de_DE` / `es_ES` in `I18nModule::REGISTERED_LOCALES` lights up new columns in the Translations tab + new columns in the seed-review Excel. No data backfill ships here — that's #0010 territory. Just the structural enablement. | ~4-6h |
| 8 | Docs + i18n + README + close epic. `docs/i18n-architecture.md` (EN+NL) + spec frontmatter `status: shipped`. | ~4h |
| | **Total** | **~52-70h conventional / ~20-28h compressed** |

## Out of scope (deferred)

- **UI string migration to DB.** `.po` keeps UI strings — see Decision Q4. Not on the roadmap.
- **Plural translations of data rows.** v1 stores singular forms only. If a data-row label needs plural variants ("1 player" / "%d players"), it stays in `.po` (UI surface) rather than `tt_translations` (data surface).
- **Per-club override of code-shipped seed labels.** v1: a row's translations apply to every club that holds the row (defaulted from `club_id=1` translations until a per-club row is written). Per-club rebranding ("our academy calls Players 'Pupils'") becomes possible once `tt_translations` accepts non-`club_id=1` rows, but the operator UX for "rebrand the whole product per club" stays out — that's a marketing-tier customisation feature for a future spec.
- **Translation memory / suggest-from-similar.** Not v1. The operator types each translation once.
- **Auto-translate via LLM** (DeepL / OpenAI). Not v1. Could ship as a follow-up spec; would also handle initial population for new locales.
- **Translatable timestamps / numbers / dates.** Use WP's `wp_date()` / `number_format_i18n()`; not handled by `tt_translations`.

## Definition of done

A reviewer should be able to answer yes to all of:

- New migration creates `tt_translations` with the documented schema; idempotent on re-run.
- `TranslatableFieldRegistry` ships with the four v1 entities + their fields registered.
- `TranslationsRepository::translate()` returns translated value when a row exists, falls back through `$locale → en_US → $fallback` reliably, never returns empty.
- Migration backfills `nl_NL` translations from `.po` for every seeded row of every registered entity.
- Editing a Lookup row's Dutch label via the new Translations tab updates `tt_translations` and surfaces immediately on the next page render.
- Editing a `<field>_<locale>` cell in the seed-review Excel and re-importing updates `tt_translations` (NOT the source table's column).
- `nl_NL.po` no longer carries the migrated msgids after Phase 6 cleanup.
- New cap `tt_edit_translations` is bridged to the `translations` matrix entity; top-up migration backfills.
- Cache hit rates measurable (manual: render the same page twice; verify only one DB query per `(entity_type, entity_id)` triple).
- FR/DE/ES locales (when registered in #0010) automatically appear as new columns in the Translations tab + the Excel.

## Open questions for the next session

None at architecture level. All twelve Qs locked above.

## Trigger to start

Free now. No upstream blockers. Recommend kicking off Phase 1 in a fresh session — it's the foundation and lands no user-visible change, so it can ship independently of any per-entity rollout pace.
