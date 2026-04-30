<!-- type: feat -->

# #0017 Sprint 3 — Staff input flow: submission, visibility, aggregation, reminders

## Problem

A trial case has multiple assigned staff (head coach, assistant coach, physio — whoever the HoD deemed relevant). Each staff member evaluates the trial player independently during the trial window. Without structure, their inputs either never get written down or get mixed up in emails to the HoD.

The sprint delivers:
- **A per-staff input form** — where an assigned staff member submits their evaluation of the trial player. Uses existing eval categories (per shaping).
- **Visibility rules** — one coach shouldn't see another's input until the HoD explicitly releases it (prevents groupthink and social pressure).
- **Aggregation UI** — a view for the HoD showing all inputs side-by-side, with the synthesis for the decision.
- **Reminder notifications** — assigned staff get a nudge as the trial end approaches if they haven't submitted.

## Proposal

Four pieces:

1. **Staff input form** — `FrontendTrialStaffInputView`. Accessible from the case Staff Inputs tab, visible to assigned staff only. Reuses existing eval category rendering.
2. **Visibility gate** — `tt_trial_case_staff_inputs` table stores submissions. Visible to own user always; visible to HoD always; visible to other assigned staff *only after HoD releases*.
3. **Aggregation view** — Staff Inputs tab shows side-by-side columns (one per submitting staff). Highlights consensus and disagreement across evaluators.
4. **Reminders** — cron-driven emails at T-7 days, T-3 days, T-0 (end date) if staff haven't submitted. Respects WP's notification preferences.

## Scope

### Schema

New table `tt_trial_case_staff_inputs`:
```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
case_id BIGINT UNSIGNED NOT NULL,              -- FK tt_trial_cases
user_id BIGINT UNSIGNED NOT NULL,              -- WP user submitting
submitted_at DATETIME DEFAULT NULL,            -- NULL = draft, non-null = submitted
category_ratings_json TEXT NOT NULL,           -- JSON: {category_id: rating}
overall_rating DECIMAL(3,2) DEFAULT NULL,      -- computed, optional
free_text_notes TEXT,
released_at DATETIME DEFAULT NULL,             -- HoD sets this to mark input as shareable
released_by BIGINT UNSIGNED DEFAULT NULL,
created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
KEY idx_case_user (case_id, user_id),
UNIQUE KEY uk_case_user (case_id, user_id)     -- one input per case per user
```

One row per staff per case. Draft = row exists with `submitted_at` NULL.

### Input form

Surface: `src/Shared/Frontend/FrontendTrialStaffInputView.php`. Accessible via Staff Inputs tab (visible to assigned staff with `tt_submit_trial_input`).

Form structure:
- Read-only header: player name, team, trial track, dates remaining.
- Evaluation category inputs — reuses existing eval categories (per shaping, no trial-specific dimensions). Renders hierarchically, same UX as regular evaluations.
- Free-text notes section.
- Draft autosave via localStorage drafts (from #0019 Sprint 1).
- "Save draft" button (no-op if autosave is working, but gives explicit control).
- "Submit" button — sets `submitted_at`, moves the row from draft to submitted state. Cannot be undone by the staff member (HoD can still edit via a separate admin path if needed).
- If already submitted: read-only view with a small "Request re-open" note ("To edit after submit, ask the HoD").

### Visibility rules

Logic, cleanly centralized in `TrialCaseStaffInputRepository`:

- **Own input**: always visible (draft or submitted).
- **Others' inputs** (same case):
  - If current user has `tt_manage_trials` (HoD): always visible.
  - Otherwise (other assigned staff): visible only if `released_at IS NOT NULL`.
- **HoD release action**: "Release all inputs" button on the aggregation view. Sets `released_at = NOW()` for all submitted inputs on the case. One-way — releasing isn't reversible (but records are still editable by HoD if really needed).

### Aggregation view

Location: Staff Inputs tab for users with `tt_manage_trials`.

Layout:
- Header: "4 of 5 assigned staff have submitted."
- Side-by-side columns (responsive on mobile — see below):
  - Column per submitting staff member.
  - Column header: staff name + role label (if set) + submitted-at.
  - Column content: each category with that staff's rating. Color-coded consistent with `RatingPillComponent`.
- Synthesis row at the top: mean rating per category across submitters, variance indicator if ratings differ significantly (e.g. ≥1 point spread).
- Free-text notes: collapsible per staff.

**Release control**:
- If no inputs released: "Release all to assigned staff" button.
- If released: "Released to staff on [date]" note.
- For HoD only.

**Mobile layout** (<960px):
- Columns stack vertically (one staff's input visible at a time, swipe/navigate between them).
- Synthesis row stays at top, condensed.

### Reminders

New cron job: `tt_trial_input_reminders`, scheduled daily.

Logic:
- For each trial case with status `open` or `extended`:
  - For each assigned staff member without a submitted input:
    - If today is case.end_date − 7: send "Trial ending in 7 days" reminder email.
    - If today is case.end_date − 3: send "Trial ending in 3 days" reminder email.
    - If today >= case.end_date: send "Trial ended — input still needed" reminder.
    - Track sent reminders in `wp_usermeta` with a key per case to prevent duplicate sends.

Email content:
- Subject: "Trial input needed: {player_name} ({days_remaining} days)"
- Body: polite reminder, link to the staff input form, case overview link.
- Respects WP's notification preferences (admins can opt users out of TalentTrack emails via a standard WP plugin hook).

Implementation note: WP-cron is unreliable on low-traffic sites. Fall back to a settings page option ("Send reminders daily at this time") and an admin-triggered "Send reminders now" button.

## Out of scope

- **Real-time collaboration on inputs** — one submitter per input; no co-authoring.
- **Input templates** per staff role (physio sees different categories than head coach). All staff see the same eval categories. Configurable later if demand emerges.
- **Rejecting an input** by HoD. HoD can edit or unsubmit, but can't "reject."
- **Input history / versioning** beyond the single submitted state.
- **Public RSS/feed of pending inputs.** Reminders via email only.

## Acceptance criteria

### Form

- [ ] Assigned staff can open the input form for their assigned cases.
- [ ] Form uses existing eval categories.
- [ ] Draft saves via localStorage and persists across tab close.
- [ ] Submit transitions the row from draft to submitted (`submitted_at` set).

### Visibility

- [ ] Assigned staff sees only their own input until HoD releases.
- [ ] HoD sees all inputs regardless of release state.
- [ ] After release, all assigned staff see all released inputs.
- [ ] Users not assigned to a case cannot see the Staff Inputs tab at all.

### Aggregation

- [ ] HoD view shows side-by-side columns of all submitted inputs.
- [ ] Synthesis row shows mean rating per category.
- [ ] Mobile view stacks columns with swipe/navigate.
- [ ] Release button works; sets `released_at` on all submitted inputs.

### Reminders

- [ ] Daily cron runs and sends T-7, T-3, T-0 reminders to assigned staff without submitted inputs.
- [ ] Duplicate sends are prevented via per-case/per-user meta tracking.
- [ ] "Send reminders now" admin button triggers the same logic for manual testing.

### No regression

- [ ] Normal evaluations (outside trial cases) are unaffected.

## Notes

### Sizing

~12–14 hours. Breakdown:
- Schema migration + repository: ~1.5 hours
- Input form (reusing eval category render): ~2.5 hours
- Visibility logic + tests: ~2 hours
- Aggregation view (desktop + mobile): ~3 hours
- Reminder cron + email templates: ~2 hours
- Draft autosave wiring (reuses Sprint 1 component from #0019): ~0.5 hour
- Testing across roles and visibility states: ~2 hours

### Depends on

- #0017 Sprint 1 (case + staff schema, tab framework)
- #0019 Sprint 1 (drafts, REST)
- #0019 Sprint 2 (list/aggregation component patterns)

### Blocks

#0017 Sprint 4 (decision panel draws on aggregation output).

### Touches

- New migration for `tt_trial_case_staff_inputs`
- `src/Shared/Frontend/FrontendTrialStaffInputView.php` (new)
- `src/Shared/Frontend/FrontendTrialCaseView.php` — add Staff Inputs tab
- `src/Modules/Trials/TrialCaseStaffInputRepository.php` (new)
- `src/Modules/Trials/TrialReminderScheduler.php` (new — cron handler)
- `includes/REST/Trials_Controller.php` — expand for staff input submit/list endpoints
- Email template for reminders (via standard WP `wp_mail`)
