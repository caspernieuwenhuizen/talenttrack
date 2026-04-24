<!-- type: feat -->

# Multi-language support — French, German, Spanish (Spain)

## Problem

TalentTrack ships today with Dutch (`nl_NL`) translations covering the UI; English is the source. To expand beyond Dutch-speaking markets, the plugin needs translations for other European languages academies are likely to use: French, German, and Spanish (Spain).

There's also a hygiene issue: the existing `talenttrack.pot` template file has **246 msgids** but the Dutch `.po` file has ~1000. The POT is badly stale — this means any new language built from it will have large gaps. Before translating into new languages, the POT needs to be regenerated so every `__()` / `esc_html__()` call in the codebase is captured.

Who feels it: French-speaking (Belgium, France, Switzerland, Quebec), German-speaking (Germany, Austria, Switzerland), and Spanish-speaking academies. All three markets have youth football development programs that are underserved by English-only or Dutch-only tools.

## Proposal

Four deliverables:

1. **Regenerate `talenttrack.pot`** from the full codebase so every translatable string is captured.
2. **Create `.po` files for three new locales**: `fr_FR`, `de_DE`, `es_ES`. Translate all strings.
3. **Translate all 19 existing English docs** (in `docs/`) into each of the three locales. Total: 57 new markdown files.
4. **Add a release-checklist step** to DEVOPS.md: "Regenerate POT before tagging a release" — prevents the POT-vs-PO drift from recurring.

## Scope

### POT regeneration

Before any translation work, regenerate `languages/talenttrack.pot` using `wp i18n make-pot`. This captures every `__()`, `_e()`, `esc_html__()`, etc. call across `src/`, `includes/`, `templates/`, and elsewhere.

Expected output: a POT file with **~1000–1200 msgids** (matching or exceeding the current Dutch `.po`).

If hardcoded English strings surface during POT regen that aren't wrapped in translation functions: wrap them and include them in the POT. This is a small but real audit pass on the existing codebase.

### Three new `.po` + `.mo` pairs

For each of `fr_FR`, `de_DE`, `es_ES`:
- Create `languages/talenttrack-{locale}.po` from the regenerated POT.
- Translate every msgid.
- Compile to `talenttrack-{locale}.mo`.

### Mixed-formality tone classification

Per shaping decision: informal for player-facing surfaces, formal for admin/official surfaces.

Tone classification guide (include in `docs/` as a translator reference):

| Surface | French | German |
| --- | --- | --- |
| Player dashboard, evaluations view, goals, my profile | Tu | Du |
| Coach-facing session entry, attendance, player browsing | Tu | Du |
| HoD-facing reports, decision panels | Vous | Sie |
| Admin/Settings, config pages, role management | Vous | Sie |
| System-level errors, migration messages | Vous | Sie |
| Emails to parents (from letter templates) | Vous | Sie |

Spanish (`es_ES`) doesn't have the same formal/informal distinction as prominently (the tú/usted axis is less rigidly tied to context). Default: tú throughout for informality, usted only for the most formal external-facing communications (e.g. denial letters to parents).

Translator workflow: each translator reviews a string in context (knowing which surface it's on) and picks the tone. To make this feasible:
- Add PHP translator comments (`/* translators: admin-facing */`) before strings where the tone isn't obvious from the msgid.
- Include a tone-classification key in the translator brief.

### Docs translation

19 English docs in `docs/`. Translate each into each of the three locales:
- `docs/fr_FR/` — 19 files, French
- `docs/de_DE/` — 19 files, German
- `docs/es_ES/` — 19 files, Spanish

Tone for docs: formal-leaning (Vous/Sie/usted) since docs are for administrators and power users.

Translation approach:
- Machine-translate (DeepL or similar) as first draft.
- Human review and editing pass by a native speaker. This is the expensive step — for 57 files × ~500 words each = ~30,000 words per language, budget real time.
- Store translated docs alongside the English originals. The plugin's docs viewer (from #0019 Sprint 7) selects the locale-appropriate file at render time.

### DEVOPS.md checklist update

Add a section to `DEVOPS.md`:

```
## Before tagging a release

1. Run `wp i18n make-pot . languages/talenttrack.pot` to regenerate the POT.
2. Diff against the previous POT — any new msgids?
3. If yes: update each active `.po` file (nl, fr, de, es) with the new strings. Either translate them yourself or leave them untranslated (fallback to English at runtime).
4. Compile `.mo` files via `msgfmt`.
5. Commit the regenerated POT, updated POs, and compiled MOs.
6. Then tag the release.
```

## Out of scope

- **Spanish Latin American variant (`es_419`, `es_MX`).** Shaping decision: `es_ES` only. Add later if a customer asks.
- **Additional locales beyond the three.** Italian, Portuguese, Polish, etc. — separate future ideas.
- **Hiring translators.** This spec assumes you have or can hire a native speaker per language for the review pass. Cost and logistics are yours to manage outside the code.
- **A translation management UI** (like GlotPress or Weblate for web-based collaborative translation). Files live in the repo; edits go through git. If this becomes painful, a future idea can introduce tooling.
- **Runtime language switching** by the user. WordPress handles this via the user's profile language setting — no new UI needed.
- **Translating custom content** that clubs add (e.g. club-specific custom fields, lookup values). That's club-by-club; not a plugin concern.

## Acceptance criteria

### POT and PO files

- [ ] `languages/talenttrack.pot` contains all translatable strings from the codebase (expect ~1000–1200 msgids).
- [ ] `languages/talenttrack-fr_FR.{po,mo}` exists, all msgids translated, compiled.
- [ ] `languages/talenttrack-de_DE.{po,mo}` exists, all msgids translated, compiled.
- [ ] `languages/talenttrack-es_ES.{po,mo}` exists, all msgids translated, compiled.
- [ ] All four `.po` files (including existing `nl_NL`) are in sync with the current POT (no stale msgids).

### Tone consistency

- [ ] French: player-facing uses Tu; admin/official uses Vous.
- [ ] German: player-facing uses Du; admin/official uses Sie.
- [ ] Spanish: tú throughout except for formal parent-facing letters.
- [ ] Translator notes (`/* translators: */`) present on any string whose tone isn't obvious from the msgid.

### Docs

- [ ] All 19 English docs have a French, German, and Spanish counterpart in the appropriate `docs/{locale}/` folder.
- [ ] Each translated doc has been through a native-speaker review pass.
- [ ] Docs preserve the structure (headings, code blocks) of the English originals.

### Release hygiene

- [ ] `DEVOPS.md` has the new pre-release checklist.
- [ ] The checklist has been followed once (proving it works) before this spec is considered complete.

### Runtime

- [ ] Setting a user's WP profile language to French/German/Spanish renders the plugin UI correctly in that language.
- [ ] Missing translations fall back to English (default WP behavior — verify no broken pages).
- [ ] No PHP warnings related to missing text domain on any locale.

## Notes

### Sizing

This is a translation project as much as a code project. Honest estimate:

- POT regeneration + audit for untranslatable strings: ~2 hours
- Codebase wrap pass (any hardcoded English found): ~2–4 hours depending on findings
- UI translation (machine + native review), 3 languages: **~15–25 hours per language** for the native review = **~45–75 hours total** for UI
- Docs translation (57 files): **~30–60 hours total** across 3 languages, depending on reviewer speed

**Total: ~80–140 hours** of work, most of it translation review. The code work itself is ~4–6 hours; the rest is translation labor.

This is not a sprint-sized deliverable. It's a **multi-week project** running in parallel with other dev work. The code-side preparation (POT regen, tone-classification guide, translator comments) can happen in one week; the translation review happens asynchronously per language as translators are available.

### Parallelization

- POT + codebase prep is serial (one week).
- Three languages can then proceed in parallel with different native speakers.
- Docs translation can be parallel with UI translation.

### Depends on

Nothing hard. Can start any time after the demo (May 4 / May 11).

In the SEQUENCE.md ordering, #0010 is in Phase 4 after #0017.

### Touches

- `languages/talenttrack.pot` — regenerate
- `languages/talenttrack-{nl_NL,fr_FR,de_DE,es_ES}.{po,mo}` — update existing nl_NL, create three new
- `docs/fr_FR/`, `docs/de_DE/`, `docs/es_ES/` — new folders, 19 files each
- Potentially `src/` — if POT regen finds hardcoded strings, wrap them
- `DEVOPS.md` — add release checklist
- `docs/translator-brief.md` (new) — tone-classification guide, translator comments explanation
