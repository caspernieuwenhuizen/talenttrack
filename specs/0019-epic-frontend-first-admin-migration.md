<!-- type: epic -->

# Frontend-first admin migration — epic overview

## Problem

TalentTrack's day-to-day work (evaluations, sessions, players, teams, goals, reports, people, configuration) is split across two surfaces: wp-admin pages for the traditional "back-office" work, and a partial frontend built out of tiles, shortcodes, and the `FrontendAjax` class. The division is historical, not designed — it reflects what happened to be ported when, not what users need.

The consequences:

- **Coaches work on phones at the pitch side.** wp-admin is a desktop-first interface WordPress never designed for mobile. Coaches doing live session logging on their phone fight the UI constantly.
- **Two codebases per entity.** Several entities have duplicated logic between `src/Modules/*/Admin/` and `src/Shared/Frontend/`. The two versions drift.
- **Admins are locked to wp-admin.** Club admins who want to approve a player record or update a configuration setting have to go to wp-admin, because that's where those surfaces live. There's no technical reason for this.

The refined ambition: **when an admin logs in, they should be able to do all admin tasks on the frontend**, with wp-admin as an optional fallback for the things WordPress itself owns (plugin activation, core updates, the WP users table). Nothing TalentTrack-specific should *require* wp-admin.

## Proposal

A seven-sprint migration that progressively moves every TalentTrack surface to the frontend, retires the `FrontendAjax` class in favor of REST, and culminates in a PWA-installable frontend that covers the full feature set. The wp-admin pages remain accessible via direct URL as emergency fallback (controlled by a "legacy UI" toggle in settings, default off), but are removed from the admin menu.

Each sprint has its own spec with concrete acceptance criteria. This overview exists so a developer can see the shape of the whole epic before diving into any single sprint.

## Scope

Seven sprints, each a separate spec file:

| Sprint | File | Focus | Effort |
| --- | --- | --- | --- |
| 1 | `specs/0019-sprint-1-foundation.md` | REST expansion, FrontendAjax removal, shared components with drafts, flash-messages, CSS scaffold | ~30h |
| 2 | `specs/0019-sprint-2-sessions-and-goals.md` | Sessions + Goals frontend, reusable list component, bulk attendance | ~22h |
| 3 | `specs/0019-sprint-3-players-and-teams.md` | Players + Teams frontend, CSV bulk on frontend, formation placeholder | ~30–35h |
| 4 | `specs/0019-sprint-4-people-and-functional-roles.md` | People + Functional Roles separate surfaces | ~15–18h |
| 5 | `specs/0019-sprint-5-admin-tier-surfaces.md` | Config, Custom Fields, Eval Categories, Roles, Migrations read-only, Usage Stats | ~28–32h |
| 6 | `specs/0019-sprint-6-legacy-toggle-and-cleanup.md` | Legacy-UI toggle, menu hiding, upgrade notice | ~8–10h |
| 7 | `specs/0019-sprint-7-pwa-and-docs.md` | PWA shell with offline drafts, Documentation viewer port | ~10h |

**Total: ~143–157 hours of driver time** with Claude Code. At 2 hours/day that's ~3.5 to 4 months of elapsed time for the epic — consistent with the SEQUENCE.md estimate that the full backlog is 4–6 months.

## Out of scope

- **WordPress core admin.** Plugin activation, WP user management, core updates, the file editor. WordPress owns these.
- **Plugin activation hook.** Fires from wp-admin's Plugins page via `register_activation_hook`. Can't be ported; but runs once per install, so it doesn't matter.
- **Full SPA rebuild.** Progressive enhancement only — server-rendered HTML with targeted JS. No React/Vue port.
- **Full offline-with-sync.** The Sprint 7 PWA does installable + offline form drafts only. Full offline with conflict resolution is a separate epic if ever.
- **Reports.** Deferred to #0014, which owns the report rebuild.
- **Audit log viewer.** Deferred to #0021 (new idea created during shaping).
- **Monetization tiering.** Kept orthogonal per shaping decision; don't gate admin surfaces behind paid tiers in this epic.
- **Native mobile app.** PWA is the end state for this epic. Native is separate if ever.

## Acceptance criteria

The epic is done when:

- [ ] Every TalentTrack surface is available and fully functional on the frontend (including admin-tier tasks: configuration, custom fields, eval categories, roles, migrations status, usage stats).
- [ ] `FrontendAjax` has been deleted from the codebase. All write paths go through REST controllers under `includes/REST/`.
- [ ] A club admin can complete every TalentTrack task without opening wp-admin (except WP-core things: plugin activation, core updates, WP user management).
- [ ] The frontend is installable as a PWA; form drafts persist in localStorage and survive signal loss at the pitch side.
- [ ] wp-admin surfaces remain accessible via direct URL (safety fallback) but are hidden from the admin menu by default. A legacy-UI toggle in settings re-exposes them.
- [ ] No coach/HoD-side reports of wp-admin being the only way to accomplish any TalentTrack task after ~2 releases stabilization.

## Notes

### Architectural decisions (locked during shaping)

1. **REST over ajax-admin.** New endpoints under `includes/REST/`. `FrontendAjax` removed entirely in Sprint 1, all current callers migrated at the same time.
2. **Single source of truth per view.** Shared view logic lives in the Module namespace; frontend renders consume it. No duplication between Admin and Shared/Frontend.
3. **Progressive enhancement.** Server-rendered HTML with sprinkled JS. No SPA.
4. **localStorage drafts on every form** from Sprint 1. Drafts are the PWA's offline story.
5. **Legacy-UI toggle, default off.** Migrated wp-admin pages accessible via direct URL but hidden from menus. Upgrade notice on first post-Sprint-6 release.
6. **New capability `tt_access_frontend_admin`** gates the Administration tile group (introduced Sprint 5). Granted by default to `administrator` and `tt_head_dev` WP roles.
7. **Monetization orthogonal.** Frontend vs admin is not a tier distinction.
8. **Formation work is #0018's, not this epic's.** Sprint 3 includes a placeholder only.
9. **Reports are #0014's.** Sprint 4 excludes them.
10. **Audit log viewer deferred to #0021.** Sprint 5 excludes it.

### Cross-epic interactions

- **#0014 (Player profile + report generator)** — Sprint 4 leaves Reports alone. When #0014 ships, its frontend surfaces should follow the conventions established by Sprints 1–3 of this epic.
- **#0017 (Trial player module)** — builds on Functional Roles from Sprint 4. Should be built frontend-first, using the components from Sprint 1.
- **#0018 (Team development / chemistry)** — the formation board lives in its own idea. This epic ships a placeholder in Sprint 3 that #0018 replaces.
- **#0016 (Photo-to-session)** — a new feature, should ship frontend-first using Sprint 1's foundations.
- **#0021 (Audit log viewer)** — new idea created during shaping. Standalone after this epic.

### Risk / rollback

- Any sprint that breaks a migrated surface in production has wp-admin as a fallback (direct URL still works even after menu removal). Users with `manage_options` capability can find any old admin URL if frontend breaks.
- Legacy-UI toggle is the documented recovery path. Flip it on → old menus return → club uses legacy UI for a release or two while the frontend bug is fixed.
