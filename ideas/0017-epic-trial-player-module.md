<!-- type: epic -->

# Trial player module — onboarding, tracked trial period, multi-staff input, parent meeting deliverables

Raw idea:

At our academy we have regular test players, and other academies do too. I want a specific place for that. Test player onboarding, monitoring, reviewing, judging, deciding, communicating should all be covered. This can be used in discussion with the player after sessions or after the trial period when parents are there. I want to be able to set up trial tracks — length in weeks, sessions/activities. Gather input from other staff (to be assigned to the trial case for a player), and be able to present an end report, an admittance letter, or denial letter (both printable A4 portrait) with the right tone and proper language.

## Why this is an epic

A real workflow with a beginning, middle, and end. Onboarding (new player intake + trial track selection), execution phase (monitored sessions, staff inputs collected over N weeks), decision phase (multi-voice review + go/no-go), and outcome phase (letter generated + delivered + next-step triggered on either branch). Plus a trial-track templating layer on top. Four sprints minimum.

## What already exists vs what's new

**Exists and should be reused:**

- **"Trial" player status.** `PlayersPage.php:126` has `'trial' => 'Trial'` as one of the four player statuses (active / inactive / trial / released). Today it's just a dropdown value with no structure behind it. This module gives it structure: when a player is moved to Trial status, a trial case opens automatically.
- **Functional roles.** `src/Modules/Authorization/Admin/FunctionalRolesPage.php` already handles per-player staff assignments. Reuse this pattern for "assigned to trial case for player X" — don't invent a parallel assignment system.
- **Sessions, attendance, evaluations, goals.** All existing. A trial case is a view layered over these, not a replacement.
- **PlayerReportView + the report-wizard work from #0014.** The printable A4 infrastructure is already there (`PlayerReportView`) and #0014 generalizes it into audience-driven templates. Trial admittance/denial letters become two more audiences in that same system rather than a separate print pipeline.
- **Soft archive.** Migration 0010 gives us `archived_at`. A closed trial case (admitted or denied) gets archived, stays queryable for history.

**New:**

- Trial track templates (the "6-week goalkeeper trial" recipe that can be instantiated per player).
- A trial case object that ties a player + track + start date + assigned staff + decision + generated letter together.
- A decision phase with structured input from multiple staff (not just free-text).
- Three new report audiences (or audiences + status variants — see "Letters" below): end report, admittance letter, denial letter.
- Parent-meeting mode for the in-person conversation.

## The flow, end to end

1. **Intake.** Head of Development (or an admin with the right capability) creates a new trial case. Select existing player or create a new one. Pick a trial track template (or skip and go freeform). Assign a primary coach (the "case owner"). Optionally assign additional staff for input — one or more assistant coaches, scouts, keeper coach, physical coach, whoever. Case start date defaults to today; end date computes from the track length. Status: `active`.
2. **Execution.** Over N weeks, the player attends sessions like any other. Evaluations get entered, attendance is recorded, goals may be set. The trial case page aggregates all of this in one view for the assigned staff. No separate data entry — everything reuses the existing session/evaluation/goal flows, just filtered to this case's date range and player.
3. **Staff input collection.** At any point (but especially near the end), each assigned staff member fills in their structured input form: ratings on N dimensions (reused from eval categories — technical, tactical, physical, mental — *or* a trial-specific set, see open questions), a free-text recommendation, a go/no-go lean. Staff can see that others have contributed but not what they said until the case owner releases the synthesis (prevents echoing). Reminders go out via email/notification if a staff member hasn't submitted by T-3 days.
4. **Synthesis + decision.** Case owner opens the decision panel: sees all staff inputs side-by-side, rating aggregates (spider chart, averages, vote tally on go/no-go), and the player's raw evaluation/attendance data from the execution phase. Case owner writes the formal recommendation and records the decision: `admitted` / `denied` / `extended` (trial period extended — a third option not in the raw idea but practically necessary).
5. **Letter generation.** Wizard walks through tone + field selection, produces an A4 portrait PDF-print-ready letter in the configured language. Two templates: admittance letter (welcome to the academy, start date, next steps, team assignment) and denial letter (thanks for the trial, feedback, best wishes — careful tone). Also generates the internal end report (multi-page, all inputs, for the case file).
6. **Delivery + close.** Letter gets printed or emailed to parents. Parent-meeting mode (see below) walks the case owner through the meeting. Case gets archived; player status transitions to `active` (with team assignment) or `released` based on the decision. On `extended`, a new case is spawned that reopens the window — retains history from the prior one.

## Trial tracks (templating)

The raw idea specifies "length in weeks, sessions/activities." Modeled as:

```
tt_trial_tracks
  id, name, slug, description, duration_weeks,
  min_required_sessions (optional threshold for valid trial),
  default_staff_roles_json (list of functional roles to pre-assign),
  evaluation_schedule_json (optional: week-2 mid-review, week-5 final),
  archived_at, archived_by

tt_trial_cases
  id, player_id, track_id nullable, owner_user_id,
  start_date, end_date, 
  status ENUM('active','decision-pending','admitted','denied','extended','cancelled'),
  decision_at, decision_by, decision_notes TEXT,
  generated_letter_filename nullable, 
  archived_at, archived_by, created_at, updated_at

tt_trial_case_staff
  id, case_id, user_id, functional_role, 
  input_submitted_at nullable, 
  input_ratings_json nullable, 
  input_recommendation TEXT nullable, 
  input_vote ENUM('go','no-go','abstain','undecided') nullable

tt_trial_case_milestones  (optional — only if evaluation_schedule_json points at them)
  id, case_id, kind ENUM('mid-review','final-review','custom'),
  scheduled_date, completed_at, notes TEXT
```

Tracks are templates. Cases are instances of tracks. A case can also exist without a track (freeform) — not every academy works with rigid programs.

Default tracks shipped with the plugin (sample data, editable):

- "Standard 6-week trial" — 6 weeks, 2 evaluations required, primary coach only assigned by default.
- "Scout-referral fast track" — 3 weeks, condensed, mid-review at week 2.
- "Goalkeeper-specific trial" — 6 weeks, keeper coach required as assigned staff.

## Letters — the tone and language requirements

This is the part the raw idea calls out specifically: "printable on A4 portrait with the right tone and proper language." Non-trivial because these letters have real emotional weight. A poorly-worded denial letter can damage the academy's reputation; a too-corporate admittance letter feels cold for a 12-year-old's proudest moment.

### Three deliverables per case

1. **End report (internal).** Multi-page A4. All staff inputs verbatim, rating aggregates, session stats, attendance. For the case file, not for the family. Tone: professional / clinical.
2. **Admittance letter.** Single A4 page. Warm, specific, forward-looking. Includes: what struck us positively, team assignment, start date, practical next steps (kit, first training date, parent contact). Signed by head of development.
3. **Denial letter.** Single A4 page. Kind but clear. Includes: acknowledgment of effort during trial, specific observation (at least one honest positive), the decision, a forward-looking line (what to work on / encouragement). Absolutely does not include rating numbers — that's the wrong level of detail for a refusal and invites argument. Signed by head of development.

### Templates + variables

Letter templates are editable admin-side (per academy — clubs want their own voice). System ships with two well-written defaults per letter type (neutral + warm). Variables available:

- `{player_first_name}` `{player_full_name}` `{player_age}` `{player_position}`
- `{trial_start_date}` `{trial_end_date}` `{trial_duration_weeks}`
- `{assigned_team}` (admittance only) `{first_training_date}` (admittance only)
- `{positive_note}` — case owner writes this one per letter. Required for denials; enforced via a prompt when generating.
- `{club_name}` `{club_address}` `{head_of_development_name}` `{head_of_development_signature_image}`

### Language

The multi-language work from idea #0010 covers the template strings (Dutch, English, French, German, Spanish). The *content* of the letter is a different thing: even if the template is in Dutch, the case owner may need to write `{positive_note}` in Dutch with a particular warmth. No automation there — it's a person writing to a family. Template language + human-written custom blocks, that's the right split.

### AI-assisted drafting (optional, flagged)

An obvious extension: the system has all the evaluation data, it could draft the `{positive_note}` block automatically. Tempting but risky. A miscalibrated AI phrase in a denial letter is a serious harm — the parent reads it with full human attention. Don't ship AI-drafted letter content on day one. Revisit after the feature has been in production for a season and we have a sense of tone drift. If/when we do ship it, the drafted text is always a *suggestion for the case owner to edit*, never auto-inserted.

### Print mechanics

Reuse the print pipeline from `PlayerReportView` — render HTML, let the browser print. Idea #0014 generalizes this into `ReportConfig` with audience-driven templates. Trial letters become three new audiences in that same framework:

- `TrialEndReport` — internal, multi-page
- `TrialAdmittanceLetter` — single page, warm
- `TrialDenialLetter` — single page, careful

No new print infrastructure needed. Same dependencies on the branding module from #0011 (club name, logo, signature image).

## Parent-meeting mode

The raw idea mentions using this "in discussion with player after sessions or after the trial period when parents are there." That's a specific UI need — a view that's appropriate to show on a laptop/tablet in the room with people watching.

### What parent-meeting mode is

- A single-screen summary of the trial case, sanitized for family viewing.
- Hides raw rating numbers (too clinical for a conversation, invites comparison to other kids).
- Shows qualitative strengths + development areas as bullet points.
- Shows sessions attended, coach observations (filtered — no staff private remarks).
- Includes the same photo, name, team context as the rebuilt "My profile" view from #0014.
- Toggleable: "show ratings" button unlocks the admin-facing view if the case owner wants to go deeper.

### What parent-meeting mode is not

Not a separate data source. Everything on this screen is already in the case, just with a presentation filter. The filter logic is a small set of rules; the UI is the work.

### Where it lives

New tab on the trial case page, next to "Staff inputs" / "Decision" / "Letters." Launches fullscreen (no wp-admin chrome) so nothing on screen accidentally reveals other players' info during the meeting.

## Decision + communication workflow

### Who can do what

| Action | Role / capability |
| --- | --- |
| Create trial case | `tt_head_dev`, admin |
| Assign staff to case | case owner, `tt_head_dev`, admin |
| Submit staff input | assigned staff only |
| See other staff's inputs | case owner + head_dev, only after owner "releases synthesis" |
| Record decision | case owner or head_dev |
| Generate letters | case owner, head_dev, admin |
| Edit letter templates (per club) | admin |

New capabilities: `tt_manage_trials`, `tt_submit_trial_input`, `tt_view_trial_synthesis`. Map onto existing roles, don't invent new roles.

### Notifications

- Staff assigned to a case: email when assigned, reminder at T-7 and T-3 days if input not submitted.
- Case owner: notification when each staff input comes in; summary when all are in.
- On decision recorded: no automatic email to parents — the letter is human-reviewed and physically/manually sent. Automating parent comms here is a boundary to respect.

## Integrations with other ideas

This epic is surprisingly dependent on other ideas in the backlog. Worth spelling out:

- **#0010 (multi-language).** Letter templates need FR/DE/ES/NL versions at minimum, EN as fallback. Add to the translation scope there.
- **#0011 (monetization).** Could be an Academy-tier feature (reasonable — smaller clubs don't have multi-staff trial boards). Or part of the core. Tier decision is monetization's call, but flagging so it's not forgotten.
- **#0012 (professionalize).** Letters are public-facing text; they need the same "no AI fingerprints" copy-editing discipline as readme/docs. Especially the default templates — this is the most visible prose in the plugin.
- **#0013 (backup).** Trial cases contain sensitive case-file data (kid didn't make it, here's why). Backup scope already covers all tt_* tables; the new tables above inherit that. But the `generated_letter_filename` points at a file on disk — that file isn't in the DB, so backup needs to either inline or explicitly follow PDF file references. Flag for the backup implementation.
- **#0014 (profile + reports).** Reusing `ReportConfig` + audience-driven templates means trial letters depend on #0014 shipping Sprint 3 (generalize the renderer) before this epic's letter sprint. Order matters.
- **#0016 (photo-to-session).** If a coach photographs a session during a trial, the draft session is attributed to the trial case automatically when the case is active for that player. Nice-to-have linkage, not blocker.

## Decomposition / rough sprint plan

1. **Sprint 1 — schema + case CRUD + track templates.** `tt_trial_cases`, `tt_trial_tracks`, `tt_trial_case_staff`. Admin page to create/view/list cases. Status transitions. Assignment UI reusing the FunctionalRoles pattern.
2. **Sprint 2 — execution phase: aggregated case view.** The page that pulls together sessions, attendance, evaluations, goals for a trial case across its date range. No new data, just smart filtering of existing tables. Proves the "nothing duplicates" design.
3. **Sprint 3 — staff input flow.** Input form, visibility rules (don't show others' inputs until released), aggregation UI, reminder notifications.
4. **Sprint 4 — decision panel + letter audiences.** Decision recording, end-report / admittance / denial templates as new audiences in #0014's `ReportConfig` framework. Default template text (written carefully — see "Letters" above). Letter variable substitution engine.
5. **Sprint 5 — parent-meeting mode.** Sanitized single-screen view. Fullscreen launch. Feature-flagged so it can ship later if Sprint 4 slips.
6. **Sprint 6 — track templates shipped with plugin + admin template editor.** Seed data for standard / scout / goalkeeper tracks. UI for clubs to edit letter templates in their own voice.

Sprints 1–4 are the core feature. Sprints 5–6 are polish/completeness. Could ship after sprint 4 as a v1 and iterate.

## Open questions

- **Evaluation dimensions for trial input.** Reuse existing eval categories (technical/tactical/physical/mental) so the trial data feeds naturally into later player evaluations once admitted, or define trial-specific dimensions (coachability, fit-with-group, raw potential — things that matter for admission more than ongoing development)? Probably both: existing categories + a trial-specific "overall fit" dimension. Worth validating with a real head of development.
- **"Extended" trials — how many extensions before it counts as a denial by exhaustion?** Suggest: two extensions max before the system forces a decision. Otherwise "extended" becomes a way to avoid saying no.
- **Denial-letter tone on borderline cases.** When a kid was close but not in, the denial needs to leave the door open ("we encourage you to try again next season" vs "we cannot offer a place"). A third template variant for borderline, or a single template with a toggle? Single template with a "leave door open" / "final decision" toggle is cleaner.
- **Parent signature / acceptance.** Does the admittance letter need a returnable acceptance form (parent signs, sends back, that's how we know they accepted the spot)? Common in competitive academies. If yes: generate a two-page PDF, second page is the acceptance slip. Worth asking actual academies whether they do this.
- **Multiple concurrent trials per player.** Almost never needed, but what if a kid does a field-trial and then a goalkeeper-trial? Model supports it (multiple rows in `tt_trial_cases` for the same player_id), but do we want to expose "trial history" on the player profile? Yes — it's valuable context for future coaches.
- **What happens to a denied player's data.** GDPR question. The player existed in the system briefly, got denied, is no longer active. Retention policy: retain the case file for N years (common academy practice is 2-3 years in case they re-apply), then hard-delete. This intersects with #0013's backup scope and #0011's privacy statement work.
- **Public-facing page for trial applications.** Does the plugin also handle the *application form* on the club website (parent fills a form → creates an initial player + trial case)? That's a whole new surface (public forms, spam protection, email verification). Probably out of scope for this epic, flag for a separate idea later. For now: head of development creates the case manually after phone/email contact.
- **Multi-academy / multi-location clubs.** Big clubs have multiple youth academies. Does a case belong to a location? Current schema doesn't model location at all — that's a bigger change. Out of scope; flag as a future consideration.

## Touches

New module: `src/Modules/Trials/`
- `TrialsModule.php`
- `Admin/TrialsPage.php` — list + filter + bulk actions
- `Admin/TrialCaseView.php` — the per-case page with tabs (Overview / Execution / Staff Inputs / Decision / Letters / Parent Meeting)
- `Admin/TrialTracksPage.php` — track template editor
- `Admin/StaffInputForm.php` — the per-staff input screen
- `Admin/LetterTemplatesPage.php` — admin-editable letter text per club
- `Shared/ParentMeetingView.php` — the sanitized fullscreen view

Schema: new tables as described above. No changes to existing tables except `tt_players.status` gains a narrower "trial" semantic (already exists as a value; now it also implies an open `tt_trial_cases` row).

Audiences (extends #0014): `TrialEndReportAudience`, `TrialAdmittanceLetterAudience`, `TrialDenialLetterAudience` in `src/Modules/Reports/Audiences/`.

Capabilities (in `Activator.php`): `tt_manage_trials`, `tt_submit_trial_input`, `tt_view_trial_synthesis`. Default assignment: `tt_manage_trials` → head_dev + admin; `tt_submit_trial_input` → any assigned user; `tt_view_trial_synthesis` → case owner + head_dev.

Docs: new `docs/trials.md` + translations in each `docs/<locale>/` folder from #0010.

Integration points:
- `src/Modules/Authorization/Admin/FunctionalRolesPage.php` — reuse for assignment UI
- `src/Modules/Evaluations/` — read-only consumer of evaluations within a trial case's date window
- `src/Modules/Sessions/` — same
- `src/Modules/Goals/` — same
- `src/Shared/Frontend/FrontendMyProfileView.php` (via #0014's rebuild) — show "trial period: [dates]" banner on a trialing player's own profile

New settings page entries: default trial track, reminder timing (T-7 / T-3 defaults), letter signatory (head of development's name + signature image upload), retention policy for closed trial cases.
