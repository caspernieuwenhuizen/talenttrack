<!-- type: feat -->

# #0026 — Guest-player attendance

## Problem

Coaches regularly run sessions where players from outside the team's roster attend: a U13 kid called up to U14 to fill in for an injured regular, a player from another club doing an informal trial day, an off-roster guest at a friendly. Today the attendance form has no way to record them, so coaches either (a) silently omit the data, or (b) permanently add the guest to the team's roster and forget to remove them. Both outcomes degrade roster data, team podium, team-level rolling stats, and team-chemistry calculations.

The plugin needs a first-class "guest" concept on attendance — a row that records the guest's presence without polluting team-scoped aggregates.

## Proposal

Add a `is_guest` flag plus optional guest-detail columns to `tt_attendance`. A guest row is either **linked** (references an existing `tt_players` record from another team) or **anonymous** (free-text name + age + position, no player record). Team-level queries filter out `is_guest = 1`. The host coach can evaluate **linked** guests normally; anonymous guests get a free-text notes field instead.

Decisions locked during shaping (25 April 2026):

- **Ship separately from #0017 (Trial player module).** #0026 is a substrate for cross-team call-ups and ad-hoc training visits — a 10h feature. #0017 is a 6-sprint formal-trial workflow blocked behind #0014 Sprint 3 + #0022 Phase 1. Both layers will coexist; when #0017 ships, a `tt_trial_cases` row can optionally back a guest entry without schema changes.
- **Linked guests evaluable; anonymous guests are notes-only.** Linked guests already have a `tt_players` record, so a normal evaluation entry from the host coach attaches cleanly to that player's profile. Anonymous guests have no record; structured evals would need to migrate at promotion time and add edge-case complexity. Free-text notes on the attendance row cover the value.
- **Promotion path; no auto-archive.** Any anonymous guest can be promoted to a real `tt_players` record at any time (preserves attendance history). Anonymous guest entries persist indefinitely — no time-based cleanup. Trade-off accepted: data accumulates, but `is_guest` filtering keeps it out of operational queries.
- **Cap interaction (vs #0011) deferred.** When monetization ships, `LicenseGate::can()` checks for adding a guest may be needed (anonymous guests in particular have abuse potential on free tier). Spec leaves a hook; specific cap behavior is determined inside #0011's feature-audit sprint.

## Scope

### Schema

New migration adding columns to `tt_attendance` (all nullable, default-safe so existing rows are unaffected):

| Column | Type | Notes |
| --- | --- | --- |
| `is_guest` | `TINYINT(1) NOT NULL DEFAULT 0` | Flag; non-guest rows keep current behavior. |
| `guest_player_id` | `BIGINT UNSIGNED NULL` | FK to `tt_players` for linked guests. NULL for anonymous. |
| `guest_name` | `VARCHAR(120) NULL` | Anonymous guest display name. NULL for linked. |
| `guest_age` | `TINYINT UNSIGNED NULL` | Optional. |
| `guest_position` | `VARCHAR(60) NULL` | Optional; free-text or pulled from positions lookup. |
| `guest_notes` | `TEXT NULL` | Coach-authored free-text observations on anonymous guests. |

Application-level invariant (not a DB constraint, but enforced in the save path): `is_guest = 1` requires either `guest_player_id IS NOT NULL` **or** `guest_name IS NOT NULL`. Both at once is invalid.

Index: `KEY idx_session_guest (session_id, is_guest)` — supports the "fetch guests for this session" query without scanning the full attendance table.

### Attendance UI — adding guests

Inside the attendance roster on the existing session edit/create view:

- **Roster section** — current team players, unchanged.
- **Guests section** — separate visual block below the roster. Empty by default.
- **"+ Add guest"** button at the bottom of the guest section. Opens a small modal/panel with a tab toggle:
  - **Linked** (default tab) — `PlayerPickerComponent` filtered to all players the coach is authorized to see (cross-team via `QueryHelpers::get_players_for_user`, scoped by access control). Pick a player → guest row created with `guest_player_id` set, attendance status defaults to "present".
  - **Anonymous** — text inputs: name (required), age (optional, 6-19), position (optional dropdown from positions lookup or free text). On save → guest row with `guest_name`/`guest_age`/`guest_position` set, `guest_player_id` left NULL.
- Saved guest rows render with a distinct visual: italic name + small "Guest" pill (CSS `.tt-attendance-row--guest .tt-guest-badge`). Linked guests show home-team name in muted text; anonymous guests show "(unaffiliated)".

Mobile reflow: the existing 640px stack pattern applies. The "+ Add guest" button stays visible at the bottom of the list.

### Evaluation flow

- **Linked guest** — Eval button on the row triggers the normal evaluation form. Saved to `tt_evaluations` with `player_id = guest_player_id`. The evaluation appears on that player's profile *and* on the host team's evaluation list (with no special distinction — it is, mechanically, a normal eval written by a coach who has access to the player).
- **Anonymous guest** — No eval button. The row's `guest_notes` textarea is the only structured note. Inline-editable; saves via `PATCH /attendance/{id}` (existing endpoint extended to accept `guest_notes`).

### Promotion flow (anonymous → real player)

On any anonymous guest row, an **"Add as player"** action:

1. Opens the standard player-create form, prefilled from `guest_name`/`guest_age`/`guest_position`.
2. Coach picks a target team (or "no team yet") and adjusts fields.
3. On save:
   - New `tt_players` record created.
   - The triggering attendance row updates: `guest_player_id` set to the new player ID, `guest_name`/`guest_age`/`guest_position` cleared, `is_guest` stays at 1 (the historical fact that they attended as a guest is preserved).
   - All other anonymous attendance rows that match the guest's name + age get a "promote these too?" follow-up dialog (best-effort matching; coach confirms each).

### Team-level stat query updates

Every team-scoped query that aggregates attendance must exclude guest rows. Audit the call sites of:

- `QueryHelpers::get_attendance_summary` (or equivalent)
- Team podium service
- Team-level rolling-stats service
- Team chemistry (#0018 — already deferred but flag the consideration)

Add `AND is_guest = 0` (or wrap in `apply_guest_scope`) to each team-context query. Player-profile queries (which scope to a single player_id) don't need filtering — a guest's appearance on their own profile is the desired behavior.

### Reporting (out of v1)

A "Guest activity" report (linked + anonymous, in a date range, grouped by host team) is genuinely useful for HoD review. Shape as a separate idea after this lands. Spec does not include it.

## Out of scope (v1)

- **Auto-archive of stale anonymous guest entries.** Decision: persist forever, accept data accumulation.
- **Cross-club data sharing of guest evals back to the home club.** A linked guest's eval appears on their profile (visible to the home coach via existing access rules). A formal "send eval to home club" flow is not modeled.
- **Public-facing trial application form.** Out of scope; flagged for #0017.
- **Bulk-import guests from CSV.** Probably never needed; if it is, follow-up.
- **A dedicated "Guest activity" report.** Tracked as a future idea; mention in cross-references.
- **Cap-aware soft-block** of guest creation on free tier when #0011 ships. The cap interaction is deferred to the #0011 feature-audit sprint, which will decide whether/how to gate guest creation.

## Acceptance criteria

- [ ] **Migration**: new columns exist on `tt_attendance` with defaults; existing rows untouched and continue to function.
- [ ] **Schema invariant**: save path rejects rows with `is_guest = 1` and both `guest_player_id` and `guest_name` NULL (or both non-NULL).
- [ ] **Linked guest add**: coach can search across authorized players, pick one, create a guest attendance row. Row links to `tt_players` via `guest_player_id`.
- [ ] **Anonymous guest add**: coach enters name/age/position; attendance row created with those fields; `guest_player_id` is NULL.
- [ ] **Visual differentiation**: guest rows render distinctly from regular roster rows (italic name + "Guest" badge); linked guests show home team, anonymous guests show "(unaffiliated)".
- [ ] **Linked-guest evaluation**: host coach can write an evaluation on a linked guest; appears on the guest's profile and the host team's evaluation list.
- [ ] **Anonymous-guest notes**: free-text `guest_notes` textarea is editable inline and saves via the existing attendance PATCH endpoint.
- [ ] **Promotion**: anonymous guest can be promoted to a real player; attendance row updates to reference the new player; original guest fields cleared; `is_guest` preserved (historical fact).
- [ ] **Stats isolation**: team podium, team-level rolling stats, team-chemistry calcs all exclude `is_guest = 1` rows. Verified by adding a guest to a team's session and confirming the team's aggregates don't change.
- [ ] **Player profile**: a linked guest's appearance shows up on their own profile (with attendance entry + any host-coach evals).
- [ ] **Mobile (375px)**: guest section + add-modal usable without horizontal scrolling.
- [ ] **Translations**: new UI strings translated in `nl_NL.po`.
- [ ] **Docs**: extend `docs/sessions.md` (and `docs/nl_NL/sessions.md`) with a Guest attendance section.
- [ ] **No regression**: existing attendance flows unchanged; existing data continues to render and aggregate correctly.

## Notes

### Why no auto-archive

Decided during shaping: the value of having a complete attendance history outweighs the cost of unbounded growth. Anonymous guest rows are small (≤500 bytes each), filtering by `is_guest = 0` is cheap with the new index, and a coach who genuinely wants old guests gone can promote them or delete the rows manually. If accumulation becomes a real problem (e.g., 100k+ anonymous rows on a single install), an auto-archive sweep can be added later as a separate pass.

### Why linked-only for evals

Anonymous guests have no `tt_players` record. A structured evaluation against an anonymous guest would either need to live on the attendance row itself (parallel evaluation table) or block until promotion. Both add complexity for a use-case the host club shouldn't be heavily invested in (if the guest matters enough to evaluate formally, they belong as a real player). Notes-only is the cleanest line.

### #0017 integration plan

When #0017 ships, a `tt_trial_cases` row will reference one or more guest attendance entries (linked guest = trialist who already has a player record; anonymous = day-zero anonymous trial that gets promoted as part of the trial onboarding). No schema change here; `tt_trial_cases.player_id` already references `tt_players`, and the promotion flow gives that row a real player ID. The trial module orchestrates the multi-week structured workflow on top of this substrate.

### Touches

Existing:
- `tt_attendance` schema — new migration.
- `src/Modules/Sessions/` — attendance save path: enforce the linked/anonymous invariant; extend PATCH endpoint to accept `guest_notes`.
- `src/Shared/Frontend/CoachForms.php` (or equivalent attendance UI) — render guests section, add-guest modal.
- `src/Shared/Frontend/FrontendSessionsManageView.php` (post-#0019 Sprint 2.3) — same.
- `src/Infrastructure/Query/QueryHelpers.php` — audit team-level attendance aggregations; add `is_guest = 0` filter.
- `src/Modules/Players/Admin/PlayersPage.php` — promotion flow handler (player create + attendance backlink).
- `src/Modules/Stats/` — team podium, rolling stats: exclude guests.
- `assets/css/frontend-admin.css` — `.tt-attendance-row--guest`, `.tt-guest-badge`.
- `docs/sessions.md` + `docs/nl_NL/sessions.md` — new "Guest attendance" subsection.
- `languages/talenttrack-nl_NL.po` — Dutch strings.

Possibly new:
- `src/Shared/Frontend/Components/GuestAddModal.php` — small component for the add-guest panel.

### Depends on

- **#0019 Sprint 2.3 (sessions frontend)** — the attendance UI updated by Sprint 2.3 is where the "+ Add guest" button lives. Need the post-Sprint-2.3 attendance render path as the integration point.
- Nothing else in the active backlog blocks #0026.

### Blocks

- **#0017 (Trial player module)** — not strictly blocked, but #0017's onboarding flow benefits from #0026 being in place first (otherwise #0017 has to model attendance-without-roster from scratch).

### Sequence position

Phase 1 follow-on, post-#0019. Slot when convenient. Independent of #0011 (cap behavior deferred there) and #0023 (styling).

### Sizing

~10 hours:

| Work | Hours |
| --- | --- |
| Migration + schema + save-path invariant | 1.0 |
| Add-guest modal (linked + anonymous tabs) | 2.0 |
| Linked vs anonymous attendance row rendering | 1.0 |
| Eval-form gating for guests (linked = normal, anonymous = notes only) | 1.0 |
| Promotion flow (anonymous → real player, with backlink) | 1.5 |
| Team-level stat query audit + `is_guest` filtering | 1.0 |
| CSS for distinct guest row styling | 0.5 |
| `.po` updates + docs/sessions.md sections (EN + NL) | 1.0 |
| Testing across linked/anonymous/promoted paths | 1.0 |
| **Total** | **~10h** |

Single PR, single release. Minor bump (next available — likely v3.17.0 if #0023 hasn't landed first, otherwise the one after).

### Cross-references

- Idea origin: [`ideas/0026-feat-guest-player-attendance.md`](../ideas/0026-feat-guest-player-attendance.md).
- Trial player module: [`ideas/0017-epic-trial-player-module.md`](../ideas/0017-epic-trial-player-module.md) — the eventual long-form trial workflow that layers on this substrate.
- Future idea: "Guest activity report" for HoD review (flag for follow-up).
