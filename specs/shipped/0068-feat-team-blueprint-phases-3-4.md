---
id: 0068-phases-3-4
type: feat
status: ready
title: Team blueprint Phases 3 + 4 — per-blueprint discussion thread + mobile drag-drop polish + parent share-link
shipped_in: ~
---

# 0068 Phases 3 + 4 — discussion thread + mobile drag-drop polish + share-link

Closes the remaining two phases of the Team Blueprint epic. Phases 1 + 2 shipped at v3.98.0 + v3.100.0. Phases 3 + 4 ship bundled in one PR per the SEQUENCE.md note ("write one spec if shipping the two phases together").

## Decisions

All ten architecture forks resolved in the v3.109.7-window shaping conversation. Repeated here so future readers see the locked answer alongside its reasoning.

| # | Question | Decision |
|---|---|---|
| 1 | Discussion-thread visibility | **Staff-only**, mirroring `PlayerThreadAdapter`. Read on `tt_view_team_chemistry`; post on `tt_manage_team_chemistry`. Parents reaching the public share-link (Phase 4) never see the comments. |
| 2 | System-message scope | **Status transitions only** (`draft → shared → locked` + `Reopen`). Per-assignment swaps would be too noisy — coaches see those via the chemistry refresh. New `BlueprintSystemMessageSubscriber` mirroring `GoalSystemMessageSubscriber`. |
| 3 | Comments tab UX | **Tab on the editor** at `?tt_view=team-blueprints&id=N&tab=comments`, same shape as goals/players. Side-panel would crowd the pitch. |
| 4 | Long-press threshold | **300ms** (matches Notion's threshold; long enough to disambiguate from scroll, short enough to feel responsive). |
| 5 | Mobile drag-drop scope | **Pointer-event fallback + `navigator.vibrate(50)` on pickup + drop only.** Auto-scroll the canvas is fragile inside the constrained pitch; the operator can scroll the page. |
| 6 | Share-link route shape | **Frontend view** (`?tt_view=team-blueprint-share&id=<uuid>&token=<hmac>`). Same stack as the editor; brand-kit + i18n for free; no JSON-shape decisions. |
| 7 | Share-link content | **Pitch + lineup table + chemistry headline + status pill.** No comments tab (per Q1 lean), no editing UI. Status pill makes the share self-explanatory ("locked → final XI" vs "shared → current draft"). |
| 8 | Token expiry / revocation | **Stay valid until operator rotates.** Add a `share_token_seed` column to `tt_team_blueprints` (default = blueprint uuid; "Rotate share link" sets a new random seed). HMAC payload is `(blueprint_id, uuid, share_token_seed)`. Operators control validity rather than a wall-clock TTL. |
| 9 | Revoke-link cap | **`tt_manage_team_chemistry`** — same as locking. If you can lock the blueprint, you can rotate its share link. |
| 10 | Anti-enumeration | **Nothing extra.** UUID4 is already cryptographically random; HMAC is keyed on `wp_salt('auth') + share_token_seed`. Adding a `?seed=` parameter is paint over what's already strong. |

## Architecture overview

### Phase 3 — discussion thread

```
┌─────────────────────────────────────────────────────────────────┐
│  Editor — ?tt_view=team-blueprints&id=N                        │
│                                                                 │
│  [Lineup | Comments]                                           │
│   ▲          ▲                                                 │
│   default    new tab                                           │
└─────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼ delegates
                       ┌─────────────────────────────────────┐
                       │  FrontendThreadView::render(        │
                       │     'blueprint',                    │
                       │     $blueprint_id,                  │
                       │     $user_id                        │
                       │  )                                  │
                       └─────────────────────────────────────┘
                                    │
                                    ▼ resolves type via
                       ┌─────────────────────────────────────┐
                       │  ThreadTypeRegistry::register(      │
                       │     'blueprint',                    │
                       │     new BlueprintThreadAdapter()    │
                       │  )                                  │
                       └─────────────────────────────────────┘

Status transitions emit:
  - tt_team_blueprint_status_changed($id, $status, $user_id)
                                    │
                                    ▼ listened by
              BlueprintSystemMessageSubscriber → posts is_system=1
              "Status changed to: shared" message into the thread.
```

### Phase 4 — share-link

```
┌──────────────────────────────────────────────────────────────────┐
│  Editor (cap tt_manage_team_chemistry)                           │
│  [Open share link] [Rotate share link]                           │
└──────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼ on click "Open share link"
                ┌─────────────────────────────────────────┐
                │  ?tt_view=team-blueprint-share         │
                │   &id=<uuid>&token=<hmac>              │
                └─────────────────────────────────────────┘
                                    │
                                    ▼ token verified via
                ┌─────────────────────────────────────────┐
                │  BlueprintShareToken::verify(          │
                │     uuid, share_token_seed, token       │
                │  )                                      │
                └─────────────────────────────────────────┘
                                    │
                              ✓     │     ✗ → 404
                                    ▼
                ┌─────────────────────────────────────────┐
                │  Read-only render:                      │
                │   - status pill (draft/shared/locked)   │
                │   - chemistry headline N/100            │
                │   - PitchSvg (read-only)                │
                │   - lineup table (slot → player name)   │
                └─────────────────────────────────────────┘

"Rotate share link" cap-gates on tt_manage_team_chemistry, sets
share_token_seed to wp_generate_password(16, false, false). All
prior URLs become invalid.
```

### Schema — additive column on `tt_team_blueprints`

```sql
ALTER TABLE tt_team_blueprints
  ADD COLUMN share_token_seed VARCHAR(32) NOT NULL DEFAULT '';
```

Backfill: any row with empty `share_token_seed` lazily seeds with the row's `uuid` on first share-link generation. Avoids touching every existing blueprint at migration time + keeps the migration idempotent + cheap.

### Token signing

```php
final class BlueprintShareToken {
    public static function tokenFor( int $blueprint_id, string $uuid, string $seed ): string {
        $payload = $blueprint_id . '|' . $uuid . '|' . $seed;
        return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
    }

    public static function verify( int $blueprint_id, string $uuid, string $seed, string $token ): bool {
        return hash_equals( self::tokenFor( $blueprint_id, $uuid, $seed ), (string) $token );
    }
}
```

Mirrors `ParentConfirmationController::tokenFor()` from #0081 — same HMAC pattern, same `wp_salt('auth')` keying.

## Phase plan

| Phase | Scope | Estimate |
|---|---|---|
| 3 | `BlueprintThreadAdapter` (new) + `BlueprintSystemMessageSubscriber` (new) + `tt_team_blueprint_status_changed` action emitted from `set_blueprint_status()` REST endpoint + Comments tab branch on `FrontendTeamBlueprintsView::renderEditor()` (cap-gated, delegates to `FrontendThreadView::render('blueprint', $id, $user_id)`). | ~6-8h |
| 4 | Migration `0078_team_blueprint_share_token_seed` (additive `VARCHAR(32)` column). `BlueprintShareToken` helper class (HMAC sign + verify). `Repository\TeamBlueprintsRepository::ensureShareTokenSeed()` lazy-init on first share-link build + `rotateShareTokenSeed()`. New `?tt_view=team-blueprint-share&id=<uuid>&token=<hmac>` view rendering pitch + lineup + chemistry + status pill, no chrome. Editor gains "Open share link" + "Rotate share link" buttons (cap-gated). Pointer-event mobile drag-drop fallback in `frontend-team-blueprint.js` (long-press 300ms → pickup → drag preview → drop on slot/roster). `navigator.vibrate(50)` on pickup + drop where supported. | ~3-5h |
| | **Total** | **~9-13h** |

## Out of scope (deferred)

- **Auto-scroll the editor canvas during touch drag** — fragile inside the constrained pitch; the operator scrolls the page.
- **Wall-clock TTL on the share-link** — operator-driven rotation is simpler + gives predictable invalidation.
- **Public-facing comments on the share-link** — per Q1 lean, the share-link is a read-only render; if parent feedback becomes a feature, it ships as its own thread type with its own visibility rules.
- **@-mention autocomplete + per-mention workflow tasks on blueprint comments** — same posture as #0085 player notes; pure `is_system=0` posting in v1.
- **Notification fan-out preferences** — the existing `NotificationSubscriber` broadcasts to every staff `canRead` returns true for, same as goals + player notes. Per-thread mute deferred.
- **Embed-from-URL preview** for parents pasting the share-link into messaging apps — needs an OG-tags follow-up; out of scope here.

## Definition of done

A reviewer should be able to answer yes to all of:

- The editor has a Comments tab visible only to staff who hold `tt_view_team_chemistry`.
- Posting a comment writes to `tt_thread_messages` with `thread_type='blueprint'`.
- Marking the blueprint shared / locked / reopened auto-posts an `is_system=1` message into the thread.
- The "Open share link" button on a saved blueprint generates a URL of shape `?tt_view=team-blueprint-share&id=<uuid>&token=<hmac>` that loads in any browser without authentication.
- "Rotate share link" invalidates every prior share URL for that blueprint immediately.
- A parent on the share-link sees the pitch + lineup table + chemistry headline + status pill, never the comments tab.
- Touch users on iOS Safari + Android Chrome can long-press a roster chip, drag it onto a slot, and see the chip drop with a haptic tap.
- Cap `tt_view_team_chemistry` continues to gate the editor; cap `tt_manage_team_chemistry` continues to gate writes + share-link rotation.

## Open questions for the next session

None at architecture level. All ten Qs locked above.

## Trigger to start

User-confirmed in the v3.109.7-window shaping conversation. Ships now.
