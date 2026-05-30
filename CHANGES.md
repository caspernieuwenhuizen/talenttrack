# TalentTrack v4.12.14 — Match-execution score box: home label uses club code; away label falls back to `OPP` (closes #1024)

Two bundled defects on the `?tt_view=match-execution&activity_id=<N>` surface, both visible on every live match the assistant coach runs from a phone.

## Pilot symptom

- Home team labelled `HJ` (derived from "Hedel JO14-1") — should be `HED` (the club code for vv Hedel).
- Away team labelled `—` (em-dash) — should be the opponent's 3-letter abbreviation OR a sensible fallback when the activity has no opponent set.
- Header line read `Hedel JO14-1 vs — · 2026-05-30` — opponent missing entirely; the `vs —` pair is unreadable.

## What ships

### Home abbreviation = club code

- New helper `TT\Shared\Club\ClubIdentity::shortCode()` returns the 3-letter club code:
    - Reads `tt_config['club_short_code']` (operator-editable).
    - Falls back to a derivation from `tt_config['academy_name']` — strips Dutch club-type prefixes (`vv`, `sv`, `fc`, `ac`, `rkvv`, `rkc`, `vvv`, `ev`, `ovv`, `sc`, `asv`, `usv`, `csv`) and uppercases the first three letters of the remaining name. `vv Hedel` -> `HED`, `sv Spakenburg` -> `SPA`, `FC Utrecht` -> `UTR`.
    - Last-ditch fallback: `get_bloginfo( 'name' )` -> derivation, then a localised `HOM` placeholder so the score box never renders an em-dash.
    - Per-request memoised.

- `FrontendMatchExecutionView::render()` calls `ClubIdentity::shortCode()` for the home label instead of running the team name through `abbreviate()`. Every match for vv Hedel now shows `HED` regardless of which JO-team is playing.

### Away abbreviation = opponent (with fallback)

- When `$activity->opponent` is set: `self::abbreviate( $opponent )` derives a 3-letter abbreviation.
- When empty / NULL: renders a localised `OPP` placeholder — never the em-dash.
- `abbreviate()` now strips age-group / team-number suffixes (`JO13`, `MO14`, `U14`, `O19`, `-1`) before deriving so `Den Helder JO13` -> `DEN`, not `DEN13`.

### Header line

- When `$activity->opponent` is empty, the header reads `<Team> · <Date>` instead of `<Team> vs — · <Date>`.

### Configuration surface

- New `Club short code` field on the Branding form (`?tt_view=configuration&config_sub=branding`). `<input type="text" maxlength="3">` with a help hint:
  > Three-letter club abbreviation shown on the match scoreboard (e.g. HED for vv Hedel). Leave empty to derive from the academy name.
- Wired into `ConfigRestController::ALLOWED_KEYS` and `KEY_AREA_MAP` under the `branding` sub-cap area.
- Leaving the field empty falls back to the academy-name derivation at render time, so existing installs get a sensible default with no operator action required.

## Why patch

UX defect bundle within the 4.12 minor. No schema change, no new REST route (existing `POST /talenttrack/v1/config` accepts the new key via its allowlist), no behavioural change on any other surface. The only other caller of `FrontendMatchExecutionView::abbreviate()` is the away column inside the same file.

## Files touched

- `src/Modules/MatchExecution/Frontend/FrontendMatchExecutionView.php` — home/away label resolution, `abbreviate()` rewrite (suffix stripping + `OPP` fallback), header line guard.
- `src/Shared/Club/ClubIdentity.php` — new helper class.
- `src/Shared/Frontend/FrontendConfigurationView.php` — new Branding-form field.
- `src/Infrastructure/REST/ConfigRestController.php` — allowlist + key-area map entry.
- `talenttrack.php` — version bump.
- `readme.txt` — changelog stanza + Stable tag bump.
- `CHANGES.md` — this file.

## Test plan

- Open `?tt_view=match-execution&activity_id=<id>` for a vv Hedel team. Verify the home column reads `HED`. Verify the away column reads either the opponent's derived 3-letter code or `OPP` when the activity has no opponent.
- Open `?tt_view=configuration&config_sub=branding`. Verify the Club short code field is present. Type `XYZ` and Save; reload the match-execution surface — the home label now reads `XYZ`. Clear the field and Save; the home label falls back to the derivation.
- For an activity with `opponent = 'Den Helder JO13'`, verify the away label reads `DEN`, not `DEN13` and not `—`.
- For an activity with `opponent = ''`, verify the header line reads `<Team> · <Date>` (no `vs —`).

## Closes

- #1024
