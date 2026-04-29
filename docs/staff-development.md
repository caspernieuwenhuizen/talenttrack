<!-- audience: user -->

# Staff development

The plugin tracks players in detail. From v3.58.0 it tracks the **people who coach those players** with the same primitives — goals, evaluations, a personal-development plan — plus a certification register that has no player-side equivalent. The module is opt-in (you can disable it under wp-admin → Configuration → Feature toggles), but it ships enabled by default for new installs.

## What you get

A new "Staff development" tile group on the dashboard, with five tiles:

- **My PDP** — your personal-development plan. Four fields: strengths, development areas, actions next quarter, and a free-form narrative for context. One row per (you, season). Save updates the same row; the previous content is overwritten, so use the narrative for history.
- **My staff goals** — personal-development goals. Each goal has a title, priority, status, optional due date, and an **optional link to a certification** (e.g. "Take UEFA-B"). When linked, the goal appears alongside the certification on the certifications tile so the trail from "I want this" → "I have this" is visible.
- **My staff evaluations** — the log of self-evaluations and (where you have permission) top-down evaluations from the head of development. The eval-category tree for staff is independent from the player tree; it ships seeded with five mains: *Coaching craft / Communication / Methodology fluency / Mentorship / Reliability*. Add subcategories under wp-admin → Configuration → Eval categories like you would for the player tree.
- **My certifications** — the badge register. Each row carries an issuer, an "issued on" date, an optional "expires on" date, and an optional document URL (Google Drive, OneDrive, intranet — the plugin doesn't host the file). Rows expiring within 90 days get an amber pill, within 30 days red, and expired grey. The same colour ladder drives the certification-expiring workflow template.
- **Staff overview** — the head-of-development roll-up. Three cards: open staff goals across the academy, top-down reviews overdue (no review in the past 365 days), and certifications expiring in the next 90 days. Each row links to the relevant detail surface. Visible only to head-of-development and club-admin roles.

## Functional role: Mentor

The migration adds a **Mentor** functional role to the existing list (Head Coach / Assistant Coach / Manager / Physio / Other). Mentors are paired with a mentee staff member through the `tt_staff_mentorships` pivot — admin-grant via the People page, same flow as the other functional roles. Mentors get manage-scope on their mentee's staff-development records (PDP / goals / evaluations / certifications). They don't get blanket access to all staff.

## Workflow templates

Four templates register with the workflow engine on module boot:

- **Annual staff self-evaluation** — fires Sept 1 at 00:00, one task per non-archived staff member, 30-day deadline. Form points the user at the My evaluations tile.
- **Top-down staff review** — same Sept 1 cron, assigned to head-of-development, 60-day deadline. One task per staff member.
- **Staff certification expiring** — daily 06:00 cron walks `tt_staff_certifications.expires_on` against four threshold windows (90 / 60 / 30 / 0 days). Engine-side dedup prevents the same (cert, threshold) firing twice. Assignee: the staff member who holds the cert; head-of-development is CC'd via the existing notification channel.
- **Staff PDP season review** — fires when a season is set current (the existing `tt_pdp_season_set_current` action from #0044's PDP cycle module). Fans out one task per staff member: refresh your PDP for the new season.

All four use the shared `StaffStubForm` placeholder for now — completing the task takes the user to the relevant tile, where they fill in the data through the regular UI. Dedicated task forms (richer than the placeholder) ship in a follow-up PR if usage signal warrants the extra surface.

## What this is *not*

- **A setup wizard for new staff.** That's #0024. This module is personal-development for staff who already have a `tt_people` row.
- **Anonymous evaluations.** The reviewer is recorded on every eval row.
- **Document storage for certifications.** v1 stores a URL pointing at the document, which lives wherever your academy already keeps such files (Google Drive, OneDrive, file system, etc.).
- **Cross-academy benchmarking.** Per-club only.
- **Peer evaluations.** v1 ships `self` and `top_down` review kinds. Peer reviews are intriguing but generate political conversations most academies aren't ready for; deferred.

## Capabilities

| Capability | Granted to | What it allows |
| --- | --- | --- |
| `tt_view_staff_development` | Administrator, Head of Development, Club Admin, Coach, Scout, Staff | See your own staff-development records on the dashboard. |
| `tt_manage_staff_development` | Administrator, Head of Development, Club Admin | Edit any staff member's records. Mentors get this scoped to their mentee(s) via the `tt_staff_mentorships` table. |
| `tt_view_staff_certifications_expiry` | Administrator, Head of Development, Club Admin | See the org-wide certification-expiry roll-up tile. |

The existing auth-matrix gate also kicks in: a non-manager user can only write to records on their own `tt_people` row (enforced in `StaffDevelopmentRestController::can_manage_target`).

## REST surface

Resource-oriented routes under `talenttrack/v1`:

```
GET    /staff/{person_id}/goals           POST   /staff/{person_id}/goals
PUT    /staff-goals/{id}                   DELETE /staff-goals/{id}

GET    /staff/{person_id}/evaluations     POST   /staff/{person_id}/evaluations
PUT    /staff-evaluations/{id}             DELETE /staff-evaluations/{id}

GET    /staff/{person_id}/certifications  POST   /staff/{person_id}/certifications
PUT    /staff-certifications/{id}          DELETE /staff-certifications/{id}

GET    /staff/{person_id}/pdp             PUT    /staff/{person_id}/pdp     (upsert)

GET    /staff/expiring-certifications     (manager-only roll-up)

GET    /staff/{person_id}/mentorships     POST   /staff/{person_id}/mentorships
                                          DELETE /staff-mentorships/{id}
```

All endpoints declare `permission_callback` against the capability layer (no role-string compares). The PHP views and the REST controller both call into the same repositories, so a future SaaS frontend gets the same answers as the plugin's rendered HTML.
