<!-- type: feat -->

# #0025 — Multilingual auto-translate for user-entered content

## Problem

The plugin's UI strings translate cleanly via `.po` / `.mo`. **User-entered free text** does not — goal titles, evaluation notes, session descriptions, custom-field text values, comment threads (when #0028 ships). A Dutch coach writes "Aanname onder druk verbeteren" and a French parent on the same install sees raw Dutch in their dashboard.

Today this is a non-issue: most deployments are single-club / single-language and the cost of NOT solving it is low. It becomes a visible product complaint as soon as either (a) #0010 ships FR/DE/ES UI plumbing or (b) #0011 onboards multi-locale clubs. This spec closes the gap with an opt-in auto-translation layer.

## Proposal

For every user-entered free-text field, lazily translate at render time when the viewer's locale ≠ the source locale. Cache results indefinitely keyed on `(source_hash, source_lang, target_lang, engine)`. Source-language is auto-detected per string with site-locale fallback. The whole feature is opt-in per club (default OFF) for GDPR Article 28 reasons. Each user picks how they want translated content surfaced via a profile preference.

Decisions locked during shaping (25 April 2026):

- **Opt-in, default OFF** for the entire translation layer. Admin enables it in Configuration with explicit confirmation that the chosen engine acts as a GDPR Article 28 sub-processor on the club's behalf. No translations happen until the admin opts in.
- **Engine: DeepL primary, Google Cloud Translation v3 fallback.** Cheapest+quickest order locked during the idea capture. Both wired through a `TranslationEngineInterface` so a future swap (OpenAI / self-hosted LibreTranslate / etc.) is a one-module change.
- **Cost ceiling: soft cap + fail open.** Admin sets a per-month character cap. At 80% an admin nudge fires; at 100% the layer stops calling the engine and renders source text untranslated. No request blocking, no payment surprises.
- **Source language: auto-detect per string with site-locale fallback.** Detection runs at write time. Detection confidence below threshold falls through to the site-locale default. Detection results cached on the source row so repeat reads don't re-detect.
- **Show-original UI: per-user profile preference.** Three values: `translated` (default), `original`, `side-by-side`. Set once in profile, applies sitewide. No per-string toggle.
- **Cache invalidation on source edit.** Editing a source string deletes all matching rows in the translation cache; the next reader in each target language pays for a fresh translation. No proactive re-translation.

## Scope

### Schema

New table:

```sql
CREATE TABLE tt_translations_cache (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_hash CHAR(64) NOT NULL,           -- SHA-256 of the source string
    source_lang VARCHAR(10) NOT NULL,        -- detected or admin-set
    target_lang VARCHAR(10) NOT NULL,        -- viewer's locale code
    translated_text TEXT NOT NULL,
    engine VARCHAR(32) NOT NULL,             -- 'deepl' | 'google' | future
    char_count SMALLINT UNSIGNED NOT NULL,   -- billed chars for budgeting
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_lookup (source_hash, source_lang, target_lang, engine),
    KEY idx_created (created_at)
);
```

Plus a `tt_translations_meta` row per source string (or as a small JSON column on the host row — decision during implementation): stores `detected_lang`, `detection_confidence`, `last_detected_at`. Avoids re-running detection on every read.

Plus a monthly counter table (or option_entry):

```sql
CREATE TABLE tt_translations_usage (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    period_start DATE NOT NULL,              -- first day of month
    engine VARCHAR(32) NOT NULL,
    chars_billed BIGINT UNSIGNED NOT NULL DEFAULT 0,
    api_calls INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_month_engine (period_start, engine)
);
```

Used to compute "characters consumed this month" against the admin's cap.

### `TranslationLayer` service

Single entry point for callers:

```php
namespace TT\Modules\Translations;

class TranslationLayer {
    public static function render( string $source, string $target_lang, ?string $source_lang_hint = null ): string;
    public static function isEnabled(): bool;
    public static function detectAndCache( string $source ): array;  // ['lang' => 'nl', 'confidence' => 0.92]
    public static function invalidateSource( string $source ): void;
    public static function usageThisMonth( string $engine ): int;
}
```

`render()` is the hot path. Algorithm:

1. If `! self::isEnabled()` → return `$source` unchanged.
2. If `$target_lang === detected_source_lang` → return `$source`.
3. Lookup `(source_hash, source_lang, target_lang, engine)` in `tt_translations_cache`. Hit → return cached row.
4. Miss: check the cost cap. At cap → return `$source` (fail open) + log a "cap-exceeded" event for the admin nudge.
5. Below cap: call the active engine's adapter, store result, increment `tt_translations_usage.chars_billed`, return translated.

Engine selection: try the configured primary engine; on adapter exception (rate limit / network), fall back to the secondary if configured; if both fail, return `$source` and log.

### Engine adapters

```php
interface TranslationEngineInterface {
    public function translate( string $source, string $source_lang, string $target_lang ): string;
    public function detect( string $source ): array;          // ['lang' => 'xx', 'confidence' => 0..1]
    public function pricePer1000Chars(): float;
    public function name(): string;
}
```

Initial implementations:
- `DeepLEngine` — REST API, requires `DEEPL_API_KEY` constant (or option). Free tier: 500k chars/month.
- `GoogleTranslateEngine` — Cloud Translation v3, requires service-account JSON. ~€20/M chars.

Future implementations slot in without touching `TranslationLayer`.

### Source-language detection

Auto-detect per string at write time:

1. On every save through the inventory of free-text call sites, before persisting the source value, call `TranslationLayer::detectAndCache( $source )`.
2. Detection confidence below 0.6 → fall through to the site locale (`get_locale()` mapped to a translation locale code).
3. Result stored alongside the source: either as a JSON column on the host table or in a sibling `tt_translation_source_meta` table keyed on `(entity_type, entity_id, field_name)`. Decision during implementation; the JSON column is preferred for hot tables (`tt_evaluations`, `tt_goals`, `tt_sessions`) where adding a JOIN per read would hurt.
4. Re-detection happens only when the source string changes.

Detection cost: one API call per write. To bound cost, detection is also cached on the source row — re-saves of unchanged text don't re-trigger detection.

### Cost cap behavior

Admin sets:
- **Monthly character cap** (default 200,000 — covers most single-club deployments under the DeepL free tier).
- **Email/notify-on-threshold percentage** (default 80%).

`TranslationLayer::render()` checks the running monthly count before each engine call. The admin nudge fires once per month at the configured threshold (idempotent — record the threshold-hit date in usage table).

At 100% of cap:
- `render()` returns source text unchanged.
- A persistent dashboard notice appears for admins: "Translation cap reached for [month]. Multi-locale users will see source text until [next month] or until the cap is raised."
- Admin can raise the cap inline; the notice clears and translation resumes.

No request blocking. No save-time errors.

### Configuration UI (Configuration → Translations tab)

New tab in the existing Configuration page (sibling to Branding, Toggles, etc.):

| Field | Type | Default |
| --- | --- | --- |
| Enable auto-translation | checkbox | OFF |
| I confirm the chosen engine acts as a sub-processor on our behalf (Article 28) | checkbox | (required if Enable is ON) |
| Primary engine | radio: DeepL / Google | DeepL |
| Fallback engine | radio: none / DeepL / Google | none |
| DeepL API key | text (encrypted at rest in `tt_config`) | — |
| Google service-account JSON | textarea or file upload | — |
| Site default content language | dropdown of installed locales | site `WPLANG` |
| Monthly character cap | number | 200000 |
| Notify admin at | percentage (0-100) | 80 |
| Sub-processor disclosure (read-only display) | text block | "DeepL SE / Google LLC. See [link to DPA]" |

Saving with Enable=ON without the sub-processor checkbox or without the engine API credentials shows a validation error.

### User profile preference

New section on the existing user-profile screen (or wp-admin user-edit screen, or inside the frontend "My account" view if/when that exists):

- **Translation display**: radio
  - `translated` — default. Translations rendered, source hidden.
  - `original` — never translate; always show source text.
  - `side-by-side` — render `[translated text] (original: [source])`. For verification.

Stored as `user_meta` key `tt_translation_pref`. Default: `translated`.

`TranslationLayer::render()` respects the calling user's preference: `original` short-circuits to `$source`; `side-by-side` returns a small HTML block; `translated` is the normal flow.

### Inventory of free-text call sites

The implementation PR audits and updates every existing display of user-entered free text. Working list (refined during the PR):

1. `Goal::title`, `Goal::description` — frontend goals views, goal print blocks.
2. `Evaluation::notes`, `Evaluation::tactical_notes`, etc. — evaluation render in player profile, eval list, eval print.
3. `Session::title`, `Session::description`, `Session::location` — session list + detail views.
4. `Attendance::notes` — session attendance render.
5. `tt_custom_fields` text values — anywhere they render.
6. `Player::display_notes` (if such a field exists) — player profile.
7. `tt_lookups.translations` JSON — *not* in scope (that's already an admin-managed translation; using auto-translate on top would be silly).

Each call site swaps from `esc_html( $value )` to `esc_html( TranslationLayer::render( $value, $viewer_locale ) )` (or equivalent for HTML contexts where some markup is allowed).

### Privacy / GDPR posture

- **Default OFF**: nothing happens until admin opts in.
- **Sub-processor disclosure** in the Configuration tab (read-only text block).
- **Privacy policy snippet**: when the feature is enabled, the plugin appends a paragraph to the WP privacy policy draft via the standard `wp_add_privacy_policy_content()` API: "Free-text content authored by users on this site may be transmitted to [DeepL SE / Google LLC] for translation when viewers request a different locale. See [DPA links]."
- **Right to erasure**: when a club opts back out, source strings are unaffected, but cached translations are deleted (`TRUNCATE tt_translations_cache` filtered by club scope where applicable). The cache holds no PII beyond what's in the source string itself; deletion is straightforward.
- **Per-club opt-in** is the right granularity for #0011 multi-tenant deployments. Each tenant decides independently.

## Out of scope (v1)

- **Self-hosted LibreTranslate / Argos engine.** Deferred — most clubs run on shared WP hosting where hosting a translation model is impractical. Slots in via the engine interface if/when demand surfaces.
- **Translation memory / glossary.** No custom dictionaries (e.g., "JO12 should never translate to U12"). Engine output is taken as-is. If terminology drift becomes a problem, add a glossary layer post-launch.
- **Bulk re-translation on engine swap.** Switching engines doesn't re-translate existing cache rows. Admin can manually clear the cache to force re-translation on next read.
- **AI-assisted translation review** (LLM-based editing for tone). Different problem; if needed, separate idea.
- **Translation of `tt_lookups.translations` JSON.** Lookups already have an admin-managed translation system from v3.6.0; auto-translating on top adds complexity for negligible value.
- **Translation of attachment filenames or metadata.** Just text fields.
- **Per-user "always show original from this author" rule.** Possible future feature; not v1.

## Acceptance criteria

- [ ] **Schema**: `tt_translations_cache`, `tt_translations_usage`, source-meta storage (JSON column or sibling table) all created via migration. Existing data unaffected.
- [ ] **Default OFF**: a fresh install or upgraded install with no Configuration → Translations action takes ZERO API calls and renders source text everywhere.
- [ ] **Opt-in flow**: enabling translation requires the sub-processor checkbox AND valid engine credentials. Saving without either shows a validation error and does not enable.
- [ ] **Detection**: writing a new free-text field detects source language and stores it. Confidence below 0.6 falls through to the site default.
- [ ] **Cache hit**: rendering a previously-translated string with the same target_lang doesn't call the engine.
- [ ] **Cache miss → engine call**: a fresh target language triggers exactly one engine call, increments `tt_translations_usage`, stores the result, returns translated text.
- [ ] **Cache invalidation**: editing a source string clears all matching rows in `tt_translations_cache`.
- [ ] **Cost cap soft nudge**: admin sees a dashboard notice when usage crosses the configured threshold; only fires once per month.
- [ ] **Cost cap fail-open**: at 100%, engine calls cease and source text renders. Admin notice persists until cap is raised or month rolls over.
- [ ] **Engine fallback**: when the primary engine raises a recoverable error (rate limit / 5xx), the fallback engine attempts the call. Both failing → source text returned.
- [ ] **User preferences**: a user with `tt_translation_pref = original` sees source text everywhere; `side-by-side` sees both; `translated` (default) sees translated only.
- [ ] **Privacy**: enabling translation populates `wp_add_privacy_policy_content()` with the sub-processor paragraph. Disabling cleans up the entry.
- [ ] **Cache erasure on opt-out**: disabling translation deletes all `tt_translations_cache` rows.
- [ ] **Inventory wired**: every call site listed in the inventory routes through `TranslationLayer::render()`.
- [ ] **Translations of UI strings**: new Configuration tab + user profile preference labels translated to nl_NL.
- [ ] **Docs**: new `docs/translations.md` (audience: admin) describing how to enable, costs to expect, sub-processor implications. Plus `docs/nl_NL/translations.md`.

## Notes

### Why opt-in and not opt-out

GDPR Article 28 requires the controller (the club) to authorize each processor in writing. An opt-out default ships every multi-language club into a sub-processor relationship before they've consented — that's a defensible-but-shaky position with EU regulators. Opt-in by an explicit admin action with a sub-processor confirmation checkbox is the conservative, audit-clean posture. Adoption is slower; legal exposure is lower.

### Why auto-detect per string instead of admin-locked default

Real-world clubs run mixed-language content: a Dutch coach copies an English article quote into a session note, a Belgian club operates in Dutch + French in the same install. An admin-locked source language would either mark the quote as Dutch (translation produces nonsense) or force the admin into per-field gymnastics. Auto-detect per string handles the variation correctly, with site-locale fallback for short ambiguous strings. The detection cost is bounded by caching detection results on the source row.

### Why user preference instead of inline toggle

A per-string "show original" icon adds visual noise to every translated text in the dashboard. A user-level preference is set once and forgotten. Power users (HoDs reviewing accuracy, scouts cross-checking source) flip to `side-by-side`; everyone else stays on the default `translated`. The icon-per-string approach was rejected because it interrupts reading flow on data-dense screens like the eval list.

### Why DeepL primary

Cheapest at the relevant volume: the free tier covers most single-club deployments (500k chars/month is more than typical content authoring volume). Quality on Dutch ↔ EN/FR/DE/ES is widely considered better than Google Translate for this content type. Google is the fallback for resilience and for clubs that already have GCP credits / billing. Engine swap is one-module away if either becomes problematic.

### What happens to cached translations on engine swap

They stay. The cache key includes `engine`, so a switch from DeepL to Google means the next read in each target language pays for a fresh Google translation. Old DeepL rows aren't deleted automatically (might still be useful if the admin swaps back). A "clear cache" button in Configuration handles the case where an admin wants a clean slate after an engine change.

### Touches

New:
- `src/Modules/Translations/TranslationsModule.php`
- `src/Modules/Translations/TranslationLayer.php` — service entry point.
- `src/Modules/Translations/Engines/TranslationEngineInterface.php`
- `src/Modules/Translations/Engines/DeepLEngine.php`
- `src/Modules/Translations/Engines/GoogleTranslateEngine.php`
- `src/Modules/Translations/Cache/TranslationsCacheRepository.php`
- `src/Modules/Translations/Admin/TranslationsConfigTab.php` — the Configuration tab section.
- `database/<NN>-add-translations-tables.sql`
- `docs/translations.md` (audience: admin) + `docs/nl_NL/translations.md`.

Existing:
- `src/Modules/Configuration/Admin/ConfigurationPage.php` — register the new tab.
- Every free-text render site in the inventory (~15-20 call sites).
- `src/Modules/Goals/`, `src/Modules/Evaluations/`, `src/Modules/Sessions/` — write-path detection hooks.
- `src/Modules/Players/Frontend/MyAccountView.php` (or wherever the user preference lives) — radio for `tt_translation_pref`.
- `languages/talenttrack-nl_NL.po` — UI strings.
- WP Privacy → privacy policy draft hook.

### Depends on

- **#0010 (multi-language UI: FR/DE/ES)** — not strictly blocking, but #0025 has limited value on a single-locale install. Spec can ship before #0010, but the visible benefit lands when #0010 expands the locale set beyond Dutch + English. Order recommendation: #0010 first, #0025 second.
- **No dependency on #0011.** Per-club / per-tenant cap logic is internal to this spec; if/when #0011 lands a multi-tenant license model, the cap can be tier-aware via a separate small change in #0011's feature-audit sprint.

### Blocks

- **#0028 (conversational goals)** — when comments-on-goals ships, comment text becomes another free-text call site that should route through `TranslationLayer`. Coordinate ordering: if #0028 ships first, add the call site there post-merge.

### Sequence position

After #0010 (recommended). Independent of #0011, #0023, #0026, #0027, #0029.

### Sizing

~26 hours:

| Work | Hours |
| --- | --- |
| Schema + migration + repository | 1.5 |
| Engine adapter interface + DeepL adapter | 2.0 |
| Google Translate adapter | 1.5 |
| `TranslationLayer::render` + cache lookup | 2.0 |
| Auto-detect at write time + source-meta storage | 2.0 |
| Cost cap counter + admin nudge | 2.0 |
| Configuration tab UI (engine, cap, opt-in, sub-processor confirm) | 2.5 |
| User profile preference (translated/original/side-by-side) | 1.5 |
| Inventory + wire ~18 call sites | 5.0 |
| Privacy policy hook + opt-out cache erasure | 1.0 |
| `docs/translations.md` (EN + nl_NL) + `.po` | 2.0 |
| Testing (real DeepL/Google calls in dev, fail-open scenario, opt-out flow) | 2.5 |
| Buffer for unfamiliar engine quirks | 0.5 |
| **Total** | **~26h** |

Could split: Sprint A = engine + layer + Configuration UI + opt-in scaffold; Sprint B = inventory wire-up + UI affordances + docs. Each ~13h.

### Cross-references

- Idea origin: [`ideas/0025-feat-multilingual-auto-translate.md`](../ideas/0025-feat-multilingual-auto-translate.md).
- Adjacent: #0010 (multi-language UI plumbing), #0028 (conversational goals — comment text becomes a translatable call site), #0017 (trial letters — letter content is human-authored and explicitly NOT auto-translated; spec already covers that distinction).
- DPA links: DeepL SE [https://www.deepl.com/privacy/], Google Cloud DPA [https://cloud.google.com/terms/data-processing-addendum]. Check current URLs at implementation time.
