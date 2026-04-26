<!-- audience: admin -->

# Auto-translation

The plugin's UI text is translated via the standard `.po` / `.mo` flow. **User-entered free text** — goal titles, evaluation notes, session descriptions, attendance notes — historically wasn't. A coach writing in Dutch and a parent reading in French saw raw Dutch.

The Translations layer (#0025) closes that gap with an opt-in render-time translation cache.

## Default OFF

Until you enable it explicitly, nothing changes. No API calls, no transmission of source text, no extra cost. Source text renders as-is. Source-language detection also doesn't run on saves.

Two things must be true to enable:

1. The **GDPR Article 28** sub-processor checkbox is ticked. The provider you choose acts as a sub-processor on your club's behalf; you authorise that relationship by enabling the feature.
2. The **primary engine** has valid credentials configured.

If either is missing the form refuses to flip Enable to ON and shows an inline error.

## Where to find it

`wp-admin → TalentTrack → Configuration → Translations`.

## Engines

Two engines ship with the plugin; the layer is engine-agnostic so a third can be slotted in via the `tt_translation_engine_factory` filter.

| Engine | API | Free tier | Notes |
| --- | --- | --- | --- |
| DeepL (primary) | api-free.deepl.com (free keys) / api.deepl.com (paid) | 500,000 chars/month | Auth via API key. Quality on Dutch ↔ EN/FR/DE/ES is widely considered better than alternatives. |
| Google Translate | Cloud Translation v3 | (no free tier; ~€20 per million chars) | Auth via service-account JSON. Project must have the Cloud Translation API enabled. |

You can configure a fallback engine that's used only when the primary returns a recoverable error (rate limit, 5xx, network). If both engines fail the layer returns source text unchanged.

## Cost cap (soft)

You set:

- A monthly character cap. Default 200,000 — covers most single-club deployments under DeepL's free tier.
- A notify-at-threshold percentage (default 80%).

What happens at each level:

- Below threshold: nothing visible. Translations happen, cache fills, usage counter ticks up.
- At threshold: a persistent admin notice appears on the wp-admin dashboard. Fires once per month (recorded on the usage row).
- At 100% of cap: engine calls cease for the rest of the month. Viewers see source text. The notice escalates to an error tone with a "Raise the cap" link. No save-time errors, no request blocking.

The next month auto-rolls over (period_start changes); usage resets implicitly because counters are scoped per period.

## Source-language detection

Detection runs at write time, not read time. The first save of a free-text field calls the engine's detect endpoint, stores the result on `tt_translation_source_meta`, and that becomes the source-language for every render until the field changes.

If detection confidence is below 0.6 the layer falls back to the configured site default content language. That value defaults to your WP locale's short code (`nl` for `nl_NL`, etc.) and is settable in the Configuration tab.

Re-saves of unchanged text don't re-detect — the source hash is recorded alongside the detected language.

## Cache invalidation

The cache invalidates automatically when a source string changes. Editing a goal title from "Aanname onder druk" to "Aanname onder druk verbeteren" drops every cached translation of the old text; the next reader in each target language pays for a fresh translation.

A "Clear cache now" button on the Configuration tab wipes the entire cache plus the source-meta table. Use it when you swap engines and want a clean slate, or when you opt out (the layer does this automatically on opt-out).

## What gets translated

Today: goal titles + descriptions, session titles + notes, attendance notes, the player-dashboard surface that surfaces those rows.

Out of scope by design:

- `tt_lookups.translations` (already an admin-managed translation system).
- File names + media metadata.
- The plugin's own UI strings (those go through the standard `.po` / `.mo` flow).

Adding a new free-text call site to the inventory means wrapping its render with `TranslationLayer::render( $value )` and calling `TranslationLayer::detectAndCache( $entity_type, $entity_id, $field_name, $value )` on the save path. See `docs/architecture.md` for the conventions.

## User preferences

Each WP user picks how translated content surfaces, on the standard wp-admin profile screen:

- **Translated** (default) — show translated; hide source.
- **Original** — never translate; always show source.
- **Side-by-side** — render `[translated text] (original: [source text])`. Useful for HoDs or scouts spot-checking accuracy.

Stored as `user_meta` key `tt_translation_pref`. Applies sitewide to that user.

## Privacy posture

When you enable translations, the plugin appends a paragraph to the WP **privacy policy editor** (Settings → Privacy → Privacy Policy Guide) describing the sub-processor relationship. Copy it into your published policy as appropriate.

Disabling translations clears the entire `tt_translations_cache` and `tt_translation_source_meta` tables. Source content (goals, sessions, evaluations) is untouched — only the translation derivatives are erased.

## Sub-processor disclosure (DPA links)

- **DeepL SE** — [www.deepl.com/privacy](https://www.deepl.com/privacy/)
- **Google LLC** — [Cloud DPA](https://cloud.google.com/terms/data-processing-addendum)

Verify the current links at the provider before relying on these. The plugin doesn't track DPA versions.

## Troubleshooting

- **Translations not appearing**: check the layer is Enabled in Configuration. Check the source's detected language matches what you'd expect (`tt_translation_source_meta` row for that entity). Check usage hasn't hit the cap (Configuration → Translations → Usage this month).
- **Engine auth errors**: the layer logs to the standard TalentTrack logger via `Logger::error( 'translations.engine.failed', … )`. Check `tt_logs` (or the dashboard log viewer when it ships) for the engine name + reason code.
- **Token errors with Google**: the layer caches the OAuth2 access token in a transient for ~1h. The transient gets cleared on 401/403 and the next call re-fetches. Manual reset: clear all transients via WP-CLI (`wp transient delete --all`) or via your cache plugin.
- **Wrong source language detected**: most common cause is the field is shorter than ~10 chars. Detection thresholds at 0.6; below that, the site default kicks in. Editing the field to expand it will re-detect on save.
