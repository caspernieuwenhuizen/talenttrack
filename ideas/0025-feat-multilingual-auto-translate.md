<!-- type: feat -->

# Multilingual auto-translate flow for user-entered content

Origin: post-#0019 v3.12.0 idea capture. The plugin's UI strings (menus, buttons, labels) localize cleanly via `.po` / `.mo`. What does NOT yet localize is **user-entered free text** — goal titles, evaluation notes, session descriptions, custom-field values. A Dutch coach writes "Aanname onder druk verbeteren"; a French parent in a multi-language deployment sees raw Dutch in their dashboard.

This idea is about closing that gap with an auto-translate path so user-entered content can render in each viewer's preferred language.

## Why this matters (working assumption)

- The single-club / single-language case is the dominant deployment today. The cost of NOT solving this is low while user count stays low.
- Once #0011 (monetization) brings on multi-locale clubs, or #0010 (FR/DE/ES UI) ships, the asymmetry "UI translates, my own data does not" becomes the most visible product complaint.
- This is also what makes a Dutch demo work cleanly for a French scout who's curious about the product.

## What it would do

For every user-entered free-text field (goal title, eval notes, session description, custom-field text values, comment threads from #0028 if it ships):

1. On write, store the source string + a detected source language code.
2. On read in language X (where X ≠ source), check a `tt_translations_cache` table for a previously-translated row.
3. If miss, call a translation engine, store the result, render translated.
4. Preserve a "show original" affordance — viewers can always see the source text.

## Engine choice

**Decision (Casper, post-0019 prompt): cheapest and quickest, in that order.**

Candidate stack to evaluate during shaping:

- **Google Cloud Translation v3** — €20/M chars; fast; widely supported.
- **DeepL Free tier** — 500k chars/mo free, then €4.49/€19.99/M chars; better quality on EU languages but rate-limited.
- **OpenAI / Anthropic APIs** — flexible quality but ~10× more expensive per char and slower; only consider if quality matters for this content type.
- **Self-hosted (LibreTranslate / Argos)** — free at runtime, GPU/CPU at server, may not be available on shared WP hosting.

Per the cheapest+quickest rule, the likely v1 stack is **DeepL free for sites under the threshold, fall back to Google Translate above**. Final pick goes through cost modeling once we know typical free-text volume.

## Open questions to resolve before shaping

1. **Cost ceiling.** Hard cap per-club per-month? Soft cap with admin nudge? What happens at the cap — fail open (show source) or fail closed (refuse new writes)?
2. **Cache invalidation.** Source edits require re-translating. How aggressive on busy clubs that revise often? Per-character or per-update charging matters here.
3. **Privacy.** GDPR Article 28: any third-party translation API is a sub-processor. Need a DPA, list it in the privacy statement, allow clubs to opt out (and disable the translate path).
4. **Detection accuracy.** Source-language detection on short strings ("Conditie") is shaky. Allow admin to lock the source language at site or per-club level?
5. **Engine swap.** Same `TT\\License::can()`-style abstraction so DeepL → Google → OpenAI is a one-module change, not a 100-call-site change.
6. **UI placement of the "show original" affordance.** Inline tooltip? Toggle in profile? Per-field control?

## Touches (when shaped)

- New module: `src/Modules/Translations/` — engine adapter interface, DeepL + Google adapters, cache repository.
- New table: `tt_translations_cache` (source_hash, source_lang, target_lang, translated_text, engine, created_at).
- New display helper: `TranslationLayer::translate( $source, $target_lang )` — calls cache first, engine on miss.
- Wired into every existing display site that renders user-entered free text. Inventory needed during shaping (~15-20 call sites across goals, evaluations, sessions, custom fields).
- New Configuration tab section: engine selection + cost cap + opt-out.
- `.po` strings — small (UI affordances only).

## Sequence position (proposed)

After #0010 (multi-language UI). #0010 ships the UI plumbing for FR/DE/ES; this idea then makes user-entered content first-class in those locales. Could ship before #0011 (monetization) if multi-locale clubs become common in pilots, or stay deferred if single-club deployments dominate.
