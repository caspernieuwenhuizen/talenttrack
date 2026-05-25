# TalentTrack v4.2.2 — Exports page form POST architecture (closes #903)

## Pilot context

Pilot environment `jg4it.mediamaniacs.nl` (only TalentTrack active, no other plugins) hit a 403 on every Export card. Diagnosis:

- POST `/wp-json/talenttrack/v1/exports/attendance_register` → `403 rest_cookie_invalid_nonce`
- GET `/wp-json/wp/v2/users/me` (any cookie-authed REST endpoint) → `401 rest_not_logged_in`
- wp-admin pages work; the user IS logged in (visible auth cookie in the request)
- Every TalentTrack wizard works — they submit via standard form POST

REST cookie auth was broken at the host layer (htaccess / wp-config / hosting WAF — outside the plugin's control), but every non-REST surface in TalentTrack worked. The Exports page was the only outlier that required REST auth.

## Architectural pivot

Exports were originally implemented in #797 as `fetch()` POST against `/wp-json/talenttrack/v1/exports/{key}` with `X-WP-Nonce`. That path:

- Required the WP REST cookie pipeline to be functioning.
- Broke the moment a host added REST hardening (Wordfence "REST API protection", iThemes Security "Restrict REST API", certain managed-WP defaults).
- Was the ONLY TalentTrack surface with that dependency.

A file download is exactly the shape server-side form POST handles natively: form submits → PHP validates nonce + cap → headers + bytes → done. No JS needed, no REST cookie pipeline.

## What changed

### `src/Shared/Frontend/FrontendExportsView.php`

1. **`render()`** — top of method now checks `REQUEST_METHOD === 'POST'` + `tt_export_key` presence and routes to `handleExportPost($user_id)`. The handler runs before breadcrumbs / headers so raw download headers can fire without the template chrome being half-flushed.
2. **`handleExportPost()`** (new private static) — verifies `_tt_export_nonce` against the `tt_export` action, sanitises POST data into an `ExportRequest`, runs `ExportService::run()`, streams the file via `Content-Disposition: attachment` + `echo` + `exit`. On any failure (nonce, cap, unsupported format, bad filters, no renderer) `self::$post_error` is set and the page falls through to render normally with an error notice surfaced above the grid.
3. **`renderCard()`** — form opens with `method="POST" action=""` (same URL re-entry), `wp_nonce_field('tt_export', '_tt_export_nonce')` immediately after, plus a hidden `tt_export_key` carrying the exporter key. Filter fields and multi-format chip selector unchanged.
4. **`renderJs()` deleted** — 84 lines of REST-fetch + blob + temporary `<a download>` gone. The form's native submission handles the download via the browser's standard download flow; no JS required for the happy path. The empty `<span class="tt-export-msg">` placeholder stays in markup — harmless without JS, available for future progressive-enhancement work if appetite returns.

### What didn't change

- **`src/Modules/Export/Rest/ExportRestController.php`** — REST route at `/wp-json/talenttrack/v1/exports/<key>` stays registered. Direct-link integrations and the future SaaS client keep working through the REST path; only the in-page Export click stops needing it.
- **`src/Modules/Export/ExportService.php`** — orchestrator unchanged. Both the form-POST handler and the REST controller call the same `ExportService::run()`; no duplicated logic.
- **The 14 registered exporters** — no `requiredCap()` changes, no payload shape changes.
- **Multi-format chip toggle (#864)** — still works. The picked `format` value submits via the form's radio input under its existing `name="format"`.

## Why this is `tech-debt`, not a `bug`

The 403 on `jg4it.mediamaniacs.nl` is an install-environment problem, not a TalentTrack code bug. But the install's failure exposed that exports were the only surface in the plugin requiring REST cookie auth. Other surfaces use form POST and never hit this class of failure. Bringing exports into line with the rest of the plugin is the right architectural call regardless of whether the pilot's host ever fixes its REST hardening. Future installs with REST-hardened hosting (corporate WordPress VIP, Pantheon, certain SiteGround configs) all benefit.

## Validation

- Pilot install: every Export card click now triggers a file download via standard form POST. No `fetch()` to `/wp-json/` on the happy path.
- Dev / staging: file downloads continue to work; the diff is invisible to the end user.
- Direct REST consumer: `curl -H 'X-WP-Nonce: …' -X POST https://…/wp-json/talenttrack/v1/exports/<key>` still returns the file (regression check for the un-deprecated REST path).
- JS disabled: form submits; file downloads. (Test: disable JS in browser dev tools, click any Export button.)
- Nonce expiry: leave the page open for >24h, click Export → red notice "Export request failed: session expired. Please reload the page and try again." surfaces above the grid (no silent failure).
- Bad cap (use a coach account that lacks `tt_export_evaluations` and try the evaluations exporter via crafted POST): ExportService throws `forbidden`, the error message surfaces in the notice block.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.2.1` → `4.2.2`. Patch bump — no architectural break (REST endpoint stays), no behavioural change visible on healthy installs, restores function on REST-hardened installs.
