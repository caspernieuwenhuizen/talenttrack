# TalentTrack v3.108.1 — Pilot-feedback batch: 8 mechanical bug fixes from a May 2026 acceptance round

Eight surgical fixes from a pilot acceptance round. Bigger items (per-topic privacy model, eval-wizard subcategories, team-overview HoD widget, breadcrumb back-navigation, broad detail-page visual refresh, KPI/widget data investigation, upgrade-to-Pro CTA) are tracked in `ideas/0089-feat-pilot-batch-followups.md` and will ship in focused follow-up PRs.

## What landed

### (1) Trial-cases tab CTA gated on player status

`FrontendPlayerDetailView::renderTrialsTab` now receives the player object and inspects `$player->status`. The "Open trial case" CTA only renders for trial-status players; for active / contracted / released players the empty-state copy switches to *"This player is not currently on trial, so there is no trial history to show."* without a button.

### (2) Preferred foot translates in the player list

`PlayersRestController::fmt()` emits a new `preferred_foot_pill_html` field rendered via `LookupPill::render('foot_options', $key)`; `FrontendPlayersManageView`'s column switches to `'render' => 'html', 'value_key' => 'preferred_foot_pill_html'`. Dutch installs now see *Rechts / Links* instead of the raw `right / left` keys.

### (3) People + functional-role edit forms redirect to list after save

`data-redirect-after-save="list"` added to the `tt-ajax-form` on `FrontendPeopleManageView::renderForm()` and `FrontendFunctionalRolesView::renderAssignmentForm()`. After a save the user lands back on the list view, not the form.

### (4) Eval wizard rateable list = strictly present/late

The previous fallback to "show the team's full roster when no attendance was recorded" is removed from `RateActorsStep::ratablePlayersForActivity()`. The wizard refuses to advance until someone is marked present/late on the activity, matching the existing empty-state copy.

### (5) `head_coach` + `team_manager` role-types translate

`LabelTranslator::roleType()` was missing those two keys. The team-detail page's staff list rendered the raw key on Dutch installs. Two new switch cases added.

### (6) Player FIFA-card own-profile bypass

`FrontendTeammateView::render()` now checks `mate.wp_user_id === viewer.wp_user_id` before the team-scope gate. A player tapping their own rate card always lands on their teammate view — even if they're between teams or on the trial-group pseudo-team.

### (7) Editable academy start-date

The player edit form (`FrontendPlayersManageView::renderForm`) now exposes an "In academy since" date field that writes through to `tt_players.date_joined`. The column has shipped on the schema since v1.0.0 and `update_player`'s payload extractor already accepted it; only the UI was missing.

### (8) Generic dropdown-dependency mechanism

New `data-tt-depends-on="other_field_name"` + `data-tt-options-source="rest:path/with/{value}"` (REST mode) or `data-tt-options-map='{"a":[["1","X"],…]}'` (static mode) attribute pair handled in `assets/js/public.js`. Any `<select>` across the app can opt in: when its parent changes, options rebuild via REST or from a JSON map. Sweep target = goal-wizard `link_type → link_id`, attendance form `team → player`, future cascades.

## Out of scope (tracked in `ideas/0089-feat-pilot-batch-followups.md`)

F1-F7, R2 LookupPill sweep, A1 breadcrumb history, A3 eval subcategories, A4 team-overview HoD widget, A5 detail-page visual refresh, A7 upgrade-to-Pro CTA, K1-K5 KPI/widget investigation.

## Affected files

- `src/Shared/Frontend/FrontendPlayerDetailView.php` — trial-cases gate
- `src/Infrastructure/REST/PlayersRestController.php` — preferred_foot_pill_html
- `src/Shared/Frontend/FrontendPlayersManageView.php` — column wired through pill, "In academy since" field
- `src/Shared/Frontend/FrontendPeopleManageView.php` — redirect-after-save
- `src/Shared/Frontend/FrontendFunctionalRolesView.php` — redirect-after-save
- `src/Modules/Wizards/Evaluation/RateActorsStep.php` — strict rateable filter
- `src/Infrastructure/Query/LabelTranslator.php` — head_coach + team_manager
- `src/Shared/Frontend/FrontendTeammateView.php` — self-bypass
- `assets/js/public.js` — generic dropdown-dependency mechanism
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version + ship metadata
- `ideas/0089-feat-pilot-batch-followups.md` — deferred items tracker

Renumbered v3.104.3 → v3.108.1 mid-rebase as parallel-agent ships covered v3.104.3 / v3.105.0 / v3.106.0 / v3.107.0 / v3.108.0 (Analytics #0083 children 2-6, demo-Excel rename #0080 Wave D, etc.) before this PR could land.
