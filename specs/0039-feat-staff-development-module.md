<!-- type: feat -->

# #0039 — Staff development module

## Problem

The plugin tracks players in detail (goals, evaluations, attendance, methodology, PDP) and tracks staff only as `tt_people` rows with a name, an email, and a functional-role assignment to a team. There's no answer to "how is the head coach of U17 developing as a coach this season?". A club that takes coach development seriously today bolts on a spreadsheet — defeating the point of a centralised tool.

The asymmetry is sharp: the system that helps an academy systematise *player* development has nothing to say about the staff who run that pipeline. Head of academy gets no surface to evaluate coaches; coaches get no surface to track their own learning; certifications (UEFA-A/B, first aid, GDPR, child-safeguarding) live in HR systems or filing cabinets, surfacing only when something expires and a kid can't be coached.

Decision already locked during the 2026-04-27 review: this is **personal-development-for-staff**, not setup-wizard-for-new-staff. The latter overlaps with #0024 and is out of scope here.

## Proposal

A new `StaffDevelopment` module that mirrors the player module's primitives — goals, evaluations, PDP — applied to `tt_people` rows, plus a certifications register that doesn't have a player-side equivalent. Staff-specific concerns (mentor functional role, separate eval-category root, certification expiry warnings) get first-class treatment instead of being shoehorned into the player tables.

One PR, one release. The idea file's v1 / v1.5 / v2 split is dropped; recent compression patterns put a ~30-40h estimate at ~3-5h actual when bundled, and three separate releases would burn more process than the build itself takes.

## Scope

### Schema

Five new tables under `database/migrations/0036_staff_development.php`. All carry `club_id INT UNSIGNED NOT NULL DEFAULT 1` per CLAUDE.md § 3 SaaS-readiness; root entities (`tt_staff_pdp` is the only root here) get a `uuid CHAR(36) UNIQUE`.

```sql
CREATE TABLE tt_staff_goals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id BIGINT UNSIGNED NOT NULL,           -- FK tt_people.id
    season_id BIGINT UNSIGNED DEFAULT NULL,       -- FK tt_seasons.id (nullable for cross-season goals)
    title VARCHAR(255) NOT NULL,
    description TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending / in_progress / completed / archived
    priority VARCHAR(10) NOT NULL DEFAULT 'medium',
    due_date DATE DEFAULT NULL,
    cert_type_lookup_id BIGINT UNSIGNED DEFAULT NULL,  -- if the goal targets a certification (e.g. "Take UEFA-B")
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at DATETIME NULL DEFAULT NULL,
    club_id INT UNSIGNED NOT NULL DEFAULT 1,
    KEY idx_person (person_id),
    KEY idx_season (season_id),
    KEY idx_status (status),
    KEY idx_club (club_id)
);

CREATE TABLE tt_staff_evaluations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id BIGINT UNSIGNED NOT NULL,           -- the staff member being evaluated
    reviewer_user_id BIGINT UNSIGNED NOT NULL,    -- the WP user doing the eval
    review_kind VARCHAR(20) NOT NULL,             -- 'self' | 'top_down'  (peer deferred to v2)
    season_id BIGINT UNSIGNED DEFAULT NULL,
    eval_date DATE NOT NULL,
    notes TEXT,
    archived_at DATETIME NULL DEFAULT NULL,
    archived_by BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    club_id INT UNSIGNED NOT NULL DEFAULT 1,
    KEY idx_person (person_id),
    KEY idx_reviewer (reviewer_user_id),
    KEY idx_season (season_id),
    KEY idx_club (club_id)
);

CREATE TABLE tt_staff_eval_ratings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    evaluation_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,         -- FK tt_eval_categories.id (staff-tree branch)
    rating DECIMAL(3,1) NOT NULL,
    comment TEXT,
    KEY idx_evaluation (evaluation_id),
    KEY idx_category (category_id)
);

CREATE TABLE tt_staff_certifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id BIGINT UNSIGNED NOT NULL,
    cert_type_lookup_id BIGINT UNSIGNED NOT NULL, -- FK tt_lookups.id where lookup_type='cert_type'
    issuer VARCHAR(120),
    issued_on DATE NOT NULL,
    expires_on DATE DEFAULT NULL,
    document_url TEXT,                             -- optional URL — object-storage friendly per CLAUDE.md
    archived_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    club_id INT UNSIGNED NOT NULL DEFAULT 1,
    KEY idx_person (person_id),
    KEY idx_cert_type (cert_type_lookup_id),
    KEY idx_expires (expires_on)
);

CREATE TABLE tt_staff_pdp (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    season_id BIGINT UNSIGNED DEFAULT NULL,
    strengths TEXT,
    development_areas TEXT,
    actions_next_quarter TEXT,
    narrative TEXT,                                -- catch-all for context that doesn't fit the three buckets
    last_reviewed_at DATETIME DEFAULT NULL,
    last_reviewed_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    club_id INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uk_uuid (uuid),
    UNIQUE KEY uk_person_season (person_id, season_id),
    KEY idx_person (person_id),
    KEY idx_club (club_id)
);
```

### Schema additions to existing tables

- `tt_eval_categories.is_staff_tree TINYINT(1) NOT NULL DEFAULT 0` — flag column; staff-tree categories are seeded with `is_staff_tree=1`. The existing tree UI is gated by this flag so the player tree and the staff tree never collide visually.

### Lookups

Two new lookup types seeded by the migration:

- **`cert_type`** — UEFA-A, UEFA-B, UEFA-C, First-aid, GDPR, Child-safeguarding. Per-club editable. Lookup admin UI already handles new types.
- **`staff_eval_category`** is *not* a separate lookup — staff eval categories live in `tt_eval_categories` with `is_staff_tree=1`. Seeded mains: *Coaching craft / Communication / Methodology fluency / Mentorship / Reliability*. No subcategories on initial seed; clubs add their own.

### Functional role

New `Mentor` row in `tt_functional_roles`. Admin-grants via the People page (same flow as Head Coach / Assistant). Mentor → mentee assignment is a row in `tt_team_people` analogue or a new tiny table; **shape lock**: use a new `tt_staff_mentorships` pivot — `(mentor_person_id, mentee_person_id, started_on, ended_on)` — because it's a mentor-of-individual relationship, not a mentor-of-team relationship.

```sql
CREATE TABLE tt_staff_mentorships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mentor_person_id BIGINT UNSIGNED NOT NULL,
    mentee_person_id BIGINT UNSIGNED NOT NULL,
    started_on DATE NOT NULL,
    ended_on DATE DEFAULT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    club_id INT UNSIGNED NOT NULL DEFAULT 1,
    KEY idx_mentor (mentor_person_id),
    KEY idx_mentee (mentee_person_id),
    KEY idx_active (ended_on)
);
```

### Capabilities

Three new caps, defined alongside the module:

- `tt_view_staff_development` — granted to academy_admin + tt_head_dev + tt_club_admin + (any user holding a `tt_people` row, scoped to self).
- `tt_manage_staff_development` — academy_admin + tt_head_dev + tt_club_admin (full edit). Also implicitly held by users with the Mentor functional role, scoped to their mentee(s).
- `tt_view_staff_certifications_expiry` — academy_admin + tt_head_dev (the people who chase expiries). Mentors see their mentees' expiries; staff see their own.

Auth-matrix entries seeded for the four new entities (`staff_goal`, `staff_evaluation`, `staff_certification`, `staff_pdp`) per persona.

### REST endpoints

Module-located under `src/Modules/StaffDevelopment/Rest/`. All gated by `permission_callback` using the AuthorizationService capability layer (no role-string compare).

```
GET    /staff/{person_id}/goals
POST   /staff/{person_id}/goals
PUT    /staff-goals/{id}
DELETE /staff-goals/{id}

GET    /staff/{person_id}/evaluations
POST   /staff/{person_id}/evaluations
PUT    /staff-evaluations/{id}
DELETE /staff-evaluations/{id}

GET    /staff/{person_id}/certifications
POST   /staff/{person_id}/certifications
PUT    /staff-certifications/{id}
DELETE /staff-certifications/{id}

GET    /staff/{person_id}/pdp
PUT    /staff/{person_id}/pdp           — upsert by (person_id, season_id)

GET    /staff/expiring-certifications   — used by the workflow template + admin overview
GET    /staff/{person_id}/mentorships
POST   /staff/{person_id}/mentorships   — admin-only; assigns a mentor
DELETE /staff-mentorships/{id}
```

### Frontend surfaces

**New tile group "Staff development"**, parallel to the player-side groups. Tiles:

- `My PDP` (every staff persona) — `?tt_view=my-staff-pdp`
- `My goals` — `?tt_view=my-staff-goals`
- `My evaluations` — `?tt_view=my-staff-evaluations`
- `My certifications` — `?tt_view=my-staff-certifications`
- `Staff overview` (HoD / academy_admin only) — `?tt_view=staff-overview`. Rolls up: who has open goals / overdue evaluations / certifications expiring in 90 days.

The four "My" tiles render a six-section dashboard layout matching the player profile rebuild from #0014 sprint 2 — hero strip with avatar + identity + role, then sections per primitive. Mobile-first at 360px base.

The HoD overview is a single-page view with three filter-able cards: *Open staff goals* (count + list), *Pending evaluations* (count + list), *Certifications expiring in 90 days* (count + list with traffic-light color).

### Workflow integration

Four templates registered with the engine (#0022) on module boot:

1. `staff_annual_self_eval` — cron `0 0 1 9 *` (Sept 1 at 00:00). Fans out one task per staff member with a non-archived `tt_people` row whose `role_type != 'unknown'`. 30-day deadline. Form: the staff evaluation form scoped to the user's own `person_id`.
2. `staff_top_down_review` — same cron + same fan-out, assigned to `tt_head_dev`. 60-day deadline. Form: the staff evaluation form scoped to one mentee per task.
3. `staff_certification_expiring` — daily cron at 06:00. Walks `tt_staff_certifications` for `expires_on` falling within the next 90 / 60 / 30 / 0 days; fires once per (cert, threshold) using a cache key to avoid duplicate firings. Assigned to the staff member; HoD CC'd via the existing notification channel.
4. `staff_pdp_season_review` — fires on the existing `tt_pdp_season_set_current` action (introduced in #0044's carryover). Fans out one task per staff member: "Review your PDP for the new season."

All four use the `chainSteps()` primitive added in #0022 Phase 2 if any post-completion action is needed. None are needed in v1; the field stays empty.

### Module wiring

```
src/Modules/StaffDevelopment/
├── StaffDevelopmentModule.php       — implements ModuleInterface, registers REST + templates + caps
├── Repositories/
│   ├── StaffGoalsRepository.php
│   ├── StaffEvaluationsRepository.php
│   ├── StaffCertificationsRepository.php
│   ├── StaffPdpRepository.php
│   └── StaffMentorshipsRepository.php
├── Rest/
│   ├── StaffGoalsRestController.php
│   ├── StaffEvaluationsRestController.php
│   ├── StaffCertificationsRestController.php
│   ├── StaffPdpRestController.php
│   └── StaffMentorshipsRestController.php
├── Frontend/
│   ├── FrontendMyStaffPdpView.php
│   ├── FrontendMyStaffGoalsView.php
│   ├── FrontendMyStaffEvaluationsView.php
│   ├── FrontendMyStaffCertificationsView.php
│   └── FrontendStaffOverviewView.php
├── Workflow/
│   ├── StaffAnnualSelfEvalTemplate.php
│   ├── StaffTopDownReviewTemplate.php
│   ├── StaffCertificationExpiringTemplate.php
│   └── StaffPdpSeasonReviewTemplate.php
└── Activator integration             — in src/Core/Activator.php (schema mirror)
```

Module registered in `config/modules.php`. Tiles registered via `CoreSurfaceRegistration` (the registry pattern landed in #0033-finalisation).

### Translations + docs

- NL `.po` updated in the same PR. ~50 new msgids estimated (UI labels + workflow template names).
- New doc `docs/staff-development.md` (EN) + `docs/nl_NL/staff-development.md` (NL), audience marker `<!-- audience: user -->` for the staff-side guidance, plus a separate admin-tier doc passage explaining the tile gating and workflow templates.

## Out of scope

- **Setup-wizard-for-new-staff.** Already covered by #0024 (setup wizard) and #0055 (record creation wizards).
- **Peer evaluations** (assistant evaluating head coach, etc.). v1 ships self + top-down only. Peer is intriguing but generates political conversations most academies aren't ready for; deferred until usage signal asks for it.
- **Staff radar charts.** The player module renders radar of evaluation categories; not in v1 for staff. Add after v1 if HoD asks.
- **Cross-club benchmarking.** Per-club only. SaaS migration (#0052) handles tenancy.
- **Importing existing staff evaluations from spreadsheets.** Manual entry for v1; CSV import is a future ask.
- **Anonymous evaluations.** The reviewer is recorded. Anonymous-survey mode is a different feature.
- **Document storage for certifications.** v1 stores a URL pointing to a document the admin uploaded elsewhere (Google Drive, OneDrive, file system). The plugin doesn't own the file.

## Acceptance criteria

The feature is done when:

- [ ] Migration `0036_staff_development.php` creates all six new tables + `tt_eval_categories.is_staff_tree` column on a fresh install. Idempotent on re-run.
- [ ] Activator schema mirror covers all six tables for fresh-install path.
- [ ] `cert_type` lookup seeded with the six standard certifications. Per-club editable.
- [ ] Staff eval-category tree seeded with five mains (Coaching craft / Communication / Methodology fluency / Mentorship / Reliability), `is_staff_tree=1`. Tree UI gates by the flag.
- [ ] New `Mentor` functional role available in `tt_functional_roles`. Admin can assign + unassign via the People page.
- [ ] Three new caps (`tt_view_staff_development` / `tt_manage_staff_development` / `tt_view_staff_certifications_expiry`) seeded with the role grants above. Auth matrix has the four new entity rows for every persona.
- [ ] REST endpoints exist at the paths above. All declare `permission_callback` against `AuthorizationService`. Coach-of-other-team can't read another staff member's PDP.
- [ ] "Staff development" tile group renders for the four staff-personas + the HoD overview tile for academy admins.
- [ ] Each "My" surface (PDP / goals / evaluations / certifications) renders at 360px width with no horizontal scroll, no hover-only interactions, all touch targets ≥ 48×48 CSS px.
- [ ] PDP form has the four fields (`strengths`, `development_areas`, `actions_next_quarter`, `narrative`). On save, upserts by `(person_id, season_id)`.
- [ ] Goal form supports the optional `cert_type_lookup_id` link — if a goal targets a certification, it surfaces on both the goals list and the certifications list.
- [ ] Evaluation form gates `review_kind` by who's looking: a staff member sees only `self`; HoD sees both options. Reviewer is auto-stamped from the WP user.
- [ ] Certification list shows all certs sorted by `expires_on` ascending; rows within 90 days of expiry get an amber pill, within 30 days red, expired grey.
- [ ] HoD overview surface shows three roll-up cards and links to each staff member's detail.
- [ ] Four workflow templates register with the engine on boot. The certification-expiring template uses the engine's existing dedup so a single cert doesn't fire the same threshold twice.
- [ ] When `tt_seasons.is_current` flips, the PDP season-review template fans out tasks (`tt_pdp_season_set_current` action subscribed).
- [ ] PHP lint clean, msgfmt validates the .po, docs-audience CI green.
- [ ] NL `.po` updated.
- [ ] `docs/staff-development.md` + Dutch counterpart shipped with audience markers.
- [ ] SEQUENCE.md updated with the v3.42.0 (or next-available) Done row.

## Notes

### Decisions locked during shaping (the eight from the idea + three extras)

1. **Goal cadence** — annual + optional mid-season check-in via the `?check_in=mid_season` URL param. Quarterly was rejected as too tight for staff careers.
2. **Reviewer model** — self + top-down for v1; peer deferred.
3. **Eval categories** — separate root in the existing `tt_eval_categories` table via an `is_staff_tree` flag. Reuses the tree UI. Five seeded mains, no subcategories on first seed.
4. **Certification model** — lookup-driven (`tt_lookups[lookup_type=cert_type]` + `tt_staff_certifications` referencing). Six seeded types.
5. **Workflow templates** — all four (annual self-eval, top-down review, certification expiry, PDP season review). Use `chainSteps()` if v2 needs follow-up tasks; not in v1.
6. **Mentor functional role** — admin-grant via the existing FR pattern, not auto-grant. New `tt_staff_mentorships` pivot table.
7. **PDP shape** — structured with three named fields (`strengths` / `development_areas` / `actions_next_quarter`) + an optional `narrative` for context.
8. **UI surface** — new "Staff development" tile group with four staff-persona tiles + a "Staff overview" HoD tile. Not absorbed into the People edit form.
9. **Sprint plan** — bundled in one PR, not split across v1 / v1.5 / v2. Compression pattern across recent epics says ~3-5h actual at this scope.
10. **Lookup naming** — `cert_type` and the staff eval tree (no separate `staff_value` lookup). Avoids confusion with #0044's `player_value` lookup type, which is a different concept (player virtues vs staff skills).
11. **Standards** — every new table gets `club_id` + (where root) `uuid` per CLAUDE.md § 3. Every new surface has a REST endpoint, not just PHP-rendered. Auth via capability checks. Mobile-first base CSS at 360px. NL `.po` and docs ship in the same PR.

### Player-centricity check

Per CLAUDE.md § 1, every feature must answer "which player(s) does this serve?". Staff development is one removed: it serves players *indirectly* by raising the quality of the people coaching them. The justification holds because the alternative (no staff development surface) means academies bolt on spreadsheets, which means staff development happens informally, which means the player-development pipeline runs hotter on individual heroics and cooler on systematic improvement.

The player-question this feature helps answer: *"Will the coach handling this player's U14 → U15 transition actually grow the way we need?"*

### SaaS-readiness check

- All six new tables carry `club_id INT UNSIGNED NOT NULL DEFAULT 1`. Repositories filter by `club_id` even though it's a no-op today.
- `tt_staff_pdp` is the only root entity here; it gets `uuid CHAR(36) UNIQUE`. Other tables are leaves and don't.
- All five frontend views compose data from repositories; no business logic in the view files. The REST controller and the PHP view both call the same domain layer.
- Auth uses `AuthorizationService` capability checks, not role-string compares.
- Document URLs on certifications are URLs, not server-relative paths. Object-storage migration is one config change away.
- Workflow scheduling rides on the existing engine (#0022), not ad-hoc `wp_cron` calls.

### Cross-references

- **#0008** — eval categories hierarchy. Staff tree branches off the same table via the new `is_staff_tree` flag.
- **#0014** — player profile + report generator. The "My PDP" / "My goals" / etc surfaces follow the same six-section dashboard pattern as Sprint 2's player profile rebuild.
- **#0022** — workflow & tasks engine. Four staff-side templates ride on the existing engine, including the chain primitive added in Phase 2.
- **#0024** — setup wizard. *Not* in scope here; this is personal-development-for-staff, not setup-for-new-staff.
- **#0033** — authorization matrix. Mentor functional role + four new entity rows seeded into the existing matrix.
- **#0044** — PDP cycle. Staff PDP shares the *concept* but not the schema — players have a richer cycle structure (multi-conversation, verdict, methodology links). Staff PDP is one row per person per season, with three structured fields. Different shape, different table.

### Estimated effort

~30-38h from the idea file (split across v1 / v1.5 / v2). Bundled with the recent compression pattern (5–14× across the last six epics): **~4-6h actual**. Ship as `v3.42.0` (or next available minor at PR-creation time).

### What ships in the PR

- Migration `0036_staff_development.php`.
- Six new repositories under `src/Modules/StaffDevelopment/Repositories/`.
- Five new REST controllers under `src/Modules/StaffDevelopment/Rest/`.
- Five new frontend views under `src/Modules/StaffDevelopment/Frontend/`.
- Four new workflow templates under `src/Modules/StaffDevelopment/Workflow/`.
- `StaffDevelopmentModule.php` wiring it all together.
- Activator schema mirror.
- Tile + capability + auth-matrix seeding via `CoreSurfaceRegistration`.
- `docs/staff-development.md` + `docs/nl_NL/staff-development.md` (audience markers + standards-quality rewrite).
- NL `.po` updates.
- `SEQUENCE.md` Done row + version bump.
