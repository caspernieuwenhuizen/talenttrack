<!-- type: feat -->

# #0019 Sprint 7 — PWA shell + Documentation viewer

## Problem

The frontend is feature-complete after Sprints 1–6, but it's still "a web page." Coaches at the pitch side would benefit from:

- **Home-screen installability**: tap-to-launch like a native app, full-screen without browser chrome, branded icon.
- **Offline resilience**: if signal drops mid-session-entry or mid-evaluation, their work isn't lost.

Separately, the in-plugin documentation (wiki / help) still lives in wp-admin. Admins and power users who want to read the docs while working on the frontend have to switch context. Small but real friction.

Sprint 7 is the epic's finale: the frontend becomes a proper web-app experience, and the last significant wp-admin-only surface (documentation) moves over as read-only.

## Proposal

Two independent deliverables:

1. **PWA shell**: manifest + service worker + minimal offline support (scoped to form drafts — reuses the localStorage drafts from Sprint 1 as the entire offline story).
2. **Documentation viewer (read-only)**: port the wiki reading surface from wp-admin to the frontend. Editing stays wp-admin (the docs are markdown files in the repo).

## Scope

### PWA shell

**Web app manifest** (`/manifest.webmanifest`):

- App name ("TalentTrack") and short name ("TalentTrack")
- Start URL (the frontend dashboard)
- Display mode: `standalone` (no browser chrome)
- Theme color matching the plugin's brand color
- Icons at 192x192, 256x256, 384x384, 512x512 (PNG, maskable). Uses the club logo from Configuration if set; falls back to a TalentTrack default.
- Scope: the site root

**Manifest link** in the frontend page head:
```html
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="<from-config>">
<link rel="apple-touch-icon" href="<logo>">
```

**Service worker** (`/talenttrack-sw.js`):

- Registration on frontend page load (only on HTTPS origins — graceful fallback on localhost).
- Install event: cache the frontend shell (CSS, JS, basic icons). NOT caching data — stays fresh-from-network.
- Activate event: clean up old cache versions.
- Fetch event: **network-first for everything**, with a stale-while-revalidate fallback for static assets (CSS/JS/icons). No aggressive caching of REST responses — that would cause stale-data issues.
- Offline page: if a page load fails because no connection, show a minimal offline page with "You're offline. Your unsaved work is preserved — return to the dashboard when connected."

**Offline form drafts**:

- No new code here! Sprint 1 already built localStorage-backed form drafts on every form.
- Sprint 7 ensures: the drafts.js module is included in the service worker's install cache, so forms work to accept input even when offline.
- When the user tries to submit while offline: capture the form state as a draft, show a flash message "Saved locally — will sync when online. Do not close the tab until connected."
- No automatic sync — when online, a banner offers: "You have 3 unsaved submissions. Retry them?" The user decides.

**Installability UX**:

- After 2 visits in the same session (or configurable), show an unobtrusive banner: "Install TalentTrack on your home screen — tap to add." Dismissible, respects user choice (stored in localStorage — no annoying re-prompts).
- Tapping the banner triggers the browser's native install prompt.

### Documentation viewer (read-only, frontend)

View: `src/Shared/Frontend/FrontendDocumentationView.php`.

Scope:

- Reads the existing markdown wiki files (already under `docs/` or similar in the repo).
- Renders them frontend-side with the plugin's existing markdown renderer (if present) or `parsedown` via composer.
- Navigation: a sidebar with the list of docs (same structure as wp-admin Documentation). Click a doc → render in the main pane.
- Search: simple text search across all docs (backend endpoint returns matches with snippets).
- Deep-linkable: each doc has a URL slug (`?doc=getting-started`).
- Mobile-friendly: sidebar collapses into a toggle-able drawer on mobile.

**Not editable from frontend**: if an admin wants to edit docs, they edit the markdown source files in the repo (or use the wp-admin Documentation surface which is kept intact). The frontend viewer is read-only.

**Availability**: The Documentation tile is visible to any user who can access TalentTrack. Not gated behind `tt_access_frontend_admin` — docs are for everyone.

## Out of scope

- **Full offline-with-sync architecture.** Drafts-only is the offline story. No conflict resolution, no background sync, no offline-first rewrite. Separate epic if ever.
- **Native app wrapper.** Not this epic.
- **Edit surface for docs on frontend.** Read-only only; editing stays wp-admin or repo-markdown.
- **Complex cache strategies.** Simple network-first with shell cache is sufficient. No per-URL caching rules, no TTL logic.
- **Web Push notifications.** Installability is in scope, push is not. Separate idea if desired.
- **Offline analytics / usage tracking.** If a usage event happens offline, it's lost. That's acceptable.

## Acceptance criteria

### PWA

- [ ] `/manifest.webmanifest` is served with correct JSON.
- [ ] Service worker registers on HTTPS origins; doesn't register on HTTP or localhost.
- [ ] Lighthouse PWA audit passes installable criteria.
- [ ] On a modern mobile browser (Chrome Android, Safari iOS), "Add to Home Screen" is offered.
- [ ] After install, launching from home screen opens in standalone mode (no browser chrome).
- [ ] With network disabled, visiting a previously-loaded page shows a minimal offline page (not the browser's default error).
- [ ] With network disabled, form drafts persist via localStorage (already built in Sprint 1).
- [ ] Re-enabling network, the "You have X unsaved submissions" banner appears and allows retry.
- [ ] Lighthouse Performance score ≥ 80 on the frontend dashboard page (mobile).

### Documentation viewer

- [ ] Documentation tile appears in the frontend tile grid (no special cap required).
- [ ] Clicking a doc renders it with correct markdown formatting.
- [ ] Search across docs works.
- [ ] Mobile viewport: sidebar collapses, main content is readable without horizontal scroll.
- [ ] Deep-linkable URLs work — sharing a URL to a specific doc opens that doc directly.

### No regression

- [ ] All existing frontend features work unchanged.
- [ ] wp-admin Documentation page still works (not removed).
- [ ] No new JS errors or PHP warnings.

## Notes

### Sizing

~10 hours total. Breakdown:

- PWA manifest + icons: ~2 hours
- Service worker with network-first + shell cache: ~3 hours
- Offline page + drafts integration: ~1 hour
- Install prompt UX: ~1 hour
- Documentation viewer: ~3 hours (the content lives in markdown files, renderer exists)

### Why this scope is right-sized

The full "PWA" spec could be 40+ hours of work if you include offline-with-sync, push notifications, background sync, cache strategies per endpoint type. That would be a separate epic. What Sprint 7 delivers is the *baseline PWA* that:

- Feels like an app (installable, standalone, branded icon).
- Preserves work when signal drops (via localStorage drafts).
- Gracefully degrades when offline (minimal offline page, not browser error).

For a youth football academy tool used on phones at pitch sides, that's the right minimum.

### What about iOS?

Safari's PWA support is the weakest among major browsers. Some features (push notifications, full offline behavior) have caveats. The baseline PWA here works on iOS but won't feel as native as on Android. Acceptable trade-off.

### Touches

- `/manifest.webmanifest` (new, served by a REST endpoint or a rewrite rule)
- `/talenttrack-sw.js` (new, served from the plugin)
- `assets/images/pwa-icons/` (new folder with sized icons)
- Frontend page head (in whichever template outputs the frontend `<head>`): manifest link, theme-color meta, apple-touch-icon
- `src/Shared/Frontend/FrontendDocumentationView.php` (new)
- `includes/REST/Documentation_Controller.php` (new, or expand if exists)
- Existing tile grid: add Documentation tile

### Depends on

Sprints 1–6. Specifically: Sprint 1's drafts (the offline story), Sprint 6's legacy-menu work (so the frontend is unambiguously primary before we make it installable).

### Blocks

End of epic #0019.

### After Sprint 7

This is the final sprint of the epic. Post-Sprint-7 work returns to the SEQUENCE.md flow for other items in the backlog.
