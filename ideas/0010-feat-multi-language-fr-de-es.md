<!-- type: feat -->

# Add French, German, Spanish translations across the plugin

Raw idea:

Multi language support. Need to add the following languages across the complete plugin: French, German and Spanish.

## What's already in place

The plugin is fully i18n-ready: `load_plugin_textdomain('talenttrack', ...)` is wired up in `talenttrack.php`, and all admin/frontend strings go through `__()` / `esc_html__()` / `esc_html_e()` with the `talenttrack` text domain. Dutch (`nl_NL`) exists as the reference locale, covering both UI strings (`languages/talenttrack-nl_NL.po` + `.mo`) and docs (`docs/nl_NL/*.md`).

So this isn't new infrastructure — it's three more translations in the existing pattern.

## Scope

For each of `fr_FR`, `de_DE`, `es_ES`:

1. **UI strings** — produce `languages/talenttrack-<locale>.po` and compiled `.mo`, translating every string in `talenttrack.pot`.
2. **Docs** — produce `docs/<locale>/*.md`, one file per topic matching the `docs/` and `docs/nl_NL/` structure.
3. **Role labels** — verify the translated role labels (Player, Coach, Head of Development, Staff) make sense in each language's football/sports context, not just literal translation.

## Out of scope

- Adding more languages later (Portuguese, Italian, etc.). Easy to extend once this pattern proves out.
- Runtime language switching per user. WordPress already supports this via user profile language — no work needed.
- RTL languages — French/German/Spanish are all LTR, no CSS changes needed.

## Catch before starting: the POT is almost certainly stale

`languages/talenttrack.pot` has 246 `msgid` entries. `languages/talenttrack-nl_NL.po` has ~1000. That gap means either (a) Dutch has ~750 entries for strings that no longer exist in the codebase, or (b) the POT was never regenerated after later features shipped.

Either way, **regenerate the POT first**, before translating anything. Otherwise we'd ship French/German/Spanish that cover a fraction of the UI, and have to do a second pass in three months.

Regeneration: `wp i18n make-pot . languages/talenttrack.pot --domain=talenttrack --exclude=plugin-update-checker,vendor,node_modules` (WP-CLI i18n command). Should be part of a release checklist going forward.

After regen: re-sync `nl_NL.po` against the new POT (`msgmerge`) to find obsolete entries (`#~` markers) and untranslated new ones. Fix the Dutch gaps. *Then* start on fr/de/es.

## How to do the translations

Three realistic paths:

- **(a) Machine translation + native review.** Run the POT through DeepL or GPT-4 per-language, then have a native speaker familiar with football terminology review. DeepL in particular is strong on fr/de/es. Fast and cheap for first pass. Risky for sport-specific terms ("pitch", "match", "drill", "lineup" have football-specific translations that generic MT misses).
- **(b) Native human translator.** Slower, more expensive, highest quality. Probably overkill for a plugin at this scale unless a specific customer demands it.
- **(c) Community/crowd.** Set up a project on translate.wordpress.org or Weblate. Works for the open-source path, needs a community to exist first.

Recommended: (a) with a glossary of football-specific terms established up front (drill, session, training, match, formation, lineup, pitch, player, coach, academy, etc.). Lock the glossary before translation so MT output is consistent.

## Open questions

- **Spanish variant.** `es_ES` (Spain) or `es_MX` / `es_ES` and others? Football terminology differs: "partido" vs "juego", "portero" vs "arquero", etc. Default: `es_ES` only, add `es_419` (Latin American Spanish) later if a customer asks.
- **German formality.** Sie (formal) or Du (informal)? Sports-coaching software in German-speaking countries usually goes informal (Du). Worth confirming with a German-speaking user.
- **French formality.** Tu or Vous? Coaching context in France leans Tu for player-facing strings, Vous for admin/official. Could mix based on which screen — or pick one and stick with it. Default: Tu throughout, for consistency.
- **Docs translation priority.** 19 markdown files per language × 3 = 57 files. Is every doc needed on day one, or do we ship UI strings first and translate docs incrementally? Shipping UI-only first is a reasonable MVP.
- **WordPress locale packs vs shipping files in the plugin.** Shipping `.mo` files inside the plugin (`languages/`) works always. If this plugin ever goes on wordpress.org, translations should live on translate.wordpress.org instead and come down via locale packs. Doesn't change day-one work — shipping in `languages/` is correct now.

## Touches

`languages/` — add six files (`.po` + `.mo` × 3 locales), regenerate `talenttrack.pot`.
`docs/` — add three folders (`docs/fr_FR/`, `docs/de_DE/`, `docs/es_ES/`) with translated markdown, one file per existing English doc.
No code changes expected. If any hardcoded English strings surface during POT regen, wrap them in `__()` / `esc_html__()` with the `talenttrack` text domain.

Release checklist update: add a "regenerate POT before tagging a release" step to DEVOPS.md so translations don't drift again.
