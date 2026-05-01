<!-- type: epic -->

# #0071 — Authorization matrix completeness, sub-cap split, and HoD redefinition

> Originally drafted as #0069 in the user's intake (uploaded as a Word doc in commit `aa8575c`). Renumbered on intake — #0069 was already taken by the v3.68.0 HoD academy-wide bypass + tile `hide_for_personas` polish bundle. Excel companion lives at `docs/authorization-matrix-extended.xlsx`.

Sequel to **#0033** (the original matrix epic). Where #0033 built the matrix infrastructure and seeded a strawman, this epic closes the gaps between what the seed contains and what the codebase actually exposes, splits the over-coarse `tt_*_settings` capability family into per-area sub-caps, and locks in an editorial decision narrowing the Head of Development persona to a development-focused, read-mostly admin role.

## Problem

Three concrete problems, each blocking matrix-driven authorization from being a real source of truth rather than shadow data.

1. **Coverage gap.** A two-pass walk through `src/Modules/`, `src/Shared/CoreSurfaceRegistration.php` (the canonical list of frontend surfaces by `view_slug`), and `config/authorization_seed.php` surfaces ~60 entities/surfaces that the codebase enforces today via `current_user_can()` but the seed never references. The first audit pass missed roughly half of these — the round-2 pass walked every `view_slug` registration, every REST controller, and every Repository class to surface the rest. Worst-case examples are sensitive: `tt_view_player_medical` and `tt_view_player_safeguarding` exist in `JourneyModule` but the matrix has no `player_injuries` or `safeguarding_notes` rows; `player_potential` ratings (a sensitive HoD-only cross-axis grading) have no row at all; the entire trial-letter-templates editor and the per-club trial-tracks editor are unmodelled. **A persona could be granted "no access" to medical data via the matrix and still see it because the matrix doesn't know the entity exists.**

2. **The settings cap is one knob for sixteen things.** `tt_view_settings` and `tt_edit_settings` gate the entire Configuration page, plus Roles & rights, Functional Roles, Permission Debug, PDP Seasons, Onboarding, Stats usage pages, Audit Log, Migrations, Custom Fields, Evaluation Categories, Translations, the rating scale, and a long list of REST controllers — **156 cap checks across 39 files**. The matrix already splits Configuration into nine sub-entities (`lookups`, `branding`, `feature_toggles`, `audit_log`, `custom_field_values`, `custom_field_definitions`, `migrations`, `setup_wizard`, `evaluation_categories`) but because the live code calls `current_user_can('tt_edit_settings')`, those per-tab matrix rows are shadow data — nothing consults them. There is no way today to express "this user can manage lookups but not branding" because there is no `tt_edit_lookups` capability.

3. **HoD is over-privileged in the seed.** The original seed gave Head of Development write rights on Reports, Workflow Templates, Documentation, Spond, Persona Templates, Translations Config, Settings, Custom Field Values, Feature Toggles, Branding, Bulk Import, Team Chemistry, and Dev Ideas (RCD on most). This conflates "the person who oversees player development across the academy" with "the person who configures the system". Editorial review of the matrix narrows HoD to a development-focused persona that is read-mostly outside the player-development surfaces. Academy Admin remains the only persona with edit rights on configuration. This is a behaviour change that must be expressed in the seed, the access-control docs, the migration preview UI, and the role mapping.

A reviewed authorization matrix exists as the source of truth for this epic — see [`docs/authorization-matrix-extended.xlsx`](../docs/authorization-matrix-extended.xlsx) (committed alongside this spec). The Excel matrix is the canonical artifact for what the post-epic state should be; the seed file and the cap layer must be brought in line with it.

## Proposal

A single epic with **five child specs**, sequenced because each later child reads from the previous one's output. Implement in order; each child can ship independently after the prior lands (with one exception — see Sequence of merging).

### The five children

1. **`feat-matrix-coverage`** — new entities added to the seed, matching the gaps surfaced in the *Gaps & Proposals* sheet of the reviewed matrix. Adds rows for Journey, Trials (full workflow), StaffDevelopment, Threads, Push, Spond, PersonaDashboard, CustomCss, Translations, plus per-module sub-entities (Methodology football_actions, PDP seasons, Authorization changelog, People my-person). Capability declarations and `LegacyCapMapper` entries land in this child for the sub-set of new entities that need bridging.

2. **`feat-settings-subcaps`** — splits `tt_view_settings` / `tt_edit_settings` into per-area sub-caps (`tt_view_lookups` / `tt_edit_lookups`, `tt_view_branding` / `tt_edit_branding`, etc.). Refactors every Configuration tab handler and every Settings-adjacent page to gate on the specific sub-cap. Updates `LegacyCapMapper` so each new cap routes to its matrix entity. Old `tt_*_settings` caps stay registered as roll-ups via `CapabilityAliases` for backwards compatibility with custom code, but become derived rather than primary. Migration seeds the new caps onto existing role holders to guarantee no user loses access on upgrade.

3. **`feat-persona-hod-narrowing`** — applies the editorial change: Head of Development becomes a development-focused, read-mostly admin persona. Updates `config/authorization_seed.php`, `RolesService::install_caps`, and the docs. Adds a one-time migration `0051_hod_persona_narrowing` that adjusts existing HoD users' caps to match the new defaults, with an opt-out config flag for installs that want to preserve the old behaviour. The Migration Preview already surfaces the diff per-user; this child wires the seed change through that surface so the operator can see exactly which caps will be revoked before clicking Apply.

4. **`feat-player-status-visibility-toggle`** — a per-club feature toggle controlling whether `player_status` (the colour dot) is visible to players and parents. **Default OFF on fresh installs** (HoD opts in); existing installs carry forward today's visible behaviour via the upgrade migration. Implemented as a runtime override on top of the matrix at the REST and render layers — the matrix retains player and parent grants on `player_status` as the permission *intent*, and the toggle expresses club *policy* on top. Does not touch the matrix itself; does not touch the cap layer; does not affect `player_status_breakdown` (which is staff-only by existing design regardless of toggle).

5. **`feat-user-impersonation`** — native admin-to-user impersonation for testing and support. Academy Admin (and only Academy Admin) can switch into any other user within their club, see exactly what that user sees, and switch back. Every switch is logged to a new dedicated table `tt_impersonation_log` for full audit trail. Integrates with all of TalentTrack's authorization layers: `PersonaResolver`, `MatrixGate`, `CurrentClub`, the cap bridge, the active-persona picker, and the feature-toggle layer all behave correctly because they all consult `get_current_user_id()`. Provides a visible "You are impersonating X" banner that cannot be dismissed and a persistent "Switch back" affordance.

### Sequence of merging

- Coverage first — adds rows but doesn't change cap behaviour.
- Sub-caps second — HoD narrowing references entities the sub-caps create (`tt_view_lookups` etc).
- HoD narrowing third — must ship in the **same release** as sub-caps; the seed change refers to caps the sub-caps child introduces. *Do not split across releases.*
- Status-visibility toggle fourth — small isolated change; benefits from matrix + docs being in their post-narrowing state.
- Impersonation last — most-touched-by-everything-else; benefits from stable matrix vocabulary, stable cap layer, and stable persona definitions.

### The granularity decision

The user chose **Option 1 — sub-caps per tab** from the three options on the *Settings — granularity* sheet:

> "We go for the best long term solution that creates least confusion."

Rationale supporting that choice: Option 2 (matrix-direct enforcement) creates two enforcement models in the same codebase, which the next developer has to learn twice. Option 3 (UI hide only) is not real security — a determined user with `tt_edit_settings` can URL-poke their way past it. Option 1 makes the matrix's per-tab rows actually mean what they say, costs roughly 50–80 file touches (most are 1–2 lines), and is reversible (the new caps stay in WP's cap registry; rolling back is removing the new caps). The `CapabilityAliases` pattern that already maps `tt_manage_players` → `tt_view_players` + `tt_edit_players` provides the precedent.

## Scope of the five children

### 1. `feat-matrix-coverage`

The seed gets the missing entity rows it should have had at #0033. **No new caps yet** — that's the next child. This child is purely additive on the matrix side.

#### New seeded entity rows

The reviewed matrix is the contract. Two passes — round 1 (initial surfacing) and round 2 (the more thorough audit driven by the user pushing back on completeness).

**Round 1 — initial pass:**

- **Methodology** — `football_actions` (separate top-level menu, currently gated only by `tt_view_methodology` / `tt_edit_methodology` but not in the seed).
- **Pdp** — `seasons` (currently gated by `tt_edit_settings` which is wrong — seasons are a PDP concern, not a configuration concern; the cap-gate fix lands in the next child).
- **Trials** — `trial_inputs`, `trial_synthesis`, `trial_decisions` added; existing `trial_cases` extended to cover Head Coach, Head of Development, Academy Admin (today scout-only).
- **Journey** — `player_timeline`, `player_injuries`, `safeguarding_notes`, `cohort_transitions` added. Injuries and safeguarding rows are the highest-priority gap because they expose sensitive minor data; per-cell scope follows the reviewed matrix verbatim, with safeguarding deliberately invisible to parents (per `CLAUDE.md` privacy note that safeguarding may concern the parent themselves).
- **StaffDevelopment** — `staff_development`, `staff_certifications`, `staff_mentorships`.
- **Threads** — `thread_messages` (polymorphic conversation primitive; permissions follow the parent entity).
- **Push** — `push_subscriptions` (every persona RCD self).
- **Spond** — `spond_integration`.
- **PersonaDashboard** — `persona_templates`.
- **CustomCss** — `custom_css` (Academy Admin RCD global, no other persona).
- **Translations** — `translations_config`.
- **People** — `my_person` (staff equivalent of player's `my_profile`).
- **Authorization** — `authorization_changelog`.

**Round 2 — surfaced after pushback on completeness:**

- **Trials sub-surfaces** — `trial_letter_templates` (per-club admittance / denial letter templates, `?tt_view=trial-letter-templates-editor`), `trial_letters_generated` (letters persisted in `tt_player_reports` for 2 years), `trial_tracks` (per-club track templates driving trial duration & milestones, `?tt_view=trial-tracks-editor`), `trial_case_staff` (assignment of evaluators to a case), `trial_extensions` (extending a case duration), `trial_reminders` (cron run + manual trigger).
- **Pdp sub-surfaces** — `pdp_planning` (HoD per-team×per-block planning matrix, `?tt_view=pdp-planning`), `pdp_conversations` (separate REST gate from files / verdicts), `pdp_calendar_export` (.ics / native calendar export, self-scoped), `pdp_evidence_packet` (PDF bundle of evaluations + goals + journey for a season — full player history, sensitive).
- **StaffDevelopment sub-surfaces** — `staff_overview` (HoD/admin dashboard at `?tt_view=staff-overview`), `my_staff_pdp`, `my_staff_goals`, `my_staff_evaluations`, `my_staff_certifications` (each a staff member's self-scoped equivalent of the player's "my-*" surfaces).
- **Journey** — `my_journey` (player's own journey at `?tt_view=my-journey`, distinct from the team-level read).
- **Methodology** — `player_status_methodology` (per-age-group player-status calc config at `?tt_view=player-status-methodology` — currently gated `tt_edit_settings` which is wrong; this is a methodology concern).
- **Players** — `player_potential` (sensitive cross-axis HoD-only ratings driving status calc), `player_behaviour_ratings` (coach-recorded behaviour qualities, also feed status calc), `player_status` (the colour-dot read; broadly visible but a deliberate authorization point because the dot itself reveals a sensitive judgement), `player_status_breakdown` (the per-input numerics behind the dot — restricted to coaches + HoD + admin per `PlayerStatusModule::ensureCapabilities`; parents and players see the colour but never the math).
- **Reports** — Scout sub-system — `scout_access` (HoD assigning players to scout users, gated `tt_generate_scout_report`), `scout_my_players` (scout's view of their assignments), `scout_history` (log of scout-link views), `scout_token_links` (chrome-free token-auth public viewer — no persona, token IS the auth, listed for completeness).
- **Workflow** — `task_completion` (the per-template assignee gate when a user submits a task form; distinct from `workflow_templates` which is the template editor).
- **Invitations** — `invitations_config` (per-persona message templates + SMTP config, `?tt_view=invitations-config`, gated `tt_manage_invite_messages`).
- **Authorization sub-surfaces** — `functional_roles_admin` (`FunctionalRolesPage` — defining the roles), distinct from `functional_role_assignments` (assigning users to them); `permission_debug` (`DebugPage` diagnostic surface); `matrix_preview_apply` (`PreviewPage` — wp-admin Apply button that writes the matrix into the live cap layer).
- **Stats** — `usage_stats_details` (per-club drill-down on the rolled-up `usage_stats`).

#### Capability declarations

A subset of the new entities need new caps in `RolesService::install_caps` because today they're either silently un-gated or piggy-back on a shared cap.

**Already declared in module-level `install_caps()` — just bridge:**

- `tt_view_player_medical`, `tt_view_player_safeguarding` (`JourneyModule`).
- `tt_manage_trials`, `tt_submit_trial_input`, `tt_view_trial_synthesis` (`TrialsModule`).
- `tt_view_staff_development`, `tt_view_staff_certifications_expiry` (`StaffDevelopmentModule`).
- `tt_admin_styling` (`CustomCssModule`).
- `tt_edit_persona_templates` (`PersonaDashboardModule`).
- `tt_generate_scout_report` (`ReportsModule`).
- `tt_view_pdp` / `tt_edit_pdp` / `tt_edit_pdp_verdict` (`PdpModule`, already split — they need to also gate the round-2 PDP sub-entities).
- `tt_configure_workflow_templates` / `tt_manage_workflow_templates` (Workflow — already split).
- `tt_view_methodology` / `tt_edit_methodology` (already split — needs to additionally gate `player_status_methodology` and `football_actions`, both of which currently fall through to `tt_edit_settings` in error).
- `tt_manage_invite_messages` (already covers `invitations_config`).

**New caps to introduce:**

- `tt_view_thread`, `tt_post_thread` — Threads has ad-hoc gates today via `tt_edit_evaluations` and `tt_view_settings`.
- `tt_view_spond`, `tt_edit_spond_credentials` — Spond piggy-backs on `tt_edit_teams`.
- `tt_view_player_timeline` — Journey timeline currently has no cap gate beyond the per-event-type filters.
- `tt_view_authorization_changelog` — currently piggy-backs on `tt_view_settings` inside `MatrixPage`.
- `tt_view_player_potential`, `tt_edit_player_potential` — currently no gate; reads happen wherever the status calc runs.
- `tt_view_player_behaviour_ratings`, `tt_edit_player_behaviour_ratings` — same.
- `tt_view_player_status` and `tt_view_player_status_breakdown` already exist in `PlayerStatusModule::ensureCapabilities()` — just add the matrix bridge for both. The two-level split (dot vs numerics) is already enforced in `PlayerStatusRestController::playerStatus()` at line 92.
- `tt_view_pdp_evidence_packet` — exporting an evidence packet contains everything about a player; should be its own cap, not a piggy-back on `tt_edit_pdp`.
- `tt_view_pdp_planning` — read of cross-team planning. Today gated `tt_edit_pdp`; that's a writable cap and over-grants. Splitting the read off lets a Team Manager hold view-only access in v2.
- `tt_view_player_status_methodology`, `tt_edit_player_status_methodology` — fixes the wrong-gate today (`tt_edit_settings`).
- `tt_view_functional_roles`, `tt_manage_functional_roles_admin` — distinct from `tt_manage_functional_roles` which is the assignment cap (already exists).

#### LegacyCapMapper extension

Every new entity gets a row in the `MAPPING` constant, and every new cap routes to the right `(entity, activity)` tuple.

#### Tests

Unit test asserting every entity in the seed has a corresponding `LegacyCapMapper` entry in at least one direction (`cap → entity` OR entity-only via direct `MatrixGate::can` call). Catches drift when someone adds an entity to the seed and forgets the bridge.

#### Labels & terminology cleanup — Functional Roles

The current single label "Functional Roles" is used for two genuinely different surfaces. Rename:

| Surface | URL | Old label | New label |
|---------|-----|-----------|-----------|
| Catalogue / type editor (defines WHAT roles exist + their auth-role mappings) | `wp-admin admin.php?page=tt-functional-roles` AND frontend `?tt_view=functional-roles&tab=types` | "Functional Roles" | **"Functional Role Definitions"** |
| Per-team assignment editor (binds a role to a `(team, person)` tuple) | frontend `?tt_view=functional-roles&tab=assignments` | "Functional Roles" | **"Functional Role Assignments"** |

URL slugs (`tt-functional-roles`, `?tt_view=functional-roles`) stay unchanged for back-compat with bookmarks. Only labels move.

The frontend view's header becomes tab-aware: `?tt_view=functional-roles&tab=assignments` shows "Functional Role Assignments" as the H1; `&tab=types` shows "Functional Role Definitions". The intro paragraph for each tab is rewritten to make the distinction explicit:

- Assignments page reads "Per-team staff bindings: head coach of U14, assistant of U16, etc. Every assignment is on a specific team — there is no league-wide 'coach' assignment."
- Definitions page reads "Catalogue of roles your academy uses (head coach, assistant, manager, physio, kit). Each role maps to one or more authorization roles which determine permissions on the team they're assigned to."

**Files touched** (label-only changes; no logic changes):

- `src/Shared/CoreSurfaceRegistration.php` lines 320, 1051, 1261 — the three places "Functional Roles" appears as a tile / menu / link label. Lines 320 (frontend tile) and 1261 (admin sidebar link) become "Functional Role Assignments" because that's the day-to-day operational surface the tile lands you on. Line 1051 (the `tt-functional-roles` admin page registration) becomes "Functional Role Definitions".
- `src/Modules/Authorization/Admin/FunctionalRolesPage.php` line 59 (page H1), line 153 (back-link text). Both become "Functional Role Definitions".
- `src/Modules/Configuration/Admin/ConfigurationPage.php` line 296 (Configuration → Settings tile). Becomes "Functional Role Definitions" with a description that clarifies "Per-club catalogue of staff roles. Each role maps to authorization roles. Per-team assignments are managed elsewhere — see the Functional Role Assignments tile on the dashboard."
- `src/Shared/Admin/BackNavigator.php` lines 91, 141 — the breadcrumb label-key map. The internal key `'Functional Roles'` stays (it's a code identifier) but the rendered translation becomes "Functional Role Definitions".
- `src/Shared/Frontend/FrontendFunctionalRolesView.php` line 73 (header) — becomes the tab-aware variant; line 75 (the descriptive paragraph) gets the rewritten copy above.
- `src/Modules/Authorization/Admin/RolesPage.php` lines 69, 262 — link text and inline reference; both become "Functional Role Definitions" because they point at the catalogue editor.
- `src/Modules/People/Admin/TeamStaffPanel.php` lines 120, 153 — the empty-state and tooltip pointing the user at the catalogue page; "Check the Functional Roles admin page" becomes "Check the Functional Role Definitions page".
- `src/Modules/Documentation/HelpTopics.php` line 164 — summary text; "functional roles" expands to "functional role definitions and assignments".
- `src/Shared/Frontend/FrontendRolesView.php` line 75 — the user-facing description of `tt_staff` role; reference to "Functional Roles" becomes "Functional Role Assignments".

Matrix workbook: the `functional_roles_admin` entity label changes from "Functional Roles Admin" to "Functional Role Definitions" for consistency. The matrix entity slugs (`functional_roles_admin`, `functional_role_assignments`) stay as code identifiers — only the display labels change.

Translations: every NL string in `languages/talenttrack-nl_NL.po` matching the old labels is retranslated. Suggested NL: "Functionele roldefinities" and "Functionele roltoewijzingen". Defer the final NL phrasing to a native-speaker review per the existing translation workflow.

This is a label-and-docs change with one tiny code element (the dynamic header in `FrontendFunctionalRolesView`). No data migrations; no cap changes; no API breakage.

### 2. `feat-settings-subcaps`

The structural fix that makes per-tab matrix rows real.

#### New capabilities

Twelve new caps register in `RolesService::install_caps`, paired view + edit:

- `tt_view_lookups` / `tt_edit_lookups`
- `tt_view_branding` / `tt_edit_branding`
- `tt_view_feature_toggles` / `tt_edit_feature_toggles`
- `tt_view_audit_log` (no edit — audit is read-only by design)
- `tt_view_translations` / `tt_edit_translations`
- `tt_view_custom_fields` / `tt_edit_custom_fields`
- `tt_view_evaluation_categories` / `tt_edit_evaluation_categories`
- `tt_view_category_weights` / `tt_edit_category_weights`
- `tt_view_rating_scale` / `tt_edit_rating_scale`
- `tt_view_migrations` / `tt_edit_migrations`
- `tt_view_seasons` / `tt_edit_seasons`
- `tt_view_setup_wizard` / `tt_edit_setup_wizard`

The `tt_view_settings` and `tt_edit_settings` caps stay registered. They become **roll-ups** via `CapabilityAliases`: a user "has" `tt_edit_settings` iff they have all twelve `tt_edit_*` sub-caps. This preserves backwards compatibility for any code that still asks the question "can this user edit settings".

#### Refactor of call sites

Every page handler currently gating on `tt_edit_settings` or `tt_view_settings` switches to the specific sub-cap. The full file list, drawn from `grep -rln 'tt_edit_settings\|tt_view_settings' src/`:

- `src/Modules/Configuration/Admin/ConfigurationPage.php` — 16 tab handlers; each switches to its specific sub-cap. The lookup tab (`tab_lookup`) gates per-type but they all share `tt_edit_lookups` because they share the `tt_lookups` table. Buttons in the action column wrap in `current_user_can('tt_edit_lookups')` — fixing the existing UX bug where edit affordances render unconditionally.
- `src/Modules/Configuration/Admin/CustomFieldsPage.php` — `tt_view_custom_fields` / `tt_edit_custom_fields`.
- `src/Modules/Configuration/Admin/MigrationsPage.php` — `tt_view_migrations` / `tt_edit_migrations`.
- `src/Modules/Evaluations/Admin/CategoryWeightsPage.php` — `tt_view_category_weights` / `tt_edit_category_weights`.
- `src/Modules/Evaluations/Admin/EvalCategoriesPage.php` — `tt_view_evaluation_categories` / `tt_edit_evaluation_categories`.
- `src/Modules/Pdp/Admin/SeasonsPage.php` — `tt_view_seasons` / `tt_edit_seasons`. Migrating this off `tt_edit_settings` is overdue; seasons are a PDP concern.
- `src/Modules/Onboarding/Admin/OnboardingPage.php` and `OnboardingHandlers.php` — `tt_view_setup_wizard` / `tt_edit_setup_wizard`.
- `src/Modules/Translations/Admin/TranslationsConfigTab.php`, `CapThresholdNotice.php` — `tt_edit_translations`.
- REST controllers under `src/Infrastructure/REST/` — `LookupsRestController`, `ConfigRestController`, `EvalCategoriesRestController`, `CustomFieldsRestController` — each switches to its respective sub-cap.

**Authorization admin pages stay on `tt_view_settings` for now.** `RolesPage`, `FunctionalRolesPage`, `DebugPage`, `MatrixPage`, `PreviewPage`, `ModulesPage` do auth-management work that doesn't fit any of the new sub-caps. They retain `tt_view_settings` (read) but adopt a new `tt_manage_authorization` cap (`create_delete`) for write handlers. This also fixes the existing security smell where `RolesPage::handleGrant` and `handleRevoke` currently check `tt_view_settings` for write — see the *R-vs-C/D implementation analysis* in the matrix workbook. The new cap is what those handlers should have been checking all along.

#### LegacyCapMapper updates

Each new cap maps to its matrix entity:

```php
'tt_view_lookups'                => [ 'lookups',                'read' ],
'tt_edit_lookups'                => [ 'lookups',                'change' ],
'tt_view_branding'               => [ 'branding',               'read' ],
'tt_edit_branding'               => [ 'branding',               'change' ],
// ... (and so on for all twelve pairs)
'tt_manage_authorization'        => [ 'authorization_matrix',   'create_delete' ],
```

The existing `tt_*_settings` rows in the mapper become roll-ups via `CapabilityAliases` and can stay in the mapper as a fall-back for any unmapped caller. Document that new code should never bridge through the umbrella cap.

#### Migration `0050_settings_subcaps_seed`

Backfills the new caps onto existing role holders so no user loses access on upgrade:

- Anyone holding `tt_view_settings` today gets all twelve `tt_view_*` sub-caps.
- Anyone holding `tt_edit_settings` today gets all eleven `tt_edit_*` sub-caps.
- Anyone holding `tt_manage_settings` today gets all `tt_edit_*` sub-caps plus `tt_manage_authorization`.

The migration is idempotent — re-running it is a no-op for already-migrated installs.

#### ConfigurationPage tile filtering

The tile landing page (`?page=tt-config`) filters tiles by their corresponding sub-cap rather than the umbrella. A user with `tt_edit_lookups` but not `tt_edit_branding` sees only the lookup tile. This automatically gives clubs the per-tab visibility that was the original goal of the per-tab matrix rows.

#### Tests

Three test categories.

- **Capability registration**: assert all twelve pairs (twenty-three caps total counting the existing ones) are registered after `RolesService::install_caps` runs on a fresh install.
- **Roll-up correctness**: a user with all twelve `tt_edit_*` sub-caps reports `current_user_can('tt_edit_settings') === true`; a user missing one sub-cap reports false.
- **End-to-end**: a user with only `tt_edit_lookups` can submit the lookups tab form successfully and gets `wp_die('Unauthorized')` when posting to the branding handler.

### 3. `feat-persona-hod-narrowing`

The editorial change: Head of Development becomes development-focused. Mechanically simple, behaviourally meaningful.

#### Seed changes

Apply the diffs from the reviewed matrix verbatim. The full list, transcribed from the user's yellow-cell edits:

| Entity | Old | New | Rationale |
|--------|-----|-----|-----------|
| `bulk_import` | C global | (removed) | Bulk operations are admin-only by design. |
| `reports` | RCD global | R global | HoD reads everything; Academy Admin owns report creation/deletion. |
| `workflow_templates` | RCD global | R global | Workflow config is administrative. |
| `documentation` | RC global | R global | Editing docs is an admin task. |
| `team_chemistry` | RCD global | R global | HoD reviews; coaches author. |
| `dev_ideas` | RCD global | C global | HoD submits + refines (write); only Academy Admin promotes/deletes. |
| `spond_integration` | RCD global | R global | External-API credentials stay admin-only. |
| `persona_templates` | RC global | R global | UI templates are admin/branding. |
| `translations_config` | RC global | R global | External cost surface. |
| `settings` | RC global | R global | Per the granularity decision, all twelve `tt_edit_*` sub-caps drop too. |
| `lookups` | RC global | R global | Implied by settings. |
| `custom_field_values` | RC global | R global | Implied by settings. |
| `branding` | RC global | R global | Implied by settings. |
| `feature_toggles` | RC global | R global | Implied by settings. |
| `rating_scale` | RC global | R global | Implied by settings. |

**What HoD keeps**: full RCD on player development surfaces — `players`, `team`, `people`, `evaluations`, `activities`, `goals`, `attendance`, `methodology`, `pdp_file`, `pdp_verdict`, `seasons` (RC), `player_timeline`, `player_injuries` (RC, sensitive), `safeguarding_notes` (RC, sensitive), `staff_development`, `staff_certifications` (R), `staff_mentorships`, `trial_cases`/`trial_inputs`/`trial_decisions`. Plus `usage_stats` R, `tasks_dashboard` R, `audit_log` R for governance visibility.

#### Default WP role mapping

`RolesService::install_caps` is updated so the `tt_head_dev` WP role no longer receives `tt_edit_settings` and the new `tt_edit_*` sub-caps. It does receive the new `tt_view_*` sub-caps so HoD can still see Configuration. The migration handles existing installs.

#### Migration `0051_hod_persona_narrowing`

Two-phase, idempotent.

1. **Capture**: take a snapshot of every user with the `tt_head_dev` role and which `tt_edit_settings`-family caps they currently hold. Write to `tt_authorization_changelog` with `change_type='migration'` so the operator has an audit trail.
2. **Apply**: for each captured user, remove the now-deprecated edit caps from their effective set. The user's role assignment stays — only the cap bundle of `tt_head_dev` shifts.

The migration respects an opt-out flag: `define('TT_HOD_KEEP_LEGACY_CAPS', true)` in `wp-config.php` skips the apply phase. This is for installs that want the old behaviour for organisational reasons. Documented prominently.

#### Migration Preview integration

The existing Migration Preview page already shows per-user gained/revoked caps when the matrix is applied. This child wires the seed change through that surface. The expected output for an HoD user on a stock install is a sizeable Revoked column (the 15 caps listed above) and an empty Gained column. The operator must see this and explicitly Apply — the migration does not run silently.

#### Documentation update

`docs/access-control.md` is the canonical statement of "what each role does". The Head of Development row in the pre-built roles table is rewritten to reflect the new boundary. New language: "All player-development areas; reads everything else." The "edit all areas (incl. Evaluations, Settings)" claim is removed.

#### Tests

End-to-end: a fresh install seeds an HoD user; assertions check they CAN edit a player, evaluation, goal, methodology entry, PDP file; CANNOT edit a lookup, branding, feature toggle, custom field value, or workflow template; CAN read all of the above.

### 4. `feat-player-status-visibility-toggle`

A per-club switch controlling whether the player-status colour dot is shown to players and parents. Implements the toggle pattern as a runtime override on top of the matrix rather than a mutation of caps or matrix data — chosen deliberately so the matrix continues to describe permission **intent** and the toggle expresses club **policy**. The two concepts stay separable.

#### The toggle

One new entry in `FeatureToggleService::definitions()`:

```php
'player_status_visible_to_player_parent' => [
    'label'       => __( 'Show player status to players and parents', 'talenttrack' ),
    'description' => __( 'When enabled, players see their own status colour and parents see their child\'s. When disabled, the status dot is staff-only (coaches, scouts, Head of Development, Academy Admin). The detailed breakdown remains staff-only regardless of this setting. Note: a status change can still be inferred indirectly if other surfaces (e.g. evaluations) reveal it; this toggle hides the explicit dot, not the underlying judgement.', 'talenttrack' ),
    'default'     => false,
],
```

Default `false` — fresh installs hide the dot from players and parents; HoD opts in. Existing installs preserve today's visible behaviour via the upgrade migration (see below).

**One toggle covers both audiences.** Single switch for player + parent together. The product reasoning: clubs that want to hide the dot from one audience almost always want to hide it from both, and a single switch is easier to communicate ("show or hide for the family"). If a club later asks for separate switches, splitting a boolean toggle into two booleans is a non-breaking schema change.

#### Surface points that honour the toggle

Three places, all gated through one tiny helper:

```php
// In src/Modules/Players/Frontend/PlayerStatusVisibility.php (new file)
final class PlayerStatusVisibility {
    public static function dotVisibleTo( int $user_id ): bool {
        $personas = PersonaResolver::personasFor( $user_id );
        $is_family = in_array( 'player', $personas, true ) || in_array( 'parent', $personas, true );
        if ( ! $is_family ) return true;  // staff always see the dot
        $toggles = tt_container()->get( FeatureToggleService::class );
        return $toggles->isEnabled( 'player_status_visible_to_player_parent' );
    }
}
```

The three call sites:

- `PlayerStatusRestController::playerStatus()` — wraps the existing 200 response with `if ( ! PlayerStatusVisibility::dotVisibleTo( get_current_user_id() ) ) return RestResponse::error( 'forbidden', __( 'Status not available.', 'talenttrack' ), 403 );`. Note: returns a generic 403 with a neutral message rather than "your club has hidden this" — leaks less product policy, and the message stays useful if the toggle is ever flipped per-team or per-age-group in v2.
- `PlayerStatusRestController::teamStatuses()` — when the requesting user is family-personas and the toggle is off, return an empty `statuses` array (not 403 — the team detail view is allowed to render, the dots are just absent).
- `PlayerStatusRenderer::dot()` / `::pill()` / `::panel()` — guard at the top: if the resolved current user is family-personas and the toggle is off, return empty string. This catches the `FrontendTeamDetailView` / `TeamPlayersPanel` render call sites without each one needing its own check.

The single guard at the renderer covers any future call site automatically — adding the dot to a new view doesn't require remembering to add a toggle check.

#### Why not split `player_status` into two matrix entities

Considered; rejected. A `player_status_self` (toggle-able) vs `player_status_team` (always-on) split would be cleaner in some sense, but it introduces a fork in the data model where in code there's only one concept. Worse, it makes "what does this user see" depend on whether you read the matrix row that was selected vs the row that would have been selected — the matrix loses its property of "this is the answer". Keeping the matrix unconditional and folding the toggle into the runtime layer means the matrix is always the answer, with one well-documented runtime modifier on top.

#### Toggle UI

Already free — sits in Configuration → Feature Toggles alongside the other toggles, no UI work needed. The label and description above are written for that surface.

#### Cap layer

Untouched. The four player-status caps (`tt_rate_player_behaviour`, `tt_set_player_potential`, `tt_view_player_status`, `tt_view_player_status_breakdown`) keep their existing role assignments. The toggle is a layer above caps, not a replacement for them.

#### Persona-dashboard widget impact

When the persona-dashboard `MyTeamPodiumPosition` widget or any future status-aware widget renders for a player or parent, it must call `PlayerStatusVisibility::dotVisibleTo()` before showing the dot. This is a discovery question for that module; the spec adds a `// TODO(player-status-visibility)` marker in the widget code so it's caught when the module is next touched.

#### Persona Templates

Persona dashboard templates that include a status-dot block need to be aware: the rendered template gets the empty string back from `PlayerStatusRenderer::dot()` rather than failing, but the surrounding caption ("Your status:") would orphan. The renderer change includes a wrapping `<span class="tt-player-status-block">` with display logic that hides the entire block when the inner dot is empty. CSS-only fix; no template-engine change needed.

#### Migration `0052_player_status_visibility_default`

On upgrade, set the toggle to `true` for existing installs (preserve today's behaviour). On fresh install, the default of `false` from `definitions()` applies and the toggle row is never written until someone changes it. Detection: the migration runs once per install; checks whether the install has any existing players in `tt_players` (a signal that the install is upgrade rather than fresh) and writes `true` if so. Idempotent.

#### Tests

- **Unit**: `PlayerStatusVisibility::dotVisibleTo()` returns true for staff regardless of toggle; returns toggle value for player/parent.
- **Integration**: a parent calls `GET /players/{child_id}/status` with toggle off → 403; with toggle on → 200 with status payload.
- **Integration**: a parent calls `GET /teams/{id}/player-statuses` with toggle off → 200 with empty statuses array (the rest of the team payload still renders).
- **E2E**: HoD flips the toggle in Configuration → Feature Toggles; on next page-load, the parent dashboard no longer shows their child's status dot, but the team detail view still shows dots when viewed by a coach.
- **Migration**: an upgrade install with existing players ends up with toggle = true; a fresh install ends up with toggle unset (default false).

#### Documentation

`docs/access-control.md` and Dutch mirror gain a paragraph: "Player status visibility — by default, only staff see player status dots. Head of Development can enable family visibility per club via Configuration → Feature Toggles." `docs/authorization-matrix.md` gets a callout under the `player_status` row: "Subject to the `player_status_visible_to_player_parent` toggle for player and parent personas." The matrix workbook itself documents this on the relevant rows in the README sheet.

### 5. `feat-user-impersonation`

Native user impersonation for Academy Admin. Solves two real problems: (a) testing — "what does this parent's dashboard look like" — and (b) support — "the user reports a bug; let me see what they see". Today the only options are asking the user to share their screen or recreating the user's exact role and team assignments on a sock-puppet account, both of which are slow and error-prone.

#### Why integrated rather than a recommended plugin

The User Switching plugin solves the WordPress half cleanly but doesn't know about TalentTrack's authorization layers — `tt_user_role_scopes`, `tt_authorization_changelog`, `CurrentClub`, the persona resolver, the per-club feature toggles, the medical-data sensitive-entity matrix grants. A native solution writes its own impersonation events to a TalentTrack-owned audit table that an Academy Admin can review under Configuration → Audit Log, and a tenant-scope check prevents cross-club impersonation in multi-tenant installs. The integration cost is upfront; the maintenance cost is low because the underlying mechanism (`wp_set_auth_cookie`) is one of the most stable WP APIs.

#### Capability and persona model

A new capability `tt_impersonate_users` is granted to:

- The WP `administrator` role (always — superadmins should retain emergency access).
- The `tt_club_admin` role (Academy Admin in matrix terms).

No other persona ever holds this cap. Specifically: Head of Development, even after this epic's narrowing, does NOT get impersonation rights. The reasoning is that impersonation reveals everything about a user — including content explicitly hidden from HoD by the matrix, like configuration data they no longer have edit rights on. If a future club wants to grant impersonation to a non-admin role, they can do it via the matrix (the cap is matrix-bridged), but the default is admin-only.

#### The impersonation model

Two-stage with explicit return:

1. **Start.** Admin clicks "Switch to this user" on the People admin page (or any surface that lists users). A confirmation modal shows: target user's display name, their email, their primary persona, the teams they're assigned to, and the message "Every action you take will be attributed to them. The audit log will record the entire session." Clicking Confirm POSTs to `POST /wp-json/talenttrack/v1/impersonation/start`.
2. **Active.** The admin's session is now the target user's session. A bright-yellow non-dismissible banner sits at the top of every page: "Impersonating Anna de Vries (parent of Lucas, U12). Switch back". The banner is rendered via a `tt_impersonation_banner` action hooked into both `wp_body_open` (frontend) and `admin_notices` (wp-admin).
3. **End.** Two ways. Admin clicks "Switch back" in the banner → POST to `/impersonation/end` → session restored to the original admin. Or the admin's browser closes / cookies expire → the next request finds an orphan impersonation token and falls back to a logged-out state, which prompts re-login.

#### Database

One new table.

```sql
CREATE TABLE {prefix}tt_impersonation_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED NOT NULL,        -- the admin
    target_user_id BIGINT UNSIGNED NOT NULL,       -- who they impersonated
    club_id BIGINT UNSIGNED NOT NULL,              -- enforces tenant boundary
    started_at DATETIME NOT NULL,
    ended_at DATETIME DEFAULT NULL,                -- NULL while active
    end_reason VARCHAR(20) DEFAULT NULL,           -- 'manual' | 'expired' | 'forced' | 'session_ended'
    actor_ip VARCHAR(45),
    actor_user_agent VARCHAR(255),
    reason VARCHAR(255),                            -- optional admin-supplied note ("debugging ticket #1247")
    PRIMARY KEY (id),
    KEY idx_actor_time (actor_user_id, started_at),
    KEY idx_target (target_user_id),
    KEY idx_club (club_id),
    KEY idx_active (ended_at)
);
```

The table is intentionally separate from `tt_authorization_changelog` because they record different domains (matrix-config edits vs authentication events) and conflating them would muddy queries. Migration `0053_impersonation_log` creates it with the multi-tenant `club_id` indexed.

#### Mechanics — the auth cookie swap

`ImpersonationService::start( int $actor_id, int $target_id ): WP_Error|null`:

1. Validate actor has `tt_impersonate_users`. (Re-checked server-side even though UI gates it.)
2. Validate target exists and is a member of the same club (multi-tenant safety: `tt_user_role_scopes` row exists with `actor_user.club_id === target_user.club_id`). Cross-club impersonation is rejected even for the WP super-administrator unless they hold the `tt_super_admin` cap (out of scope here; this spec assumes single-club-per-install for the current product surface).
3. Validate target does NOT hold `tt_impersonate_users` themselves. Admins may not impersonate other admins (see "Defence in depth" below).
4. Persist the actor's user ID to a secure cookie `tt_impersonator_id`, signed with `wp_hash()`, expires when the session expires.
5. Insert row into `tt_impersonation_log` with `started_at = NOW()`.
6. Call `wp_set_auth_cookie( $target_id, false, is_ssl() )` — this is the WordPress API for swapping session identity. `false` means non-persistent; the impersonation does not survive a browser close.
7. Return `null` (success).

`ImpersonationService::end()`:

1. Read the `tt_impersonator_id` cookie. If missing or invalid → no-op (browser is in normal logged-in state).
2. Read the active impersonation row from `tt_impersonation_log` (where `actor_user_id = cookie_value` and `ended_at IS NULL`).
3. Update the row: `ended_at = NOW()`, `end_reason = 'manual'`.
4. Call `wp_set_auth_cookie( $actor_id, false, is_ssl() )` to restore.
5. Delete the `tt_impersonator_id` cookie.

A daily cron `tt_impersonation_cleanup_cron` finds orphan rows (`ended_at IS NULL` and `started_at < NOW() - INTERVAL 24 HOUR`) and closes them with `end_reason = 'expired'`. Prevents the audit log from accumulating dangling sessions.

#### Banner & switch-back UI

A new view component `ImpersonationBanner::render()` is hooked into:

- `wp_body_open` (frontend) — renders before the dashboard shortcode.
- `admin_notices` (wp-admin) — renders above any page content.
- `body_class` filter adds `tt-impersonating` so themes can style around it.

The banner renders only when the `tt_impersonator_id` cookie is present AND a matching active row exists in `tt_impersonation_log`. (Both conditions guard against stale cookies after a manual database reset.)

CSS: bright yellow background (`#FCD34D`), full-width, fixed top, ~40px tall, non-dismissible. Always visible on every page including modal / wizard surfaces. The "Switch back" button posts to `/impersonation/end` with a CSRF nonce.

#### Surfaces that need to know about impersonation

Most of the codebase doesn't — `MatrixGate`, `PersonaResolver`, every REST controller, every render call site reads `get_current_user_id()` and gets the target's ID, which is exactly what's wanted. The exceptions are:

- **Audit log writers.** Anywhere code writes to `tt_authorization_changelog` (matrix edits, role grants, etc.) currently uses `get_current_user_id()` for `actor_user_id`. During impersonation we want the actual admin recorded, not the impersonated user. A helper `ImpersonationContext::effectiveActorId(): int` returns the impersonator if active, else `get_current_user_id()`. All audit writers switch to this helper.
- **Dangerous-action gates.** Some operations should be blocked entirely while impersonating, even if the impersonated user has the cap. Specifically: matrix Apply, matrix edits, role grants/revokes, deleting any record, exporting data. The reasoning is that an admin debugging a parent's view shouldn't accidentally trigger destructive operations from inside that session. A helper `ImpersonationContext::isImpersonating(): bool` and a guard `ImpersonationContext::denyIfImpersonating( string $action ): WP_Error|null` are added; every destructive admin handler calls the guard before acting. List of guarded handlers: `MatrixPage::handleSave`, `MatrixPage::handleApply`, `RolesPage::handleGrant`, `RolesPage::handleRevoke`, `BackupSettingsPage::handleRestore`, `DemoData::handleReset`, all `tt_delete_*` admin-post handlers across the codebase, and `BulkImport::handleSubmit`. These get a yellow notice "Action disabled while impersonating: switch back to perform this operation."
- **Push notifications.** A push notification subscribed to the target's device must not fire because of an admin's actions during impersonation. The push module reads `ImpersonationContext::isImpersonating()` and suppresses outbound dispatches.
- **Email notifications.** Same logic — emails that would have been sent to the target user (or about their actions) are suppressed during impersonation.
- **Workflow task completion.** Marking a task complete during impersonation writes the audit log with the actor admin, not the impersonated user, and the workflow engine logs a special note: `[impersonated by admin@example.com]`. Same for any threaded message, evaluation save, or goal update.
- **Persona-dashboard active-persona picker.** The picker's `POST /me/active-persona` endpoint already validates against `PersonaResolver::personasFor()` — no change needed. During impersonation, the admin is genuinely the target user as far as `PersonaResolver` is concerned, so they can switch among the target's personas naturally.

#### Defence in depth

Several rules that prevent privilege escalation:

- **Admin-on-admin is forbidden.** An admin cannot impersonate another admin (or any user holding `tt_impersonate_users`). Prevents lateral admin escalation in audit logs.
- **Self-impersonation is forbidden.** An admin cannot impersonate themselves. Trivial check; no surface but worth the explicit guard.
- **Stacking is forbidden.** If `tt_impersonator_id` cookie is already set, `start()` returns an error. Prevents nested impersonations whose audit trail would be ambiguous.
- **Tenant boundary.** Cross-club impersonation requires explicit super-admin cap (not granted by default). A WP administrator who is not also `tt_super_admin` cannot impersonate users in clubs they don't administer.
- **2FA replay guard.** If TalentTrack ever enables 2FA (not today; tracked under #0049), `wp_set_auth_cookie` skips the 2FA challenge. The mitigation is a config constant `define('TT_IMPERSONATION_REQUIRES_2FA_REVERIFICATION', true)` that, when set, forces the admin to re-authenticate before starting an impersonation. Off by default; enables the safer path for clubs that need it.

#### Audit log surface

A new wp-admin page under Configuration → Audit Log → Impersonation Sessions (also accessible via the matrix entity `impersonation_log`, which this child adds as a new entity row). Lists every impersonation session — actor, target, club, started, ended, end reason, actor IP, optional reason note. Filterable by date range, actor, target. Export to CSV.

For sensitive sessions (impersonation of a user who holds safeguarding access), the row is highlighted and an additional flag `flagged_for_review` is set. The Read-Only Observer can review the impersonation log but cannot delete rows; only `tt_super_admin` (not granted on stock installs) can delete impersonation log rows, and even then deletion is soft (a `deleted_at` column added in a follow-up if needed — out of scope here).

#### Matrix integration

A new entity `impersonation_log` is added to the matrix in this child:

| Persona | Activity | Scope |
|---------|----------|-------|
| Academy Admin | RCD | global |
| Head of Development | R | global |

(HoD reads the audit trail for governance but cannot impersonate; cannot delete log rows.)

The impersonation capability `tt_impersonate_users` itself is bridged via `LegacyCapMapper` to a new matrix entity `impersonation_action`:

| Persona | Activity | Scope |
|---------|----------|-------|
| Academy Admin | C | global |

Only Academy Admin holds it; matrix scope is global because the cross-club guard is enforced separately in the service layer.

#### REST endpoints

```
POST   /wp-json/talenttrack/v1/impersonation/start
       body: { target_user_id: int, reason?: string }
       returns: { ok: true, target: { id, display_name, persona_summary } }

POST   /wp-json/talenttrack/v1/impersonation/end
       returns: { ok: true, restored_actor: { id, display_name } }

GET    /wp-json/talenttrack/v1/impersonation/log
       query: { actor_user_id?, target_user_id?, from?, to?, limit?, offset? }
       returns: paginated list

GET    /wp-json/talenttrack/v1/impersonation/active
       returns: { active: bool, actor_id?, target_id?, started_at? }
```

Permission callbacks: `start` requires `tt_impersonate_users` AND no active impersonation; `end` requires presence of valid `tt_impersonator_id` cookie; `log` requires the matrix grant on `impersonation_log`; `active` requires only `is_user_logged_in()`.

#### UI integration points

- People admin page (`?tt_view=people`) — adds a "Switch to this user" button next to each row, visible only when current user holds `tt_impersonate_users`. Disabled with tooltip "Cannot impersonate another admin" for users who themselves hold the cap.
- Users wp-admin page (`/wp-admin/users.php`) — adds a "Switch to" row action via the standard WP `user_row_actions` filter. Same permission gating as above.
- Banner — global, on every frontend and admin page, only when impersonation is active.
- Audit log page — under Configuration → Audit Log → Impersonation Sessions.

#### Tests

- **Unit**: `ImpersonationContext::effectiveActorId()` returns impersonator ID when active, current user otherwise. `denyIfImpersonating()` returns `WP_Error` when active, null otherwise.
- **Unit**: `ImpersonationService::start()` rejects: target doesn't exist, target in different club, target holds `tt_impersonate_users`, actor doesn't hold `tt_impersonate_users`, actor already impersonating, target is actor.
- **Integration**: Admin starts impersonation of a parent → `wp_get_current_user()` returns the parent → `MatrixGate::canAnyScope()` for `safeguarding_notes` returns false (parent has no grant). End impersonation → `wp_get_current_user()` returns admin → same call returns true.
- **Integration**: During impersonation, calling the matrix Apply endpoint returns 403 with the standard "Action disabled while impersonating" error. Audit log shows no matrix-edit row.
- **Integration**: During impersonation, marking a workflow task complete writes a `tt_workflow_task_log` row with `actor_user_id` = admin's ID (not the parent's), and a note containing `[impersonated]`.
- **E2E**: Admin loads the People page, clicks Switch to on a parent, sees the parent's dashboard with the yellow banner, navigates to a player profile, sees the parent-scoped read of the player, clicks Switch back, returns to the admin page with no banner. `tt_impersonation_log` has one row with both timestamps.
- **E2E**: Admin starts impersonating a parent, closes the browser. The next day's `tt_impersonation_cleanup_cron` finds the orphan row and closes it with `end_reason = 'expired'`.
- **Security**: Cross-club impersonation attempt is rejected with 403 `cross_club_forbidden`. Admin-on-admin attempt is rejected with 403 `admin_target_forbidden`.

#### Documentation

- `docs/access-control.md` (and Dutch mirror) — new section "Impersonation". How it works, who can do it, what's logged, what's blocked during a session, how to review the log.
- `docs/authorization-matrix.md` — the new entities (`impersonation_log`, `impersonation_action`) listed under Authorization module.
- A new doc `docs/impersonation.md` (and Dutch mirror) — operator guide. Walks through the start / active / end flow with screenshots. Lists every action that's blocked during a session. Notes the 24-hour orphan cleanup. Notes the recommendation that admins always supply a reason note (e.g. ticket number) so the audit log is searchable.
- Banner translation strings in `languages/talenttrack-nl_NL.po`.

## Wizard plan

**Exemption** — this epic touches the seed file, the cap layer, and migration logic. There is no record-creation flow added or removed. Existing wizards (new-player, new-team, new-evaluation, new-goal, new-activity) check capabilities at entry; the spec leaves their cap requirements untouched (`tt_edit_<entity>` continues to gate each wizard's `requiredCap()`). Where a wizard's required cap is among those changing for HoD (`tt_edit_settings` no longer held by HoD), the entry-point gating is what blocks HoD from launching the wizard — no wizard code change needed.

## Out of scope

- **The `age_category` scope kind.** The user's original brief mentioned an `age_category` context that doesn't exist in the codebase (only `global` / `team` / `player` / `self`). Adding one is a real change — new `MatrixGate::SCOPE_AGE_GROUP` constant plus a `tt_user_role_scopes.scope_type='age_group'` row type plus runtime resolution against `tt_teams.age_group`. Out of scope for this epic; tracked separately if a customer asks.
- **Splitting create from delete.** The matrix has `change` and `create_delete` as separate activities, but the cap layer collapses them for most entities — `tt_edit_<entity>` covers both. Splitting would require new `tt_delete_<entity>` caps for ~7 entities. Out of scope; documented as an option in the *R-vs-C/D analysis* sheet of the matrix workbook for future revisit.
- **The `tt_view_player_safeguarding` parent visibility question.** The reviewed matrix sets parents to no access on safeguarding notes. `CLAUDE.md` notes that safeguarding may concern the parent themselves, so this is correct — but a future feature might want a per-record "this safeguarding note is shareable with the parent" flag. Out of scope; flagged in the spec for the Journey module owner.
- **A deeper persona-management UI.** The matrix epic v2 will add per-club persona definition (today personas are hard-coded in `PersonaResolver`). This epic does not touch persona definition; it operates within the existing eight personas.

## Acceptance criteria

### Coverage child (`feat-matrix-coverage`)

- [ ] Every entity listed in the reviewed matrix's *Matrix* sheet exists as a row in `config/authorization_seed.php` with the documented persona grants and scope. The matrix has ~107 entities post-round-2; this child must land all of them.
- [ ] `LegacyCapMapper::MAPPING` has an entry for every new cap declared in this child, and every matrix entity has either a cap mapping or is documented as matrix-only.
- [ ] A unit test in `tests/Authorization/SeedCoverageTest.php` enumerates the seed and asserts that for each entity, either (a) at least one cap maps to it via `LegacyCapMapper`, or (b) the entity is documented as matrix-only. Catches future drift.
- [ ] A second unit test, `tests/Authorization/SurfaceCoverageTest.php`, walks every `view_slug` registered in `src/Shared/CoreSurfaceRegistration.php` and asserts that each one resolves to a matrix entity. Audit-completeness backstop.
- [ ] Sensitive entities (`player_injuries`, `safeguarding_notes`, `player_potential`, `pdp_evidence_packet`, `player_status_breakdown`) have explicit assertions: the seed denies access to all personas except those listed in the matrix.
- [ ] The Migration Preview page lists every newly-seeded entity correctly when the matrix is applied.
- [ ] "Functional Roles" labels are split into "Functional Role Definitions" and "Functional Role Assignments" at every user-visible label site enumerated in this spec. URL slugs unchanged.

### Sub-caps child (`feat-settings-subcaps`)

- [ ] All twelve cap pairs are registered after `RolesService::install_caps` runs.
- [ ] The `CapabilityAliases` rollup correctly answers `tt_edit_settings` based on the twelve sub-caps.
- [ ] Every file currently calling `current_user_can('tt_edit_settings')` or `current_user_can('tt_view_settings')` has been refactored to a specific sub-cap, or is a deliberately-kept umbrella check (Authorization admin pages). Remaining umbrella checks are documented in a comment with the rationale.
- [ ] `ConfigurationPage`'s tile landing filters tiles by the new sub-caps. A user holding only `tt_edit_lookups` sees the Lookups tile and not the Branding tile.
- [ ] The Configuration lookup-tab UX bug is fixed: Edit / Delete / Add New buttons hide for users without `tt_edit_lookups`.
- [ ] Migration `0050_settings_subcaps_seed` runs idempotently.
- [ ] A user holding `tt_edit_lookups` only can save the lookups tab; the same user posting to the branding handler gets `wp_die('Unauthorized')`.

### HoD-narrowing child (`feat-persona-hod-narrowing`)

- [ ] `config/authorization_seed.php` `head_of_development` block matches the reviewed matrix's HoD column verbatim.
- [ ] `RolesService::install_caps` for `tt_head_dev` no longer assigns the deprecated edit caps; assigns the new `tt_view_*` sub-caps for visibility.
- [ ] Migration `0051_hod_persona_narrowing` runs on upgrade. With `TT_HOD_KEEP_LEGACY_CAPS` unset, an HoD user loses the listed edit caps; with the constant set, no change. Both cases write to `tt_authorization_changelog`.
- [ ] `docs/access-control.md` and `docs/nl_NL/access-control.md` reflect the new HoD scope.
- [ ] End-to-end test: a fresh-install HoD user can edit a player + evaluation + goal + methodology entry + PDP; cannot edit a lookup or branding or workflow template.

### Player-status visibility toggle child (`feat-player-status-visibility-toggle`)

- [ ] The toggle `player_status_visible_to_player_parent` exists in `FeatureToggleService::definitions()` with default `false`.
- [ ] The toggle appears in Configuration → Feature Toggles and can be flipped by anyone with the `feature_toggles` matrix grant (Academy Admin only post-narrowing).
- [ ] `PlayerStatusVisibility::dotVisibleTo()` returns true for staff personas regardless of toggle state; returns the toggle's value for player and parent personas.
- [ ] `GET /wp-json/talenttrack/v1/players/{id}/status` returns 403 for a parent of the player when the toggle is off; returns 200 with the status payload (without breakdown) when the toggle is on; always returns 200-with-breakdown for a coach of the team regardless of toggle.
- [ ] `GET /wp-json/talenttrack/v1/teams/{id}/player-statuses` returns an empty `statuses` array for a parent when the toggle is off; populated array when on; always populated for a coach.
- [ ] `PlayerStatusRenderer::dot()` returns empty string when called in a request context whose user is family-persona and the toggle is off. Wrapping `<span class="tt-player-status-block">` collapses cleanly so no orphan caption renders.
- [ ] Migration `0052_player_status_visibility_default` writes `true` to the toggle when the install has existing players (`SELECT COUNT(*) FROM tt_players > 0`); leaves the toggle unset on a fresh install. Idempotent.
- [ ] The matrix's `player_status` and `player_status_breakdown` rows are unchanged.

### User-impersonation child (`feat-user-impersonation`)

- [ ] The capability `tt_impersonate_users` is registered, granted to `administrator` and `tt_club_admin`, denied to all other roles by default. `LegacyCapMapper` bridges it to the `impersonation_action` matrix entity.
- [ ] `tt_impersonation_log` table exists after migration `0053_impersonation_log` with the documented schema and indexes.
- [ ] `ImpersonationService::start()` succeeds when actor is admin and target is a non-admin in the same club; rejects target-doesn't-exist, target-in-different-club, target-is-admin, target-is-self, actor-already-impersonating with distinct error codes.
- [ ] `ImpersonationService::end()` closes the open log row with `end_reason='manual'` and restores the auth cookie. Calling it without an active session is a no-op.
- [ ] The banner renders on every frontend and wp-admin page only when impersonation is active. Cannot be dismissed.
- [ ] During an active impersonation, every admin handler in the guarded list returns the standard "action disabled while impersonating" error; no rows are written to the underlying tables.
- [ ] Workflow task completions, evaluation saves, goal updates, and thread messages performed during impersonation persist to their respective tables with `actor_user_id` = admin's ID, and the note column on the relevant audit row includes `[impersonated by {admin email}]`.
- [ ] Email and push notifications that would be triggered by the impersonated user's actions are suppressed during the session and resumed when the session ends.
- [ ] The cron `tt_impersonation_cleanup_cron` runs daily, finds rows where `ended_at IS NULL AND started_at < NOW() - INTERVAL 24 HOUR`, and closes them with `end_reason='expired'`.
- [ ] The audit log page at Configuration → Audit Log → Impersonation Sessions lists rows with the documented columns, supports the documented filters, and exports to CSV.
- [ ] A WP super-administrator without `tt_super_admin` cannot impersonate users in clubs other than their own.
- [ ] E2E: the documented user journey (admin impersonates parent → sees parent dashboard → safeguarding-notes 403s for the impersonated parent → switch back → admin can read safeguarding notes again) passes.

## Notes

### Documentation updates required for every child

Per `CLAUDE.md` § 5 and the Definition of Done checklist, any user-visible behaviour change requires updating both `docs/<slug>.md` and `docs/nl_NL/<slug>.md`:

- `docs/access-control.md` and `docs/nl_NL/access-control.md` — rewrite the pre-built roles table; rewrite the Head of Development paragraph; note the new `tt_*_<area>` capabilities and how they roll up. Add a "Player status visibility" paragraph. Update every reference to "Functional Roles" to use the disambiguated names.
- `docs/authorization-matrix.md` and `docs/nl_NL/authorization-matrix.md` — refresh the persona descriptions; add a section "Settings is granular as of v3.x"; note the matrix Excel artifact as the source of truth. Add an asterisk-footnote on the `player_status` row.
- `docs/modules.md` — for any module that gains a new capability — extend the existing "what disabling does" section.
- A new doc, `docs/authorization-matrix-extended.md`, accompanies the Excel matrix. Explains the meaning of cream-tinted "proposed" rows and amber-bordered "extended" cells.
- A new doc `docs/impersonation.md` (and Dutch mirror) — operator guide.
- `languages/talenttrack-nl_NL.po` — every new user-facing string.
- `SEQUENCE.md` — append a row for the epic with the five children numbered as sub-tasks.
- `CHANGES.md` — one entry per child describing the user-facing change.

### `CLAUDE.md` guideline updates

- **§4 "Auth shouldn't be permanently chained to WP cookies"** — add a paragraph: "Capability granularity follows the matrix. New configuration tabs declare a `tt_view_<area>` / `tt_edit_<area>` pair from day one. Calling `current_user_can('tt_edit_settings')` in new code is forbidden — use the area-specific cap. The umbrella cap survives only as a `CapabilityAliases` roll-up for legacy code."
- **§6 Definition of Done checklist** — add under "SaaS-readiness": "If this PR adds a Configuration tab or a Settings-adjacent admin page: declare the area-specific cap pair; gate the page on the area-specific cap, not on `tt_edit_settings`."
- **§6 Definition of Done checklist** — add under "Auditability": "If this PR adds a destructive admin handler (matrix Apply, role grant, restore, delete, bulk import), add it to the `ImpersonationContext::denyIfImpersonating()` guard list. Destructive actions must not run from inside an impersonation session."

### Reviewing the "R vs C/D" implementation gap

Separately tracked in the matrix workbook. Not in scope for this epic because it's a code-discipline question, not an entity gap. Worth a follow-up issue: codify the three rules (menu cap = view; every edit affordance hides on edit cap; every save handler re-checks edit cap) in `CLAUDE.md` and add a static-analysis check to flag pages that violate them.

### Seasons under lookups?

No — seasons are not a lookup. Lookups (`tt_lookups`) are short flat reference lists with name + sort_order. Seasons (`tt_seasons`) carry a date range, an `is_current` flag, and are foreign-keyed by `tt_pdp_files` and other PDP entities. They render under PDP in the matrix because PDP is what they configure. Documented in the Pdp module section of `docs/modules.md`.
