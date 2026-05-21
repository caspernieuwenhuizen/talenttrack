# TalentTrack v4.0.1 — RecordLink frontend entry-link URL helper canonicalises through home_url($path) on the REQUEST_URI fallback (closes #860)

## Pilot report

> still 404

Pilot 2026-05-21 clicked **+ New blueprint** on `?tt_view=team-blueprints&team_id=4` and the wizard URL 404'd at the entry point. Same root cause as the v3.110.172 / v3.110.180 wizard-redirect bugs — but a different code path.

## Root cause

`v3.110.172` (#766) and `v3.110.180` (#782) fixed the wizard's **post-step redirect** by introducing `wizardStepUrl()` that wraps `REQUEST_URI`'s path through `home_url($path)` so `wp_safe_redirect` always gets a fully-qualified URL on the canonical host.

Those fixes did **not** touch the **initial entry-link build**. Every "+ New X" / "Edit Y" link constructed through `RecordLink::dashboardUrl()` still used the old 4-stage fallback chain:

1. configured `dashboard_page_id` → permalink
2. self-healing shortcode-page scan → permalink
3. `esc_url_raw(REQUEST_URI)` cleaned of routing args
4. `home_url('/')`

On the Strato install one of stages 1-2 was producing a URL that didn't resolve cleanly, falling through to stage 3 which then `add_query_arg`-ed into a 404-able URL. The blast radius is large: ~15+ files construct entry URLs through this helper (every Team Blueprints "+ New blueprint", PDP edit/create, onboarding pipeline links, mark-attendance hero "Edit activity", etc.).

## Fix

Apply the `wizardStepUrl()` pattern inside `RecordLink::dashboardUrl()` itself — every caller benefits without a per-surface patch. Stage 3 now:

- Extracts the path from `REQUEST_URI` manually (`strpos`/`substr` on the `?` separator). No `esc_url_raw` round-trip — that's what mangled URLs on Strato per v3.110.180's diagnosis.
- Wraps the path through `home_url($path)` so the result is always fully-qualified on the canonical host.
- Stage 4 (`home_url('/')`) preserved for the genuinely-missing-REQUEST_URI case (CLI runs, unusual proxy configs).

Stages 1-2 are unchanged — installs where `dashboard_page_id` is configured correctly (the majority) keep their fast path.

## Files touched

- `src/Shared/Frontend/Components/RecordLink.php` — single function fix in `dashboardUrl()` step 3.
- `talenttrack.php` + `readme.txt` + `CHANGES.md` — version bump.

No schema, no migration, no REST, no behaviour change beyond the URL resolution. No Dutch translations needed (the change is to URL building, not strings).

## How to test

1. On the pilot's Strato install: navigate to `?tt_view=team-blueprints&team_id=4`. Click **+ New blueprint**. Expect: wizard loads at `?tt_view=wizard&slug=new-team-blueprint&team_id=4`. Before the fix this 404'd.
2. Same sanity check on the other surfaces listed in #860's blast-radius section (PDP edit/create links, onboarding pipeline, mark-attendance hero "Edit activity") — they should all start working on the pilot install.
3. No regression on a clean install where `dashboard_page_id` is correctly configured: links continue to resolve via the dashboard page permalink (step 1 short-circuits).

## Why patch (not minor)

Bug fix, no new behaviour. Per the v4.0.0 SemVer rule: patch.
