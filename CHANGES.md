# TalentTrack v3.97.1 — Player notes: staff-only running log on the player file (#0085)

Closes #0085 — the small-academy use case from the May 2026 pilot where coaches, scouts, HoD, and team managers want to leave structured observations on a player that aren't formal evaluations / scout reports / PDP cycles. Examples surfaced by the operator: *"Lucas was unusually quiet at practice tonight, parents are going through a divorce"* / *"Talked to Eve's parents at the tournament — they're considering a club switch in summer"* / *"Try Jonas as a winger next match, he's outgrowing the defensive midfielder role"*. None of those need a formal evaluation; all of them need to reach the rest of the leadership community.

Renumbered v3.95.2 → v3.96.1 → v3.97.1 after v3.96.0 (#0068 pair-chemistry follow-up) and v3.97.0 (#0081 onboarding-pipeline child 2a) landed in succession during the rebase window.

## Architectural lucky strike

TalentTrack already shipped the polymorphic Threads infrastructure (`tt_thread_messages` + `tt_thread_reads` + `ThreadTypeRegistry`) for #0028's goal-conversation feature. Until now only one thread type was registered (`goal`); this release registers a second (`player`) and wires it into the existing player-file Notes tab slot from #0082. The entire feature is one new adapter class, one new matrix entity, two new caps, one new tab branch on the existing player file, and one cascade hook on player soft-delete. No new tables, no new REST routes, no new frontend components.

## What landed

### `PlayerThreadAdapter`

New class at `src/Modules/Threads/Adapters/PlayerThreadAdapter.php` implementing `ThreadTypeAdapter`. Registered alongside `GoalThreadAdapter` from `ThreadsModule::boot()`:

```php
ThreadTypeRegistry::register( 'goal',   new GoalThreadAdapter() );
ThreadTypeRegistry::register( 'player', new PlayerThreadAdapter() );
```

`canRead`: requires `tt_view_player_notes` cap + matrix scope row resolved by `QueryHelpers::user_has_global_entity_read()` (HoD / admin / scout) or `coach_owns_player()` (team-scoped coaches). Players + parents are explicitly excluded by WP role check — they never read player notes via this adapter, full stop. The matrix seed has no grant for those personas anyway; the role-check is belt-and-braces against future seed-edit drift.

`canPost`: same shape with `tt_edit_player_notes`.

`participantUserIds`: returns the empty list. Per-user @-mention parsing is deferred to a follow-up (spec §5).

`entityLabel`: returns `"Notes — {player display name}"` for breadcrumb / page-title use.

### Matrix seed + caps

New entity `player_notes` seeded with documented persona scoping:

- `assistant_coach` / `head_coach` / `team_manager`: `r/c[team]`
- `scout`: `r/c[global]` — scouts observe across teams, so global scope.
- `head_of_development` / `academy_admin`: `r/c/d[global]`
- `player` / `parent`: no grant.

Three new caps in `LegacyCapMapper`:

```php
'tt_view_player_notes'   => [ 'player_notes', 'read' ],
'tt_edit_player_notes'   => [ 'player_notes', 'change' ],
'tt_manage_player_notes' => [ 'player_notes', 'create_delete' ],
```

`RolesService` gains a new `PLAYER_NOTES_CAPS` constant; the caps land on `tt_head_dev` / `tt_club_admin` (full RCD), `tt_coach` / `tt_scout` / `tt_staff` (view+edit). Administrator gets all three via the existing `ensureCapabilities()` walk.

### Migration `0069_authorization_seed_topup_player_notes`

Same shape as 0063 / 0064 / 0067. Walks the seed file and `INSERT IGNORE`s every (persona, entity, activity, scope_kind) tuple where `entity = 'player_notes'`. Existing rows including operator-edited ones stay untouched. Idempotent. Per `feedback_seed_changes_need_topup_migration.md`: the seed file alone doesn't reach existing installs because migration 0026 only seeds on fresh install / "Reset to defaults" click.

### Notes tab on the player file

`FrontendPlayerDetailView::tabs()` adds a `notes` key when `current_user_can('tt_view_player_notes')` resolves true. The dispatch switch routes to `renderNotesTab($player_id, $user_id)` which:

1. Re-checks the cap (defence in depth).
2. Re-checks the per-player scope via `PlayerThreadAdapter::canRead()` — covers the case where a coach has the cap globally but not for this specific player's team. Renders an `EmptyStateCard` with explainer when blocked.
3. Delegates to `FrontendThreadView::render('player', $player_id, $user_id)` — same component the goal threads use. Inherits the existing chrome: 5-minute edit window for own messages, soft-delete (own or admin via cap), mark-read on tab open, 30-second polling for new messages, mobile-first layout per `CLAUDE.md` § 2.

### Tab count badge

`PlayerFileCounts::for()` gains a `notes` count alongside the existing 5 (goals / evaluations / activities / pdp / trials). Counts only `deleted_at IS NULL` rows. Badge renders via the existing `tt-tab-badge` markup; zero-count tabs get the muted `tt-player-tab--empty` modifier from #0082.

### Player soft-delete cascade

`PlayersRestController::delete_player()` now writes `deleted_at = current_time('mysql')` and `deleted_by = get_current_user_id()` on every `tt_thread_messages` row where `thread_type='player' AND thread_id=N AND deleted_at IS NULL`, in the same transaction as the player archive. Notes are retained for compliance — they're soft-archived, not hard-deleted. The future GDPR erasure spec (split out of #0086 per the v3.95.1 lock) handles hard delete via the `PlayerDataMap` registry from #0081 child 1.

## What's deliberately NOT in this PR

Per the spec's "Out of scope" section + a tightening pass during shaping:

- **@-mention autocomplete + `PlayerNoteMentionTemplate` workflow tasks.** ~150 LOC across mention parser, autocomplete UI, task template, auto-completion via thread reads. Skipped to keep this PR small; ships as a follow-up if the operator asks for it.
- **Visibility dropdown (staff_only / internal).** Every note is staff-only by virtue of the adapter's read gate excluding players + parents. The "internal" sub-level for HoD-only sensitive notes adds complexity (needs a new visibility constant + per-message gate); operator's primary ask is just staff-only.
- **Search integration.** The cap gate handles this implicitly — note bodies appear in global search results only for users whose cap resolves true, which is the spec's behavior. Explicit search-index registration is unnecessary.
- **`docs/player-notes.md` operator guide.** Deferred. UI is self-explanatory; if the operator asks for a doc, ~30min add-on.
- **Parent / player participation in notes.** Explicit out-of-scope per spec — would force coaches into self-censorship from day one.
- **Rich text, file attachments, threading / replies, cross-academy notes, bulk import of historical notes.** All out-of-scope per spec.

## Affected files

- `src/Modules/Threads/Adapters/PlayerThreadAdapter.php` — new.
- `src/Modules/Threads/ThreadsModule.php` — register the adapter from `boot()`.
- `src/Modules/Authorization/LegacyCapMapper.php` — three new cap → entity bridges.
- `src/Modules/Authorization/Admin/MatrixEntityCatalog.php` — `player_notes` localized label.
- `src/Infrastructure/Security/RolesService.php` — `PLAYER_NOTES_CAPS` constant + grants on `tt_head_dev` / `tt_club_admin` / `tt_coach` / `tt_scout` / `tt_staff` + `ensureCapabilities()` walk.
- `src/Shared/Frontend/FrontendPlayerDetailView.php` — `notes` tab in `tabs()` + `renderNotesTab()` method + dispatch case.
- `src/Infrastructure/Query/PlayerFileCounts.php` — `notes` count in the returned map.
- `src/Infrastructure/REST/PlayersRestController.php` — cascade soft-archive of notes on player delete.
- `config/authorization_seed.php` — `player_notes` rows on 5 personas.
- `database/migrations/0069_authorization_seed_topup_player_notes.php` — new top-up migration.
- `languages/talenttrack-nl_NL.po` — 7 new msgids.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.

## Translations

7 new translatable strings: `Notes — %s`, `Player notes`, `Notes are staff-only`, the staff-only headline explainer, `Not in scope for this player`, the per-player scope explainer, and the page-top description on the Notes tab. `Notes` (used as the tab label) was already in the .po.
