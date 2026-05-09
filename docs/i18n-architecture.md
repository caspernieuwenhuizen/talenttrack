<!-- audience: dev -->

# i18n architecture — two channels, one rule

TalentTrack runs translation through **two distinct channels**, each owning a different category of strings. The split is intentional: the per-channel cost model and tooling are different, so mixing them produces the worst of both worlds. This page explains which channel owns what, why, and how to add a new translation.

## TL;DR

| Channel | Owns | Storage | Edit path | Adding a locale |
|---|---|---|---|---|
| **`.po` / gettext** | UI strings — `__('Save')`, button labels, validation errors, headings | `languages/talenttrack-<locale>.po` → `.mo` | Loco / Poedit / `xgettext` | Ship `<locale>.po` skeleton (#0010 territory) |
| **`tt_translations` table** | Data-row strings — lookup labels, eval-category names, role labels, functional-role labels | `tt_translations(club_id, entity_type, entity_id, field, locale, value)` | Per-entity Translations form, seed-review Excel | Add to `I18nModule::REGISTERED_LOCALES` |

The hard rule: **a string belongs to exactly one channel**. If you find yourself adding a `__()` call for a value that lives in a database column, stop — that's data, route it through `TranslationsRepository::translate()` instead.

## Why the split?

UI strings and data-row strings have fundamentally different read patterns and edit constraints.

### UI strings stay in `.po`

Five technical reasons that survive any "should we DB-back UI strings?" discussion:

1. **Performance.** gettext mmaps `.mo` once per request. Resolving `__('Save')` is a hash lookup against memory, zero query cost. Data-row resolution costs one cached SELECT per `(entity_type, entity_id)` triple. UI strings render thousands of times per page; cache misses there would matter.
2. **Plurals.** `_n('1 player', '%d players', $n)` — gettext understands language-specific plural rules (Polish 4 forms, Russian 3, Arabic 6). The `tt_translations` schema doesn't.
3. **Context disambiguation.** `_x('Open', 'verb', 'talenttrack')` vs `_x('Open', 'adjective', 'talenttrack')` — `.po` has `msgctxt` for this; the data table doesn't.
4. **Static analysis.** `xgettext` walks `__()` call sites, builds the `.pot`, catches missing translations automatically. Data-row strings are dynamic and can't be statically extracted.
5. **Plugin / hook integrations.** WPML, Polylang, Loco hook into the `gettext` filter. UI strings stored in DB tables bypass that ecosystem.

### Data-row strings move to `tt_translations`

Six reasons UI-string tooling is the wrong fit for data:

1. **Operator-authored content.** A coach adds a custom lookup row "Linker spits" — there is no `.po` channel for it. Pre-#0090 such rows shipped untranslated forever.
2. **Per-club rebranding (future).** Per spec Decision Q11 follow-up, a club may want to rebrand "Players" to "Pupils". That's per-tenant data, not a global string.
3. **Editable from the UI.** Operators expect to edit the Dutch translation of "Goalkeeper" inline, not via a `.po` round-trip + plugin update.
4. **Bulk review path.** The seed-review Excel (#0089 / Phase 5) is a natural fit for editing 200 lookup labels at once across 5 locales. `.po` is a one-locale-at-a-time tool.
5. **Same data, different routes.** The exact same lookup row needs to appear in every channel a SaaS front-end might consume — REST, mobile app, public-facing widget. A queryable table fits that better than `.mo` files.
6. **Cache-coherent invalidation.** Saving a translation row bumps a per-`(entity_type, entity_id)` version counter; cached translations orphan immediately. `.mo` requires a server-side reload — fine for ship-time, awkward for runtime.

## How the data-row channel works

### Schema

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
    UNIQUE KEY uk_lookup (club_id, entity_type, entity_id, field, locale),
    KEY idx_lookup (club_id, entity_type, entity_id),
    KEY idx_locale (locale)
);
```

`entity_type` is `VARCHAR(32)` (not ENUM) — adding a new translatable entity needs zero schema migration. The `TranslatableFieldRegistry` enforces the allowlist in software.

### Registry

`Modules\I18n\TranslatableFieldRegistry` declares which `(entity_type, field)` pairs are translatable. The registry is consulted by:

- `TranslationsRepository::translate()` — refuses unregistered fields (defensive against typos at call sites).
- The seed-review Excel — generates `<field>_<locale>` columns from the registry × `REGISTERED_LOCALES`.
- The per-entity admin Translations forms — render one row per registered field.

To add a new translatable entity, register it from your module's `boot()`:

```php
TranslatableFieldRegistry::register( 'my_entity', [ 'label', 'description' ] );
```

The four entities currently registered (#0090 Phases 2-4):

| Entity | Fields |
|---|---|
| `lookup` | `name`, `description` |
| `eval_category` | `label` |
| `role` | `label` |
| `functional_role` | `label` |

### Resolver

`TranslationsRepository::translate( $entity_type, $entity_id, $field, $locale, $fallback )` returns the row's translation if one exists, else the canonical column value (`$fallback`).

**Locale fallback chain:** `$locale → 'en_US' → $fallback`. Never returns empty. The canonical column on the source table is the immovable backstop.

**Cache:** 60-second `wp_cache` with versioned keys (mirrors the #0078 Phase 5 `CustomWidgetCache` pattern). Save bumps the per-row version counter; cached entries orphan immediately. O(1) invalidation.

**Tenancy:** every read + write scopes to `CurrentClub::id()`.

### Per-entity helpers

Each translatable entity has an ergonomic wrapper that consumes the resolver:

- `LookupTranslator::name( $row )` / `LookupTranslator::description( $row )`
- `EvalCategoriesRepository::displayLabel( $raw, ?int $entity_id )`
- `RolesPage::roleLabel( $key, ?int $entity_id )` + `FunctionalRolesPage::roleLabel( $key, ?int $entity_id )`
- `LabelTranslator::authRoleLabel( $key, ?int $entity_id )` + `LabelTranslator::functionalRoleLabel( $key, ?int $entity_id )`

Pass the entity_id when you have it. String-only callers continue to work via the gettext fallback.

### Adding a locale

Single line edit:

```php
// src/Modules/I18n/I18nModule.php
public const REGISTERED_LOCALES = [ 'en_US', 'nl_NL', 'fr_FR', 'de_DE', 'es_ES' ];
```

Every consumer (resolver, admin form, seed-review Excel) picks up the new locale automatically. No schema change. No migration. No data backfill.

The actual `.po` rollout for UI strings ships independently — that's #0010 territory. This module only opens the data-row channel.

## When you're not sure which channel a string belongs to

Ask: *is this string stored in a database column whose value an operator might edit?*

- **Yes** → `tt_translations`. Register the entity + field, route reads through the entity's helper.
- **No** → `.po`. Wrap with `__()`, let the .po toolchain catch it.

Edge cases:

- **Status keys** (`'active'`, `'open'`, `'completed'`) — keys, not labels. Render-time translated via `LabelTranslator`. Stored as enum-style strings; the human label maps via gettext.
- **Migration-seeded English values** — those go into `.po` historically (gettext-resolvable) AND now into `tt_translations` (Phase 6 backfill via gettext). Reads prefer `tt_translations` first.
- **Computed strings** — `sprintf( __('Hello, %s'), $name )` — the format string belongs in `.po`, the variable is data and stays raw.

## See also

- `docs/i18n-audit-2026-05.md` — pre-#0090 audit that triggered this architecture.
- `specs/shipped/0090-epic-data-row-i18n.md` — the architectural decisions in detail (12 Qs locked, Phase plan, definition of done).
- #0010 spec — the FR/DE/ES `.po` rollout for UI strings (separate epic).
- #0025 spec — auto-translate engines (DeepL / OpenAI) that can bulk-fill `tt_translations` for new locales.
