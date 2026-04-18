# TalentTrack v2.4.1 — Delivery Changes

## What this ZIP does

Completes the i18n pass that v2.4.0 started. v2.4.0 translated most of the UI
but left residual `ucwords()` status transforms and hardcoded labels in the
coach dashboard and several admin pages. This release closes those gaps.

With v2.4.1, **Sprint 0 is now genuinely complete**.

## How to install

1. Extract this ZIP somewhere.
2. Open the resulting `talenttrack-v2.4.1/` folder.
3. Copy its **contents** into your local `talenttrack/` repository folder.
   Allow overwrites.
4. GitHub Desktop shows the changed files (5 PHP files + talenttrack.php + readme.txt).
5. Commit: `v2.4.1 — i18n completion pass`.
6. Push to origin.
7. GitHub → Releases → new release tagged `v2.4.1`. Publish.
8. WordPress auto-updates.

## Files in this delivery

### Modified
- `talenttrack.php` — version bumped to 2.4.1.
- `readme.txt` — stable tag + changelog.
- `src/Shared/Frontend/CoachDashboardView.php` — goal status/priority dropdowns and attendance options routed through LabelTranslator; fixes the "In Progress" fallback text.
- `src/Modules/Players/Admin/PlayersPage.php` — player-status option labels now explicit translated strings; status on player-detail view routes through LabelTranslator; media picker strings translatable.
- `src/Modules/Goals/Admin/GoalsPage.php` — status and priority columns and dropdowns use LabelTranslator.
- `src/Modules/Sessions/Admin/SessionsPage.php` — attendance status dropdown labels use LabelTranslator.

### Unchanged
- Every other file in the plugin. v2.4.0 infrastructure (LabelTranslator, .pot, Dutch .po/.mo) is the foundation this release builds on.

## Files NOT modified (but audited)
These were audited during this pass and confirmed already correct:
- `src/Modules/Teams/Admin/TeamsPage.php`
- `src/Modules/Evaluations/Admin/EvaluationsPage.php`
- `src/Modules/Reports/Admin/ReportsPage.php`
- `src/Modules/Documentation/Admin/DocumentationPage.php`
- `src/Modules/Configuration/Admin/ConfigurationPage.php` (already i18n'd in v2.3.0)
- `src/Shared/Admin/Menu.php`
- `src/Shared/Frontend/DashboardShortcode.php`
- `src/Shared/Frontend/PlayerDashboardView.php`
- `src/Shared/Frontend/FrontendAjax.php`
- `src/Shared/Frontend/BrandStyles.php`
- `src/Modules/Auth/LoginForm.php`, `LoginHandler.php`, `AuthModule.php`

## Post-install verification

1. Switch WP site language to Nederlands (Settings → General).
2. Visit **TalentTrack → Spelers** — status filter / column labels in Dutch.
3. Edit a player — Status dropdown shows: Actief / Inactief / Proef / Vertrokken.
4. View a player — Status row reads "Actief" (not "Active").
5. Visit **TalentTrack → Doelen** — Status column shows Dutch labels: Open / Bezig / Afgerond / Gepauzeerd / Geannuleerd.
6. Edit a goal — Priority dropdown: Laag / Gemiddeld / Hoog. Status dropdown: Open / Bezig / Afgerond / etc.
7. Visit **TalentTrack → Trainingen** → edit a session — Attendance dropdown: Aanwezig / Afwezig / Te laat / Geblesseerd / Afgemeld.
8. Open the frontend dashboard as a coach — all dropdowns translated; delete-goal confirm ("Dit doel verwijderen?") shows in Dutch.
9. Switch back to English — all fallbacks still work.
