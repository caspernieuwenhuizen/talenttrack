# TalentTrack v2.4.0 — Delivery Changes

## What this ZIP does

Completes **Sprint 0 Phase 4: full internationalization**. Adds:

- Translation template (`.pot`) covering every translatable string
- Complete Dutch translation (`.po` + compiled `.mo`)
- JS-side strings now translated via `wp_localize_script`
- Status/priority labels routed through a translation map

With this release, **Sprint 0 is complete**. TalentTrack has modular architecture,
migrations, REST envelope, logging, audit, feature toggles, environment-aware
behavior, and full i18n.

## How to install

1. Extract this ZIP somewhere.
2. Open the resulting `talenttrack-v2.4.0/` folder.
3. Copy its **contents** (not the folder itself — the files and folders inside)
   into your local `talenttrack/` repository folder. Allow overwrites.
4. GitHub Desktop shows the changed files.
5. Commit: `v2.4.0 — Sprint 0 Phase 4 (i18n + Dutch)`.
6. Push to origin.
7. GitHub → Releases → new release tagged `v2.4.0`. Publish.
8. WordPress auto-updates.

## Files in this delivery

### Modified
- `talenttrack.php` — version bumped to 2.4.0.
- `readme.txt` — stable tag + changelog.
- `assets/js/public.js` — strings now read from a localized `TT.i18n` object; no hardcoded English.
- `src/Shared/Frontend/DashboardShortcode.php` — passes translated strings to the script via `wp_localize_script`.
- `src/Shared/Frontend/PlayerDashboardView.php` — uses `LabelTranslator` for goal status / attendance labels (no more untranslatable `ucwords()`).

### Added
- `src/Infrastructure/Query/LabelTranslator.php` — returns translated labels for goal statuses, priorities, player statuses, attendance statuses.
- `languages/talenttrack.pot` — master translation template (245 strings).
- `languages/talenttrack-nl_NL.po` — Dutch translation source.
- `languages/talenttrack-nl_NL.mo` — Dutch translation compiled (what WordPress actually loads).

### Unchanged
- Every other file — existing modules, REST, DB, roles, auth, logging, audit, feature toggles, config, menu — all carry through from v2.3.0 untouched.

## How to switch the interface to Dutch

1. In WordPress admin → **Settings → General → Site Language**.
2. Choose **Nederlands**.
3. Save.
4. Refresh the page. The TalentTrack admin menu now shows "Spelers", "Evaluaties", "Doelen", etc.
5. Log out and visit the page with `[talenttrack_dashboard]` — the login form is in Dutch ("Inloggen", "Onthoud mij", "Wachtwoord vergeten?").
6. Log in — all dashboard tabs and forms are translated.

To switch back to English, set Site Language to **English (United States)**.

## What you'll see

**Examples of the Dutch translation:**
- Admin menu: Spelers / Teams / Evaluaties / Trainingen / Doelen / Rapporten / Instellingen
- Roles: Hoofd Opleidingen / Clubbeheerder / Trainer / Scout / Staf / Speler / Ouder
- Goal statuses: Open / Bezig / Afgerond / Gepauzeerd / Geannuleerd
- Attendance: Aanwezig / Afwezig / Te laat / Geblesseerd / Afgemeld
- Login form: "Log in om verder te gaan" / "Gebruikersnaam of e-mail" / "Wachtwoord" / "Onthoud mij" / "Inloggen" / "Wachtwoord vergeten?"

**What stays in English / original:**
- Position codes (GK, CB, CM, CAM, LW, etc.) — these are data, not UI labels
- Player names, team names, evaluation notes — user-entered content
- Audit log internal action codes (`player.saved`, `evaluation.deleted`) — technical identifiers

## Adding another language later

The template is at `languages/talenttrack.pot`. To add, e.g., German:

1. Install [Poedit](https://poedit.net/) (free).
2. Open the `.pot`, save as `talenttrack-de_DE.po`, translate all strings.
3. Poedit auto-generates `talenttrack-de_DE.mo` on save.
4. Drop both files into the `languages/` folder.
5. Set WP site language to Deutsch.

## Post-install verification

1. Switch site language to Nederlands.
2. TalentTrack admin menu should show "Spelers", "Evaluaties", etc.
3. Click "Spelers" → page header should be "Spelers" with "Nieuwe toevoegen" button.
4. Log out and visit `[talenttrack_dashboard]` page → login form in Dutch.
5. Switch back to English → everything reverts.
