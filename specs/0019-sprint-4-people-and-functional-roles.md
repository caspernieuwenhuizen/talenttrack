<!-- type: feat -->

# #0019 Sprint 4 — People + Functional Roles full frontend

## Problem

**People** (staff — physios, team managers, other non-player, non-coach roles) and **Functional Roles** (the per-team assignment layer that pairs a person with a role like "assistant coach", "physio", "team manager") are two closely-related but distinct surfaces.

Today both live in wp-admin:

- **People**: `src/Modules/People/Admin/PeoplePage.php`, `TeamStaffPanel.php`. Manages the person records (first name, last name, role-less identity).
- **Functional Roles**: `src/Modules/Authorization/Admin/FunctionalRolesPage.php`. Defines which functional roles exist (head coach, assistant coach, physio, manager, other). Per-team assignment happens either here or on the team edit surface depending on who's doing the work.

The HoD uses both heavily — assigning staff to teams is a weekly workflow at most academies. Doing this from wp-admin on a desktop is fine; doing it on a phone is not.

## Proposal

Two deliverables, both reusing the foundation from earlier sprints:

1. **People full frontend** — list (via `FrontendListTable`), create/edit/delete, custom fields. Mirrors the Players surface in shape.
2. **Functional Roles full frontend** — definition surface (list + CRUD of functional role types) and assignment surface (which person holds which functional role on which team).

Functional Roles is kept as its own distinct surface per shaping decision — not embedded in the Team edit view. It's cross-cutting, and fragmenting it per-team makes the "who is the physio for U13 next week" query hard.

## Scope

### People frontend

Views under `src/Shared/Frontend/FrontendPeopleManageView.php`:

- **List view**:
  - Powered by `FrontendListTable`.
  - Filters: active/archived, has WP user account, team assignment (any/none/specific team).
  - Columns: name, email, primary role (from their current Functional Role assignment if any), actions.
  - Search: by name or email.
  - Row actions: Edit, Archive.
- **Create/edit view**:
  - Form fields: first name, last name, email, phone (if custom fields include it), notes.
  - Optional WP user linkage: dropdown of WP users (excluding players). Matches the existing pattern.
  - Custom fields: rendered via `CustomFieldRenderer`.
  - Save via REST `People_Controller` (new — doesn't exist in `includes/REST/` today).
  - Draft persistence.

### Functional Roles frontend

Two sub-views:

**Functional Role types** (`src/Shared/Frontend/FrontendFunctionalRoleTypesView.php`):

- Simple CRUD list of the defined functional role types (head coach, assistant coach, physio, team manager, other).
- Drag-to-reorder (reuses the `DragReorder` class — works on frontend with minor CSS adjustment).
- Permission-gated: only users with `tt_manage_functional_roles` capability (already exists).

**Functional Role assignments** (`src/Shared/Frontend/FrontendFunctionalRoleAssignmentsView.php`):

- A matrix or list view: for each team, which functional role types are filled and by whom.
- Powered by `FrontendListTable` with a custom filter: team.
- Columns: team, functional role, person, effective date, actions.
- Row actions: Change person, Unassign.
- "Assign" button creates a new row: pick team, pick functional role, pick person from People list, pick effective date.
- Mobile-friendly: card view shows team header with a list of roles + assignees.

### Cross-links

- Person edit view shows that person's current and historical functional role assignments inline (read-only summary).
- Team edit view (from Sprint 3) gets an "Assignments" section showing who holds which functional role on this team, read-only, with a link to "Manage team assignments" that deep-links into the FunctionalRoleAssignments view filtered by this team.

## Out of scope

- **Reports.** Deferred to #0014 entirely. No Reports surface in Sprint 4.
- **Merging People with WP Users management.** People is a distinct entity with its own schema; it may or may not have a WP user linkage. This epic doesn't change that model.
- **Functional role capability system rework.** Existing caps stay.
- **Bulk assignment operations** (assign-same-person-to-N-teams-at-once). Out of scope — single-assignment flow is enough.
- **Historical/versioned role assignments.** Current assignment is what's shown; the existing schema supports start/end dates but we're not building a full "career history" surface.
- **Trial-case-specific staff input** (e.g. who evaluates a trial player). That's #0017's concern, which is a separate epic but will use this sprint's Functional Roles infrastructure.

## Acceptance criteria

### People

- [ ] HoD/admin can list people with filters + search.
- [ ] Create a new person via the frontend with first name, last name, email, optional WP user linkage, custom fields.
- [ ] Edit and archive people.
- [ ] People list shows each person's current primary functional role (if assigned).
- [ ] Mobile works.

### Functional Roles

- [ ] Admin can manage functional role types on the frontend (list, create, edit, delete, reorder).
- [ ] HoD can assign a person to a functional role for a team via the frontend.
- [ ] HoD can unassign or change who holds a role.
- [ ] The assignments view can be filtered by team.
- [ ] From a team edit view, "who's on staff for this team" is visible inline, with a deep-link to manage.
- [ ] Mobile works.

### No regression

- [ ] wp-admin People and FunctionalRoles pages still work.
- [ ] Existing Functional Role assignments are preserved; nothing is migrated or deleted.
- [ ] Capability gates (`tt_view_people`, `tt_edit_people`, `tt_manage_functional_roles`) work identically on frontend as on admin.

## Notes

### Scope reduction from the original epic outline

The original Sprint 4 included Reports. Per shaping, Reports is deferred to #0014, which owns the report generator rebuild entirely. This makes Sprint 4 meaningfully smaller than first planned.

### Why Functional Roles stays its own surface

Per shaping decision: Functional Roles is cross-cutting. The "who is the physio at this club" question shouldn't be answered by visiting every team individually. The primary home is a global view; the team edit view gets a read-only summary with a deep-link.

### Sizing

~15–18 hours. Breakdown:

- People frontend: ~6 hours
- Functional Role types frontend: ~3 hours (small surface)
- Functional Role assignments frontend: ~5 hours
- Cross-links (person → assignments, team → assignments summary): ~2 hours
- Mobile polish + testing: ~2 hours

### Touches

- `src/Shared/Frontend/FrontendPeopleManageView.php` (new)
- `src/Shared/Frontend/FrontendFunctionalRoleTypesView.php` (new)
- `src/Shared/Frontend/FrontendFunctionalRoleAssignmentsView.php` (new)
- `includes/REST/People_Controller.php` (new)
- `includes/REST/FunctionalRoles_Controller.php` (new)
- Existing tile grid: add People Manage, Functional Roles tiles (gated by cap)
- `src/Shared/Frontend/FrontendTeamsManageView.php` (from Sprint 3): add assignments summary section

### Depends on

Sprint 1, Sprint 2 (FrontendListTable), Sprint 3 (Teams surface exists to link assignments summary from).

### Blocks

None directly. #0017 (Trial player module) depends on these surfaces being in place before its own implementation.
