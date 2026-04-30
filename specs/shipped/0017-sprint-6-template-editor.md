<!-- type: feat -->

# #0017 Sprint 6 — Track + letter template editor

## Problem

Sprint 1 seeded three track templates (Standard / Scout / Goalkeeper) and Sprint 4 shipped three letter templates in English and Dutch. Clubs will want to:
- Customize the letter wording to match their voice (formal vs. warm, brand voice, specific phrases).
- Add new tracks (e.g. "Keeper Specialist Track — 6 weeks" with specific defaults).
- Translate letters into their working language if not Dutch/English.

Without editors, these customizations require editing PHP or SQL directly. Not realistic for most clubs.

## Proposal

Two admin surfaces:
1. **Track template editor** — CRUD for `tt_trial_tracks`. Add new tracks, edit existing ones (including seeded ones), archive.
2. **Letter template editor** — edit the three letter templates (admittance, deny-final, deny-encouragement) in the club's preferred language. HTML source with a variable-substitution legend and a preview.

Both live under the frontend Administration tile group (with `tt_manage_trials` capability).

## Scope

### Track template editor

Surface: `src/Shared/Frontend/FrontendTrialTracksEditorView.php`.

**List view**:
- All tracks (including the three seeded + any custom).
- Columns: name, slug, default duration, is_seeded, status (active/archived).
- Row actions: Edit, Archive, Duplicate.

**Edit view**:
- Name (translated — per-locale field).
- Slug (editable only if not seeded; seeded tracks have locked slugs).
- Description (per-locale).
- Default duration in days.
- "Save" button.
- "Revert to default" button for seeded tracks — resets name/description to the shipped default.

**New track flow**:
- Click "New track" → form pre-filled with sensible defaults (28 days, empty name/description).
- Save creates a new row with `is_seeded = false`.

**Archive flow**:
- Archiving a track prevents new cases from selecting it.
- Existing cases using the archived track continue to work (the track is still loadable by ID).

### Letter template editor

Surface: `src/Shared/Frontend/FrontendLetterTemplatesEditorView.php`.

**Three templates**: admittance, deny-final, deny-with-encouragement. Each editable separately.

**Per template**:
- Locale selector (English / Dutch — or whatever locales the site has active).
- HTML source editor (textarea with monospace font — no fancy WYSIWYG in v1, HoDs can paste formatted HTML if they want).
- Variable legend panel (sidebar or collapsible):
  - `{player_first_name}` — "First name of the trial player"
  - `{player_last_name}` — etc.
  - Lists every variable available in this template with a one-line description.
- Preview panel:
  - Shows the template rendered against a sample player (e.g. "Tim Pietersma, age 14, U14")
  - Updates on save (not live — live preview is a future enhancement).
- "Save" button.
- "Reset to default" button — restores the plugin-shipped default for this template + locale.

**Persistence**:
- Templates stored in a new table `tt_trial_letter_templates` with `(template_key, locale)` as the unique key. Fallback to the plugin-shipped default if no custom row exists.
- Template lookup order in Sprint 4's rendering: custom row for current locale → custom row for fallback locale (en) → plugin-shipped default.

### Acceptance-slip toggle (carryover from Sprint 4)

Sprint 4 added a setting `tt_trial_admittance_include_acceptance_slip`. This sprint adds the setting UI properly under the letter template editor:
- Toggle: "Include acceptance slip on admittance letters."
- Response deadline (days from letter date): default 14, configurable.
- Club address for returns (if slip is enabled): text field, used for the `{club_address}` variable in the slip.
- Response instructions text: per-locale, editable.

### Schema

New table `tt_trial_letter_templates`:
```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
template_key VARCHAR(64) NOT NULL,  -- 'admittance', 'deny_final', 'deny_encouragement'
locale VARCHAR(10) NOT NULL,        -- 'en_US', 'nl_NL', etc.
html_content LONGTEXT NOT NULL,
is_customized BOOLEAN DEFAULT TRUE,  -- FALSE only if it matches the shipped default verbatim
updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
updated_by BIGINT UNSIGNED NOT NULL,
UNIQUE KEY uk_template_locale (template_key, locale)
```

### Permissions

- Track and letter template editing gated by `tt_manage_trials` (same cap as decision recording).
- Seeded tracks can be edited and reset-to-default. Cannot be deleted (only archived).

## Out of scope

- **WYSIWYG letter editing.** HTML textarea only. WYSIWYG is a possible future enhancement.
- **Live preview** as HoD types. Preview updates on save.
- **Variable insertion UI** (click a variable in the legend to insert at cursor). Nice-to-have but not v1; copy-paste from the legend works.
- **Per-track letter overrides** (different letter for different tracks). Not v1 — same three templates regardless of track. Could be added later if clubs with multiple tracks have very different letter traditions.
- **Validation that required variables are present** in custom letters. HoD gets a preview; if they've removed `{player_first_name}`, they'll see the missing variable in the preview. No hard validation.

## Acceptance criteria

### Track editor

- [ ] HoD can list all tracks.
- [ ] HoD can create a new track with name, description, default duration.
- [ ] HoD can edit name and description of seeded tracks (slug stays locked).
- [ ] HoD can reset a seeded track to its shipped defaults.
- [ ] HoD can archive a track (hidden from new-case flow; existing cases still reference it).
- [ ] Duplicate creates a copy with `is_seeded = false` and empty customizations.

### Letter template editor

- [ ] HoD can open the editor for each of the three letter templates.
- [ ] Locale selector shows only active locales on the site.
- [ ] HTML source editor works.
- [ ] Variable legend is visible and informative.
- [ ] Preview renders against sample data on save.
- [ ] "Reset to default" restores the shipped template.
- [ ] Lookup order works correctly in Sprint 4's rendering: custom locale → custom fallback locale → shipped default.

### Acceptance-slip settings

- [ ] Toggle, deadline, address, and instructions all editable per-locale.
- [ ] When enabled, Sprint 4's admittance rendering includes page 2 with the customized values.

### Permissions

- [ ] Only `tt_manage_trials` users access either editor.

### No regression

- [ ] Clubs without custom templates continue to use the shipped defaults.
- [ ] Sprint 4's letter generation works identically for clubs that haven't customized anything.

## Notes

### Sizing

~8–10 hours. Breakdown:
- Schema migration for letter templates: ~0.5 hour
- Track editor (list + edit + create + archive): ~2.5 hours
- Letter template editor (source + legend + preview + save): ~3 hours
- Acceptance-slip settings UI + wiring to Sprint 4: ~1.5 hours
- Template-lookup logic in Sprint 4's renderer: ~1 hour
- Testing across locales, custom-vs-default, preview accuracy: ~1.5 hours

### Depends on

- #0017 Sprint 1 (tracks schema)
- #0017 Sprint 4 (letter templates, rendering via `PlayerReportRenderer`, acceptance-slip setting)
- #0010 (multi-language) — if locales beyond en/nl are needed, #0010's translation infrastructure underpins this. For v1 (en + nl only), can ship before #0010.

### Blocks

Nothing within this epic. Ships when ready.

### Touches

- New migration for `tt_trial_letter_templates`
- `src/Shared/Frontend/FrontendTrialTracksEditorView.php` (new)
- `src/Shared/Frontend/FrontendLetterTemplatesEditorView.php` (new)
- `src/Modules/Trials/LetterTemplateRepository.php` (new — encapsulates lookup/fallback logic)
- `includes/REST/Trials_Controller.php` — expand for editor endpoints
- Administration tile group — add two tiles for the editors
- Sprint 4's rendering — modified to use `LetterTemplateRepository::getForKey()` instead of hardcoded defaults
