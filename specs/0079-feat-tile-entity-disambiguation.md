<!-- type: feat -->

# #0079 — Tile-entity disambiguation + FR scope sync: matrix becomes the actual answer

Sibling to **#0071** (auth matrix completeness). Where #0071 brought the seed and cap layer in line with the canonical matrix, this spec closes the last two gaps where the matrix is asked a question and gives the wrong answer: (a) tile visibility, dispatcher gating, and matrix grants must agree; (b) FR-assigned coaches must actually pass the matrix's team-scope check.

## Problem

### Problem 1 — three sources of truth disagree

Three sources of truth answer "should this user reach this surface?" with different logic, and they disagree.

A scout user lands on the dashboard and sees **Mijn teams**, **Mijn spelers**, and **Open wp-admin** tiles. Clicking **Mijn teams** dispatches to the coach-side teams view, which renders the notice *"Dit onderdeel is alleen beschikbaar voor coaches en beheerders."* The dispatcher correctly knows the scout doesn't belong, but the tile shouldn't have rendered in the first place.

Tracing the disagreement:

1. **Seed/matrix.** Scout has `team: r global`, `players: r global`, `frontend_admin: r global`. The first two are correct as data grants — scouts read teams and players globally to find prospects. The third is a seed bug — scouts have no business in wp-admin.
2. **TileRegistry.** The People-group tile **Mijn teams** declares `entity = 'team'`; v3.87.0 wired `MatrixGate::canAnyScope()` as the visibility check; the matrix grants `team:r` to scout, the tile renders.
3. **DashboardShortcode dispatcher** (`coaching_slugs` branch). Hardcoded `$is_coach || $is_admin` check at [src/Shared/Frontend/DashboardShortcode.php:241-247](../src/Shared/Frontend/DashboardShortcode.php#L241-L247) rejects scout with the friendly notice.

The architectural error: a single `team` entity is being asked to answer two different questions — *"can this user read team data?"* (yes for scout, who reads globally to scout) and *"should this user see the coach-side My teams tile?"* (no, scout doesn't have teams). One entity row in the matrix cannot answer both questions.

The same shape repeats across the People group (My teams, My players), the Performance group (Activities, Goals, Evaluations on the coach side vs. their consumption shape), and the Admin group (Open wp-admin gated by `frontend_admin` which the scout currently holds). The fact that the dispatcher backstop catches the leak means the bug ships as a UX wart rather than a data leak — but the operator looking at the matrix admin page cannot reason about what each row means.

### Problem 2 — FR-assigned coaches fail the matrix scope check

Head coach assigned to a team via Functional Role sees only the Methodology tile on the dashboard. Every team-scoped tile (My teams, My players, Activities, Evaluations, Goals, Podium, Team chemistry, PDP) is hidden because the matrix returns false for their scope check.

Tracing the failure: `MatrixGate::canAnyScope()` at [src/Modules/Authorization/MatrixGate.php:167-185](../src/Modules/Authorization/MatrixGate.php#L167-L185) checks `SCOPE_TEAM` by requiring (1) a `tt_people` row linked to the WP user, (2) a `tt_user_role_scopes` row with `scope_type='team'` for that person. The FR assignment path writes `tt_team_people` × `tt_functional_roles` but does *not* write the matching `tt_user_role_scopes` row. Result: matrix-active installs hide every team-scoped tile from FR-assigned coaches.

This is the same shape as the HoD bug fixed in v3.68.0 (#0069 added a `tt_head_dev` shortcut in `AuthorizationService::userHasPermission` for the same reason), but for head_coach / assistant_coach / team_manager / mentor / scout / physio / kit personas — every persona that arrives via FR. The Sprint 7 acceptance criterion of #0071 stated *"A person assigned as Head Coach via Functional Role gets the Head Coach persona's profile, scoped to that team — assignment auto-elevates within scope"*, but the auto-elevation isn't writing the scope row that the runtime gate needs.

Why bundle this with Problem 1: the new tile-specific entities introduced for Problem 1 (`team_roster_panel: r[team]`, etc.) go through the same `userHasAnyScope($user_id, 'team')` path. Without Problem 2 fixed, the new entities still fail for FR-assigned coaches and the spec's acceptance criteria can't be verified.

## Proposal

**One entity per tile destination.** Tiles that resolve to a coach-only or admin-only surface get a tile-specific entity that is distinct from the underlying data entity. The seed grants the new entities only to personas that legitimately reach those surfaces; the data entity stays as it is and continues to gate REST + repository reads.

**Dispatcher consults the matrix.** Hardcoded `is_coach || is_admin` style branches in `DashboardShortcode::dispatch*` get replaced with `MatrixGate::canAnyScope($user_id, $entityForViewSlug, 'read')`. The dispatcher reads the entity from the tile registry — the same entity the tile gate consulted — so they cannot disagree.

**FR assignment writes its scope row.** The FR assignment write path inserts (and the FR unassignment path removes) the matching `tt_user_role_scopes` row, so the matrix's scope check returns the answer the assignment intended. A one-time backfill migration covers existing FR assignments on installs that have the missing rows.

**Seed bug fix.** Scout loses `frontend_admin: r global`. Anyone else who has it but shouldn't — same treatment after audit.

End state per persona × tile becomes one matrix row that the operator can grant or revoke explicitly, and end state per FR assignment becomes one scope row that MatrixGate can find. The matrix admin page becomes legible: every tile that ships in `CoreSurfaceRegistration` has a corresponding entity row, every entity row has a "Used by:" backlink to the tile that consumes it, and FR assignments propagate to scope grants without operator intervention.

## Scope

### 1. Audit pass — which tiles need their own entity

Walk every tile registered in `CoreSurfaceRegistration::registerFrontendTiles()`. For each, classify:

- **Tile-specific already** — the tile's `entity` value is already a tile-shaped concept (e.g. `my_team`, `my_card`, `my_journey`, `my_evaluations`). No change.
- **Data-shaped** — the tile's `entity` is a data concept shared with REST controllers and repositories (e.g. `team`, `players`, `activities`, `evaluations`, `goals`, `frontend_admin`). Needs a sibling tile-specific entity.

Output: a list (committed in the PR description, not in source) of every tile with its current entity and the new tile-specific entity to declare. Expected count: 10–15 tiles, mostly the People group and the coach-side Performance group.

### 2. New seeded entities

Add tile-specific entity rows in `config/authorization_seed.php` for every tile flagged in step 1. Naming convention: `<surface>_panel` for tile-specific reads (e.g. `team_roster_panel`, `coach_player_list_panel`, `activities_panel`). One row per entity per persona that legitimately reaches the surface.

Indicative grants per persona (final list locked during the audit pass):

- `team_roster_panel` — assistant_coach, head_coach, team_manager: `r[team]`. head_of_development, academy_admin: `r[global]`. Scout, parent, player: no grant.
- `coach_player_list_panel` — same shape.
- `activities_panel`, `goals_panel`, `evaluations_panel` — coach personas + admin only. Scout has the underlying `activities:r[global]`, `goals:r[global]`, `evaluations:r[global]` data grants for scouting workflows but does not see the management tile.
- `wp_admin_portal` — academy_admin only. Replaces scout's spurious `frontend_admin` grant for tile gating; the underlying `frontend_admin` entity stays for any admin-tier matrix logic still consulting it.

### 3. Tile registration updates

In `CoreSurfaceRegistration::registerFrontendTiles()`, change `entity` to the new tile-specific value for every tile flagged in the audit. Drop the now-redundant `cap_callback` arguments (`$is_coach_or_admin_cb` and friends) since matrix-active installs ignore them and matrix-dormant installs are out of scope per #0071's `tt_authorization_active = 1` rollout.

### 4. Dispatcher refactor

In `DashboardShortcode::dispatch()`, the hardcoded role-class branches collapse to one shape:

```php
$entity = TileRegistry::entityForViewSlug( $view );
if ( $entity !== null && ! MatrixGate::canAnyScope( $user_id, $entity, 'read' ) ) {
    FrontendBackButton::render();
    echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this surface.', 'talenttrack' ) . '</p>';
    return;
}
self::dispatchByViewSlug( $view, $user_id, $is_admin );
```

Concretely: the `coaching_slugs`, `analytics_slugs`, `admin_slugs`, `me_slugs`, `workflow_slugs`, `dev_slugs`, `invitation_slugs`, `report_slugs`, `trial_slugs`, `staff_dev_slugs`, `wizard_slugs` branches each lose their per-class role check and gain the matrix call as the sole pre-dispatch gate. Per-view caps that the existing view classes re-check internally stay (defence in depth).

The `me_slugs` branch keeps the `$player` linked-record check separately — that is a data-prerequisite, not an authorisation question. Same for the analytics-tier `tt_view_reports` cap if it survives the matrix sweep; flag during implementation.

New helper `TileRegistry::entityForViewSlug( string $slug ): ?string` returns the tile's declared entity or null when no tile claims the slug.

The friendly notice copy ("You do not have access to this surface.") goes through `__()` for nl_NL translation. Per-branch variants (the existing "only available for coaches and administrators", "only available for users linked to a player record", etc.) collapse to one generic copy unless a UX reason emerges during implementation to keep them.

### 5. Seed correctness sweep

Drop `frontend_admin: r global` from the scout block in `config/authorization_seed.php`. Audit other personas for `frontend_admin` grants that shouldn't be there — assistant_coach, head_coach, team_manager all currently have it; verify each against the dispatcher's actual reach. Document the kept-or-dropped decision per persona inline in the seed.

### 6. Matrix admin page reverse-lookup

`MatrixEntityCatalog::callbackGatedTiles()` (the "Tiles not controlled by the matrix" list — added v3.86.0, emptied v3.88.0) keeps working unchanged because the new entities are still matrix-controlled. The matrix admin page automatically picks up the new entities via the existing seed walk; the "Used by:" backlinks resolve correctly because the tile registry maps the new entities to their tiles.

### 7. FR assignment writes scope row

Hook the FR assignment write path so that creating an assignment also writes the matching `tt_user_role_scopes` row, and removing an assignment removes it. Concretely:

- On `FunctionalRoleAssignmentService::assign()` (or the equivalent insert site for `tt_team_people` × `tt_functional_roles`): after the FR row lands, look up the assignee's `tt_people` ID and insert a row into `tt_user_role_scopes` with `person_id`, `scope_type='team'`, `scope_id=<team_id>`, and the assignment's date range copied from the FR row when present.
- On `FunctionalRoleAssignmentService::unassign()`: delete the matching scope row by `(person_id, scope_type='team', scope_id=<team_id>)`.
- Idempotent: repeated assigns/unassigns of the same `(person, team, role)` triple do not duplicate or orphan scope rows. Use `INSERT … ON DUPLICATE KEY UPDATE` semantics or a pre-check.
- Multi-team: a single person assigned head_coach on team A and assistant_coach on team B gets two scope rows, one per team. Removing the team-A assignment leaves the team-B scope row untouched.
- Multi-role-on-same-team: a single person assigned both head_coach and team_manager on the same team gets *one* scope row (the scope is per (person, team), not per (person, team, role)). The first assign creates it; the last unassign removes it; intermediate assigns/unassigns leave it.

Audit-log every scope-row write under the same `change_type` convention as existing FR audit entries, so an operator can trace why a user has a scope row.

The write path is the canonical site. `MatrixGate` does *not* learn about FR; it keeps reading from `tt_user_role_scopes` only. Single source of truth at read time stays.

### 8. Backfill migration

New migration `0062_fr_assignment_scope_backfill.php`:

```sql
INSERT INTO tt_user_role_scopes (person_id, scope_type, scope_id, start_date, end_date, club_id, created_at, updated_at)
SELECT DISTINCT tp.person_id, 'team', tp.team_id, tp.start_date, tp.end_date, tp.club_id, NOW(), NOW()
FROM tt_team_people tp
INNER JOIN tt_functional_roles fr ON fr.id = tp.functional_role_id
LEFT JOIN tt_user_role_scopes urs
       ON urs.person_id = tp.person_id
      AND urs.scope_type = 'team'
      AND urs.scope_id = tp.team_id
WHERE urs.id IS NULL
  AND fr.club_id = tp.club_id;
```

(Schema names checked at implementation time — the migration uses whatever column names the actual tables ship with.)

The migration is idempotent — re-running it inserts no rows because the LEFT JOIN finds the previously-inserted rows. Logs the inserted-row count for the operator. Wraps in a single transaction.

Verifies post-migration: every `(person_id, team_id)` pair present in `tt_team_people` × `tt_functional_roles` has a corresponding `tt_user_role_scopes` row. Test asserts this invariant.

## Wizard plan

**Exemption** — this spec touches the seed, tile registry, and dispatcher. No record-creation flow added or removed.

## Out of scope

- **Migration of existing customised matrix grants.** Per the user's instruction, existing-install drift is deferred until go-live. New entities default to seed values; admins who customised the data entity grants (`team`, `players`, `frontend_admin`) keep those rows as-is. If an install has, for example, granted `team:r[team]` to a custom persona, that grant stays on `team` and the matching `team_roster_panel:r[team]` is the seed default for that persona — the operator does not need to act. If the seed mismatches their intent, they edit the new row. A future migration can mirror grants if a customer asks.
- **Dispatcher gates outside the tile-driven view-slug router.** REST controllers and admin-page handlers retain their explicit cap checks. This spec collapses tile dispatch only.
- **Renaming or removing the data entities** (`team`, `players`, `activities`, `frontend_admin`). They keep their REST/repository roles. Only the tile/dispatcher layer moves to the new entities.
- **Per-team tile customisation.** Same out-of-scope clause as #0033 / #0071.
- **Persona-dashboard widget visibility.** Persona dashboards gate widgets on caps, not on tile-entity. Widget gating rework is its own follow-up if a leak surfaces.
- **Documentation rewrite of `docs/access-control.md`.** Touch the access-control doc with the one-line note that tile visibility is now per-tile via dedicated entities; the deeper rewrite waits until the audit settles.

## Acceptance criteria

- [ ] Every tile in `CoreSurfaceRegistration::registerFrontendTiles()` whose destination is gated by a role-class check in the dispatcher declares a tile-specific entity. The audit list is included in the PR description.
- [ ] Every new entity exists in `config/authorization_seed.php` with persona grants matching the destination dispatcher's intent.
- [ ] `LegacyCapMapper` knows about the new entities where they need cap bridges; entries follow the existing convention. Entities that are matrix-only (no cap mapping) are explicitly listed in a code comment.
- [ ] `DashboardShortcode::dispatch*` no longer contains hardcoded role-class branches (`$is_coach || $is_admin`, `$is_player`, etc.) for tile dispatch. The single matrix-driven gate is in place.
- [ ] `TileRegistry::entityForViewSlug()` exists and returns the tile's declared entity for known slugs, null otherwise.
- [ ] Scout user no longer sees Mijn teams, Mijn spelers, Activiteiten, Open wp-admin on the dashboard.
- [ ] Scout user clicking any of the previously-leaking surfaces directly via `?tt_view=` URL gets the matrix-driven 403 notice via the dispatcher's matrix check.
- [ ] Coach user (head_coach FR on a team) still sees Mijn teams + Mijn spelers, scoped to their assigned team. Lands on the coach view with no regressions.
- [ ] Academy admin still sees every admin tile including Open wp-admin and reaches every dispatch path.
- [ ] Matrix admin page lists every new entity with a "Used by:" backlink resolving to the consuming tile. The "Tiles not controlled by the matrix" list stays empty.
- [ ] `languages/talenttrack-nl_NL.po` gains the generic "You do not have access to this surface." string in nl_NL. Per-class notice strings that are no longer rendered are removed (or left as orphaned msgids — translator's call).
- [ ] One-line note in `docs/access-control.md` and `docs/nl_NL/access-control.md` records the per-tile-entity convention.
- [ ] No hardcoded role-name string compares (`$user->roles`, `in_array('tt_scout', …)`) added in this spec's diff. The matrix is the answer.
- [ ] Head coach assigned to one team via Functional Role sees the team-scoped coach tiles (My teams, My players, Activities, Evaluations, Goals, Podium, Team chemistry, PDP) on the dashboard. Lands on each without the dispatcher's "no access" notice.
- [ ] Assistant coach, team_manager, mentor, scout (where applicable), physio, kit (where applicable) — every FR-assigned persona — pass the matrix's team-scope check after FR assignment.
- [ ] Backfill migration `0062_fr_assignment_scope_backfill.php` runs on upgrade and inserts the missing `tt_user_role_scopes` rows for every existing FR assignment that lacks one. Idempotent on repeated runs.
- [ ] Future FR assignments via `FunctionalRoleAssignmentService::assign()` write the matching scope row; FR unassignments remove it. Multi-team and multi-role-on-same-team cases match the rules in Scope §7.
- [ ] Removing an FR assignment from a head coach hides the team-scoped tiles for that team on their next dashboard load. Removing the last assignment hides the persona's team-scoped tiles entirely.

## Notes

### Why distinct entities and not `hide_for_personas`

The `hide_for_personas` field on a tile, added in v3.68.0 / #0069, is the right tool when a tile is *visible-by-cap* but should hide for some personas as a UX preference (e.g. an admin tile that is reachable by an HoD on a small install but cluttering their day-to-day). It is the wrong tool when the issue is that the tile genuinely should not be visible — using it stacks an override on top of a wrong primary gate, and the matrix admin page still reads "scout can read team" for a row that semantically means "scout sees the coach-side My teams tile". Distinct entities make the matrix self-describing; `hide_for_personas` patches around the disagreement.

### Why the dispatcher refactor is in the same PR

Leaving the dispatcher's hardcoded checks in place after the tile gate moves to dedicated entities preserves the three-sources-of-truth situation in a different shape — tile says yes, dispatcher says no, with the matrix admin showing the tile-yes answer. The point of this work is to collapse to one source. A two-PR split (entities first, dispatcher second) would mean the second PR is the only one that actually closes the bug; better to do them together.

### Naming convention

`<surface>_panel` is a convention, not a hard rule. The intent is to read clearly in the matrix admin page — *"team_roster_panel"* should signal "this is the tile that lands on the coach's roster panel" without an operator needing to know the codebase. Where a clearer name exists in product vocabulary, use it. Avoid `_tile` as a suffix because the matrix already groups by tile registration, and the "Used by:" backlink already says "Used by: tile X".

### Why `wp_admin_portal` instead of dropping `frontend_admin` from scout

`frontend_admin` is consumed by more than the Open wp-admin tile — it gates several `tt_access_frontend_admin` cap checks across REST controllers and admin pages. Dropping the scout grant on it might silently revoke a path scout legitimately needs (the audit will surface this). Adding `wp_admin_portal` as a tile-specific entity for the Open wp-admin tile only is the surgical fix; the broader `frontend_admin` audit follows in this same PR but with the safer surface-by-surface review.

### Sequence position relative to PR #214 (matrix Excel/CSV round-trip)

Independent. PR #214 ships the operator's edit/import path for the matrix workbook; this spec ships new seed rows. They touch different files (#214 is `docs/authorization-matrix-extended.xlsx` round-trip plumbing; this is `config/authorization_seed.php`). Either can ship first. If #214 lands first, the new entities from this spec land in the workbook on the next round-trip and the operator sees them as "proposed, not yet seeded" until this spec ships.

### Why bundle FR scope sync with tile-entity disambiguation

The two halves are technically independent at the file level — different services, different tables, no overlap. They are bundled because:

1. **Verification depends on both.** The acceptance criterion *"head coach with FR assignment sees team-scoped tiles"* requires the new tile entities AND a working scope check. Splitting means the first PR can't fully verify; the gap is "fixed in the next release".
2. **Same architectural theme.** Both close gaps where the matrix is asked a question and gives a wrong answer for runtime reasons. Operators editing the matrix admin page need both fixes to trust what the page says.
3. **Demo install convergence.** The current pre-go-live install has the FR-assigned head coach (Kevin Raes) showing only Methodology. Same install will be the first place the new tile entities are tested. One ship resolves both.

The two halves stay logically separable inside the PR — distinct commits, distinct sections of the diff, distinct acceptance bullets. If the FR scope sync turns up a deeper issue during implementation that warrants its own ship, the spec can split — but the current evidence is that both fixes are surgical.

### Why MatrixGate doesn't learn about FR directly

Read-side derivation (walking `tt_team_people` × `tt_functional_roles` inside `MatrixGate`) is the alternative to the write-side sync chosen here. Trade-off:

- Read-side: no backfill, no write-path hook, but every gate call gets a more complex query and the scope table becomes "shadow data" — present but not authoritative.
- Write-side: one-time backfill + assignment-time hook, but `tt_user_role_scopes` stays the single source of truth at read time and the gate query is unchanged.

Picked write-side because Sprint 7 of #0071 explicitly stated this design intent (assignment auto-elevates within scope), and because the matrix-as-truth principle is precisely about not having shadow tables that the gate has to special-case. The read-side path is documented here in case the write-side hook turns out to be infeasible at the FR layer; it is the fallback.

### Estimate

~10–15h. Tile-entity audit + entity authoring 2-3h, tile registration updates 1h, dispatcher refactor 2-3h, seed correctness sweep 1h, FR write-path hook + tests 2-3h, backfill migration + tests 1-2h, tests + nl_NL + docs note 1-2h. Single PR. Ships as v3.90.0.
