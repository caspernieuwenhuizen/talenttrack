<!-- type: feat -->

# #0017 Sprint 4 — Decision panel + letter generation

## Problem

By Sprint 3, a trial case has all its inputs gathered. What's missing: recording the decision (admit / deny) and generating the letter that communicates it to the parents.

Shaping decision: three distinct letter templates (admit / deny-final / deny-with-encouragement), each with substantively different tone. Optional acceptance-slip page per club (toggle in letter template settings). Letter generation hooks into `PlayerReportRenderer` from #0014 Sprint 3 — no duplicate rendering code.

## Proposal

A Decision tab on the case view where HoD records:
1. The decision outcome.
2. Justification notes.
3. The letter — generated from three templates, previewed, adjustable, then rendered as HTML-print.

On decision recording:
- Player status transitions (`admit` → `active`, `deny_*` → `archived`).
- Case status → `decided`.
- Letter saved as a persisted scout-report-style record in `tt_player_reports` (reusing #0014 Sprint 5's table — the logic for per-report persistence is identical).

## Scope

### Decision tab

Location: new tab on `FrontendTrialCaseView`, visible to users with `tt_manage_trials`.

Contents:

**Pre-decision state** (case status = open / extended):
- Summary of aggregated inputs (pulled from Sprint 3's synthesis).
- Summary of execution view (pulled from Sprint 2's data).
- Decision form:
  - Outcome: radio buttons — Admit / Deny (final) / Deny (with encouragement).
  - Justification text (required, ≥30 chars).
  - Optional: HoD override notes for the letter (pre-filled with standard phrasing, HoD can edit before rendering).
  - "Record decision and generate letter" button.

**Post-decision state** (case status = decided):
- Read-only decision summary: outcome, date, by whom, justification.
- Link to the generated letter (opens in new tab via persisted URL).
- "Regenerate letter" button (creates a new letter version if the HoD needs to adjust — old version revoked, new version active).
- For admit decisions: "Acceptance slip status" — if the club has acceptance-slip enabled, shows whether the slip has been returned (manually marked by HoD: "Acceptance received").

### Three letter templates

Implemented as three new audiences in `PlayerReportRenderer` (extending #0014's audience system):

1. **`TrialAdmittanceLetter`**:
   - Tone: warm, welcoming, forward-looking.
   - Sections: salutation, confirmation of admission, start date of the following season/term, next steps, signatory.
   - Optional page 2: acceptance slip with tear-off-style form (signature line, date, parent name). Toggle per club.
2. **`TrialDenialFinalLetter`**:
   - Tone: respectful, definitive.
   - Sections: salutation, decision, brief rationale (club chose not to offer a place), thanks for participation, well-wishes, signatory.
   - Does NOT say "try again next year" — that's for the encouragement variant.
3. **`TrialDenialEncouragementLetter`**:
   - Tone: respectful, constructive.
   - Sections: salutation, decision, specific positives observed during the trial, areas to develop, explicit invitation to re-apply next season/year, signatory.

Each template uses variable substitution for club-specific and player-specific data:
- `{player_first_name}`, `{player_last_name}`, `{player_age}`
- `{trial_start_date}`, `{trial_end_date}`
- `{club_name}`, `{head_of_development_name}`, `{signatory_title}`
- `{current_season}`, `{next_season}`
- For encouragement letter: `{strengths_summary}` (HoD-written), `{growth_areas}` (HoD-written)

Default template text ships with the plugin in English and Dutch. Clubs customize in Sprint 6.

### Letter generation flow

1. HoD records decision → form validates → writes to `tt_trial_cases` (decision, decision_made_at, decision_made_by, decision_notes), and sets case status = `decided`.
2. Player status transitions atomically: admit → `active`, deny_* → `archived`.
3. Letter is rendered via `PlayerReportRenderer::render(ReportConfig)` where the `ReportConfig` has audience = one of the three trial audiences.
4. Rendered HTML is persisted to `tt_player_reports` (the table from #0014 Sprint 5) with:
   - `audience = 'trial_admittance' | 'trial_denial_final' | 'trial_denial_encouragement'`.
   - `expires_at = NOW() + 2 years` (for retention policy).
   - No `access_token` (letters are not externally shared via scout-style links — they're printed/emailed by the HoD).
   - Base64 photo if included (reuses #0014 Sprint 5's base64 pattern, though less critical here since letters are typically printed locally).
5. Success screen: "Letter generated. [View] [Print] [Download HTML] [Regenerate]."

### Variable-substitution engine

Implemented as a simple `{var}` replacement with a context array:
```php
function apply_template(string $template, array $context): string {
    return preg_replace_callback(
        '/\{([a-z_]+)\}/',
        fn($m) => $context[$m[1]] ?? $m[0],  // leave unknown vars literal for visibility
        $template
    );
}
```

Unknown variables left as literal `{foo}` so the HoD sees which ones need filling (rather than silently blank sections).

### Acceptance slip (optional per club)

- Setting: `tt_trial_admittance_include_acceptance_slip` (boolean, default false).
- When true, admittance letter rendering appends a page 2 with:
  - Club letterhead repeated.
  - Header: "Acceptance of trial offer for {player_name}."
  - Pre-filled: "I confirm acceptance of the trial offer for the {next_season} season."
  - Signature line, date line, parent printed name line.
  - Instructions: "Please return this page to {club_address} by {response_deadline}."
- Tracked status in `tt_trial_cases.acceptance_slip_returned_at` (new nullable column added via migration in this sprint).
- HoD manually marks received ("Acceptance received" button on the Decision tab post-decision). No auto-processing — parents sign on paper.

## Out of scope

- **Emailing the letter** to parents automatically. HoD downloads the PDF/HTML and emails it themselves using their preferred mail client. (Automatic email could be a later enhancement — #0017 doesn't include the scout-style emailed link pattern for trial letters.)
- **Letter template editor** for clubs — Sprint 6.
- **Parent-meeting mode** — Sprint 5.
- **Retracting a decision** — once recorded, decision is final. The case can be re-opened by a new case (rare).

## Acceptance criteria

### Decision recording

- [ ] HoD can pick one of three outcomes (Admit / Deny-final / Deny-encouragement) and submit with justification.
- [ ] Case status → `decided`, decision fields populated correctly.
- [ ] Player status transitions correctly (`admit` → `active`, `deny_*` → `archived`).
- [ ] Case cannot be decided twice (second submission returns an error).

### Letter generation

- [ ] Correct template is used based on the chosen outcome.
- [ ] Default English and Dutch templates render correctly with variable substitution.
- [ ] Unknown variables render as literal `{foo}` for visibility.
- [ ] Rendered letter is persisted to `tt_player_reports` with `expires_at = NOW() + 2 years`.
- [ ] HoD can view, print, download, or regenerate the letter.
- [ ] Regeneration invalidates the previous version (sets `revoked_at`) and creates a new one.

### Acceptance slip

- [ ] With setting off: admittance letter is single-page.
- [ ] With setting on: admittance letter has page 2 with the acceptance slip.
- [ ] HoD can mark acceptance as received post-decision.

### Permissions

- [ ] Only users with `tt_manage_trials` can record a decision.
- [ ] Users without the cap see the Decision tab in read-only mode (or not at all).

### No regression

- [ ] #0014 Sprint 3's `PlayerReportRenderer` continues to work for existing audiences (standard, parent, internal, scout) unchanged.
- [ ] Decisions on non-trial players or players with no open case are properly rejected by the API.

## Notes

### Sizing

~14–16 hours. Breakdown:
- Decision form + tab: ~2 hours
- Three letter template files (HTML + CSS, English + Dutch): ~4 hours
- Audience registration in renderer + variable substitution: ~2 hours
- Persistence to `tt_player_reports`: ~1.5 hours
- Status transition logic (player + case atomically): ~1.5 hours
- Acceptance-slip feature (setting, page-2 template, received-tracking): ~2 hours
- Testing across outcomes, locales, acceptance-slip on/off: ~2 hours

### Depends on

- #0014 Sprint 3 — `PlayerReportRenderer` + `ReportConfig` — HARD DEPENDENCY. Cannot ship before this.
- #0014 Sprint 5 — `tt_player_reports` table. Can piggyback on that schema if it ships first; otherwise this sprint has to create it.
- #0017 Sprints 1–3 (case + inputs).

### Blocks

Sprint 5 (parent-meeting mode uses the generated letter for in-meeting display).

### Touches

- `src/Shared/Frontend/FrontendTrialCaseView.php` — add Decision tab
- `src/Modules/Reports/Audiences/TrialAdmittanceLetterAudience.php` (new)
- `src/Modules/Reports/Audiences/TrialDenialFinalLetterAudience.php` (new)
- `src/Modules/Reports/Audiences/TrialDenialEncouragementLetterAudience.php` (new)
- `src/Modules/Reports/TemplateEngine.php` (new — variable substitution)
- `templates/letters/*.html` (new — default template markup)
- `languages/` — Dutch translations for the default letter text
- Migration: add `acceptance_slip_returned_at` column to `tt_trial_cases`
- `includes/REST/Trials_Controller.php` — decision endpoints
- Settings page: `tt_trial_admittance_include_acceptance_slip` toggle
