# TalentTrack v4.2.1 — Wizard URL query var rename: `slug` → `tt_wizard` (closes #901)

## Pilot report

After v4.0.1 shipped (#860, `RecordLink::dashboardUrl()` canonicalisation), the pilot reported:

> "Tournament wizard still reaches a 404 — same for blueprint wizard"

Both `?tt_view=wizard&slug=new-team-blueprint&team_id=4` and `?tt_view=wizard&slug=new-tournament&…` returned a document-level 404 on the pilot's Strato install. Same symptom we'd chased through three prior fixes (#766 v3.110.172, #782 v3.110.180 + v3.110.182, #860 v4.0.1). All three landed correct fixes for real URL-construction bugs — none of them touched the query-var name, which turned out to be the actual root cause.

## Diagnostic that confirmed the hypothesis

Two URLs on the same install:

| URL | Result |
|---|---|
| `http://jg4it.mediamaniacs.nl/?tt_view=team-blueprints&team_id=4` | Works — dashboard renders the team blueprints list. |
| `http://jg4it.mediamaniacs.nl/?tt_view=wizard&slug=new-team-blueprint&team_id=4` | **404** at document level. |
| `http://jg4it.mediamaniacs.nl/?tt_view=wizard&zzz=new-team-blueprint&team_id=4` | Works — dashboard renders, wizard view fires its "Unknown wizard" notice (because `zzz` isn't read). |

Only difference between the broken URL and the working one is the query-var name. WordPress is rejecting `?slug=…` at the routing layer, before the `[talenttrack_dashboard]` shortcode ever runs.

## Diagnosis

`slug` is not a TalentTrack-namespaced query var. WordPress core doesn't use `slug` as a public query var by default — `name` is the canonical "find post by slug" var — but **plugins routinely register `slug` as public via the `query_vars` filter**. Yoast SEO is the most common; several caching and redirect plugins do it too. On installs running such a plugin, `WP_Query::parse_query()` treats `?slug=new-team-blueprint` as a request to resolve a post with that slug, sets `is_singular = true`, finds no matching post, sets `is_404 = true`, and the response is a 404 page *before* the dashboard shortcode runs.

This matches every observed symptom:

- 404 at document level, not at shortcode-render level.
- Hits **both** the blueprint and tournament wizards equally (both used `slug=`).
- Manifests only on installs with the offending plugin active — dev / staging installs without it worked fine, which is why all three prior fixes appeared to work locally but the pilot kept seeing 404s.
- Every other dashboard view (`team-blueprints`, `tournaments`, `players`, etc.) keeps working — they carry no `slug` arg.

Every other TalentTrack query var is prefixed (`tt_view`, `tt_back`, `tt_mfa_required`). The wizard's `slug` was the lone unnamespaced one.

## Fix

The query var is renamed to `tt_wizard` (matches the existing `tt_view` / `tt_back` convention; cannot collide with any third-party plugin's query var). Touched files:

### Write sites — emit `tt_wizard` instead of `slug`

- `src/Shared/Wizards/WizardEntryPoint.php::urlFor` — every "+ New …" button across the dashboard.
- `src/Shared/Frontend/FrontendWizardView.php::wizardStepUrl` — step-to-step redirect during wizard navigation.
- `src/Modules/Mfa/Auth/MfaLoginGuard.php::handle` — the MFA enrollment forced-redirect.

### Read sites — read `tt_wizard` first, fall back to legacy `slug`

- `src/Shared/Frontend/FrontendWizardView.php::render` — main dispatch.
- `src/Modules/Mfa/Auth/MfaLoginGuard.php::handle` — the guard's "are we already on the MFA enrollment wizard" check.

The back-compat fallback keeps any bookmarked or shared pre-v4.2.1 `?slug=…` URL working for one release. Removal of the fallback is a candidate for the next minor.

### Strip-list updates

- `WizardEntryPoint::dashboardBaseUrl()` strips `tt_wizard` alongside the existing `slug` from the base URL before adding fresh wizard args, so an in-flight wizard URL never leaks its previous slug into a fresh wizard link.

### Untouched on purpose

- `WizardDraftRestController` — route is `/wizards/(?P<slug>[a-z0-9_-]+)/draft`, a path pattern not a query var. REST routing doesn't go through `WP_Query`; this endpoint was never affected.
- All other `'slug' =>` occurrences across `src/` — unrelated (admin menu slugs, methodology lookup slugs, etc.).

## Why prior fixes didn't help

- **#766 (v3.110.172)** fixed the wizard's post-step redirect — `wp_safe_redirect` was rejecting non-canonical hosts. Correct fix, different bug.
- **#782 (v3.110.180)** refined the redirect via `home_url($path)` to bypass `esc_url_raw` mangling. Correct, different bug.
- **#782 follow-up (v3.110.182)** added `currentDashboardUrl()` for in-request submit handlers. Correct, different bug.
- **#860 (v4.0.1)** fixed `RecordLink::dashboardUrl()` to canonicalise the REQUEST_URI fallback. Correct, different bug.

All four corrected real bugs in URL construction. The entry URL has been correctly built all along — it just landed on a `slug=` value that WP refused to route past on installs running a plugin that claimed `slug` as a public query var.

## What pilots and operators should know

- Pilot install (jg4it.mediamaniacs.nl) — every wizard entry should now work.
- Other installs — no behavioural change visible; both wizards were already working there.
- Bookmarked old-format URLs (`?tt_view=wizard&slug=…`) continue to resolve for one release via the back-compat shim. Re-bookmark after testing; the legacy fallback is a removal candidate in the next minor.

## Bumped

`talenttrack.php` Version + `TT_VERSION` constant + `readme.txt` Stable tag: `4.2.0` → `4.2.1`. Patch bump — bug fix within the current minor, no behavioural change visible to working installs, back-compat shim covers old URLs.
