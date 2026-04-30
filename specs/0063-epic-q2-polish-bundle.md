<!-- type: epic -->

# #0063 — q2 polish epic: shared patterns, detail pages, in-product comms

## Problem

User testing of the v3.62 stack produced a punch-list of about 70 items. Most aren't independent bugs — they share three or four root patterns:

- "Master-record names in admin tables aren't clickable" → about 15 items, all wanting one universal pattern.
- "Status pills are inconsistent" → activity uses `LookupPill::render`, goals use plain text, PDP uses `tt-status-{x}` CSS classes. Three patterns where there should be one.
- "Picker components are inconsistent" → player edit form has 3 plain text fields for parent contact; player compare uses 4 hardcoded native dropdowns; rate card has a partial picker. Should all use one DOM contract.
- "Wizards are missing a Back button" — `FrontendWizardView` framework-level miss; affects every wizard.

The remaining items are real per-page bugs (PDP conv layout, podium polish, demo Excel template, backup status accuracy, admin menu duplication) that need their own treatment but don't share patterns.

This epic ships a single coherent pass: build the shared patterns once, apply them everywhere, fix the per-page bugs in the same PR.

## Proposal

A four-sprint epic, **single PR, single release** (v3.63.0). The compression is intentional — splitting would mean shipping the foundations sprint without the application sprint, leaving an in-progress codebase visible in production.

### Sprint 1 — foundations (~12-15h)

Build patterns once. Each one's a chokepoint that downstream sprints assume.

- **`RecordLink` helper** — `Shared\Frontend\Components\RecordLink::render($label, $detail_url, $css_class)`. Emits the `tt-record-link` CSS class introduced ad-hoc in v3.62 so the existing my-goals + my-activities usages keep working. Single place for the hover / focus styling.
- **`ParentSearchPickerComponent`** — mirror of `PlayerSearchPickerComponent`, queries `tt_people` where `role_type IN ('parent','guardian')`. Reuses the `.tt-psp` DOM contract + the existing `assets/js/components/player-search-picker.js` hydrator (zero new JS). Includes a "Parent doesn't exist? Create one →" link that launches the new-person wizard with the parent role pre-selected, and returns to the player edit form.
- **Wizard Back button** — single change in `Shared\Frontend\FrontendWizardView`: render a Back button on every step ≥ 2, wired to the existing step-history (`session` storage on the wizard run). Benefits NewPlayer / NewTeam / NewEvaluation / NewGoal / NewActivity / NewPerson at once.
- **Status pill convergence** — refactor goals + PDP onto `LookupPill::render($lookup_type, $key)`. Update the `meta.color` of the `planned` activity-status row to `#dba617` (yellow, not blue). Goal status column on the coach-side admin becomes display-only (today it's plain text, looks editable but isn't).

### Sprint 2 — detail pages + apply patterns (~18-22h)

Net-new frontend detail surfaces:
- `FrontendPlayerDetailView` (`?tt_view=players&id=N`) — read-only public-friendly display: photo + name + age tier + team + position + recent perf + active goals.
- `FrontendTeamDetailView` (`?tt_view=teams&id=N`) — display incl. roster table with **player-status traffic light per row** (closes the player-status visibility complaint), upcoming activities, head/assistant coach + team manager pulled **from `tt_team_people` staff assignments**, chemistry score teaser.
- `FrontendPersonDetailView` (`?tt_view=people&id=N`) — read-only display for staff/parent/scout.
- New-person wizard under `Modules\Wizards\Person\` — basics → role → review. Registered in `WizardsModule`.

URL convention follows the v3.62 precedent (list slug + `?id=N` triggers detail render in the same view), not new dedicated slugs.

Apply `RecordLink` + new pickers everywhere:
- **Players page** — player name → player detail; team → team detail; new parent column → person detail.
- **Teams page** — team name → team detail; head coach (from staff assignments, **not** legacy `tt_teams.head_coach_id`) + assistant + manager → person detail.
- **People page** — name → person detail; email → in-product mail composer (see Sprint 3); roles column wired through `LabelTranslator::roleType()` for NL.
- **Evaluations page** — player + team + trainer linkified; rich filter bar (search + status + player + type + date range) extracted from `PeoplePage` precedent.
- **Activity page** — title moves to second column + linkified; planned-pill yellow per Sprint 1.
- **Goals page** — player + goal title linkified; status pill display-only; "Only coaches" checkbox gets explanatory tooltip.
- **PDP page** — player + status pill; rich filter bar.
- **Podium** — team header in styled wrapper + linkified; cards → player detail; tighten 1-2-3-to-cards spacing.
- **Player compare** — drop the 4 hardcoded slots; use `PlayerSearchPickerComponent` + 2 default slots + "+" button up to 4; aligned column layout.
- **Rate card** — `PlayerSearchPickerComponent` + `required` attribute + inline validation.
- **Player edit form** — replace 3 text fields (guardian_name / guardian_email / guardian_phone) with `ParentSearchPickerComponent` + Dutch translation of the "Connect a parent account" copy.

### Sprint 3 — larger features (~15-20h)

- **PDP conversation tab refactor** — switch from `1fr 280px` sidebar to a tab strip (Conversation | Evidence). Print page gets an in-page Close button.
- **PDP planning back-to-list** — fix the back URL that currently goes one step too far.
- **Team chemistry help + docs** — help button on the chemistry view linking to a new `docs/team-chemistry.md` (EN+NL): how the pitch is rendered, where players come from, how scores are computed, what "everything zero" means, how to populate it. The actual rebuild is parked in `ideas/0062-feat-team-chemistry-rebuild.md`.
- **Player status overhaul** — built on top of `FrontendTeamDetailView`'s roster column from Sprint 2:
  - >100% weight sum → inline warning, no auto-redistribute.
  - Threshold display: render all colour bands inline (red 0-39 / amber 40-59 / green 60+), not just the resolved value.
  - Behaviour + potential capture form on `FrontendPlayerDetailView` (one consolidated entry point).
  - NL translation review pass.
- **Demo Excel template overhaul**:
  - Add missing fields per sheet (Players: +height/weight/photo/parent contacts; Teams: +coach assignments; etc. — full parity with admin forms).
  - New `_README` sheet explaining sheet-by-sheet how to fill, what `auto_key` is and how to use it for cross-sheet linking.
  - Move Demo Data admin page from `Tools → TalentTrack Demo` to `TalentTrack → Configuration → Demo data`.
- **Frontend reports parity** — port the 3 wp-admin-only reports as real frontend views (Player Progress & Radar, Team rating averages, Coach activity), not just admin-link tiles.
- **In-product mail composer** — `?tt_view=mail-compose&to=<email>&person_id=<id>` reachable from the People page email column. Form: To (read-only), Subject, Body, optional template picker. Sends via `wp_mail`. Logs every send to `tt_audit_log` via `AuditService::record('mail_sent', 'person', $person_id, [...])`. Cap: `tt_send_email` (new, granted to admin / club_admin / head_dev / coach). New-person wizard adds the recipient if not yet a person record.

### Sprint 4 — admin menu + miscellaneous + ship (~8-10h)

- WP-admin sidebar:
  - Drop the duplicate Dashboard submenu so WordPress doesn't auto-clone the parent label.
  - Re-group menus per logical buckets (People / Performance / Analytics / Configuration / Access). Welcome + Account fold into Configuration.
- **Module management page redo** — grouped accordion (Core / Performance / Analytics / Configuration / Access / Optional) with one-line description + version-introduced-in line per module.
- **Backup status next-run** — query `wp_next_scheduled()` for the actual cron-event timestamp, display it next to "Last run".
- **Staff overview top-down review filter** — exclude player evaluations from that column.
- **Goals coach-side conversation help** — help button on the goal-detail conversation thread, links to `docs/conversational-goals.md`.
- **NewTeam wizard age-group seed** — migration to seed `age_group` lookup type if empty (U7 through U23 + Senior).
- **NewTeam wizard roster step** — net-new step after Staff: assign players via `PlayerSearchPickerComponent` multi-select.
- **Trial details step grey-out** — visual indicator that step 3 is skipped when path != trial.
- **Help link target** — `target=_blank` so help opens in a new tab instead of replacing the dashboard.
- **Methodology player visibility** — re-verify; expected outcome: no code change (auth is already correct per the v3.61 audit).
- nl_NL.po updates for every new string (with **pre-flight `grep '^msgid'` dedupe check**; this has bitten the last two PRs).
- SEQUENCE.md updated.
- `talenttrack.php` + `TT_VERSION` bump to v3.63.0.
- PR + CI + squash-merge + release.

## Out of scope

- Team chemistry data + pitch + score rebuild — parked at `ideas/0062-feat-team-chemistry-rebuild.md`.
- Mobile-first retrofit of the touched legacy desktop-first views — separate programme #0056.
- Real OTP / WhatsApp Cloud API — separate, parked under #0042 v1.1.

## Acceptance criteria

### Foundations (Sprint 1)
- [ ] Goal status renders as a coloured pill (same `LookupPill` component as activity status).
- [ ] PDP status renders as a coloured pill (same component).
- [ ] `planned` activity status pill is yellow.
- [ ] Every wizard step ≥ 2 shows a Back button that returns to the previous step without losing typed values.
- [ ] Player edit form's parent section uses the new picker; "Connect a parent account" reads in Dutch on `nl_NL`.

### Detail pages + apply (Sprint 2)
- [ ] Clicking a player name anywhere lands on `?tt_view=players&id=N`.
- [ ] Clicking a team name anywhere lands on `?tt_view=teams&id=N`; the team page lists head/assistant/manager from staff assignments.
- [ ] Clicking a person name anywhere lands on `?tt_view=people&id=N`.
- [ ] Evaluations + PDP list pages have a search / status / date filter bar.
- [ ] Player compare opens with 2 slots + a "+" button up to 4; each slot uses the player picker.
- [ ] Rate card refuses to render with no player selected and shows an inline validation message.

### Larger features (Sprint 3)
- [ ] PDP conversation surface has Conversation / Evidence tabs.
- [ ] PDP print page has a Close button.
- [ ] Team chemistry view has a help button → `docs/team-chemistry.md`.
- [ ] Player status: weight sum >100% → inline warning, no auto-redistribute.
- [ ] Player detail page has a behaviour + potential capture form.
- [ ] Demo Excel template has every admin-form field per sheet + a `_README` sheet.
- [ ] Demo Data admin page reachable at `TalentTrack → Configuration → Demo data`.
- [ ] All 3 wp-admin reports also render as frontend tiles.
- [ ] Clicking an email on the People page opens the in-product mail composer; sending via the composer logs an audit row.

### Admin (Sprint 4)
- [ ] WP-admin sidebar has no duplicate "TalentTrack" / "Dashboard" pair.
- [ ] Module management page renders grouped (not one flat list).
- [ ] Backup status shows actual next-run from `wp_next_scheduled`.
- [ ] Staff overview top-down review column shows staff evaluations only.
- [ ] NewTeam wizard age-group dropdown is populated on a fresh install.
- [ ] NewTeam wizard has a Roster step.

### No regression
- [ ] Every existing wizard still completes successfully.
- [ ] Every existing tile / view dispatch still works.
- [ ] `bin/contract-test.php` still passes (REST envelopes intact).
- [ ] `bin/audit-tenancy-source.sh` still passes.

## Notes

### Sizing

| Sprint | Estimate |
| - | - |
| 1 — foundations | ~12-15h |
| 2 — detail pages + apply | ~18-22h |
| 3 — larger features | ~15-20h |
| 4 — admin + ship | ~8-10h |
| **Total** | **~53-67h** as a bundle |

### Hard decisions locked during shaping

1. **Single PR, single release** — split would leave foundations visible without their applications. Compression is the right call.
2. **URL convention reuses v3.62 precedent** — `?tt_view=<list>&id=<N>` triggers detail render. No new slugs.
3. **`RecordLink` emits the existing `tt-record-link` CSS class** — backward-compatible with v3.62 ad-hoc usages.
4. **Status pills converge onto `LookupPill::render`** — drop the parallel `tt-status-{x}` CSS-badge system. One canonical way per concern.
5. **`ParentSearchPickerComponent` reuses the `.tt-psp` DOM contract + JS hydrator** — zero new JS.
6. **In-product mail composer** instead of `mailto:` — explicit user pick. Captures audit log, sends from the academy address, doesn't depend on the coach's mail client. New cap `tt_send_email`, audit action `mail_sent`.
7. **Team chemistry rebuild deferred** — `ideas/0062-feat-team-chemistry-rebuild.md` captures the open work; this PR ships docs + help button as the v1 fix.
8. **Methodology player visibility** — re-verify only, expect no code change.
9. **Demo Excel page moves to Configuration** — old `Tools → TalentTrack Demo` registration removed.
10. **Pre-flight po-dedupe check is mandatory** — last two PRs hit msgid duplicates. Workflow: every new `__()` string runs through `grep '^msgid' languages/talenttrack-nl_NL.po` before commit.

### Cross-references

- **#0042** (v3.58) — established the per-record detail-view pattern at `?id=N`.
- **#0056** — mobile-first retrofit programme; touches some of the same files but not blocking.
- **#0058** — wizard-first standard; new-person wizard follows it.
- **#0060** — persona dashboard; templates may need updating once new detail views exist.
- **#0061** (v3.59-v3.62, three rounds) — same punch-list, three preceding rounds; this is the 4th and meant-to-be-final round.
- **#0062** (parked) — team chemistry rebuild.

### Things to verify in the first 30 minutes of build

- The v3.62 `tt-record-link` CSS class is still present and styled — `RecordLink` reuses it, so a regression there breaks both old and new usages.
- `PlayerSearchPickerComponent::render()` accepts the props the new `ParentSearchPickerComponent` expects to match.
- `LookupPill::render` reads `meta.color` correctly — Sprint 1's status convergence depends on it.
