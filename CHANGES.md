# TalentTrack v3.110.9 — Team Blueprint Phases 3 + 4: discussion thread + mobile drag-drop polish + parent share-link (#0068, closes epic)

Bundled ship of the two remaining phases of #0068 Team Blueprint. **Closes #0068.** Phases 1 + 2 shipped at v3.98.0 + v3.100.0; Phases 3 + 4 ship together. All 10 architectural decisions locked in `specs/0068-feat-team-blueprint-phases-3-4.md`.

## Phase 3 — per-blueprint discussion thread

`Threads\Adapters\BlueprintThreadAdapter` is the third registered thread type after `goal` (#0028) and `player` (#0085). Staff-only — read on `tt_view_team_chemistry`, post on `tt_manage_team_chemistry`.

`Threads\Subscribers\BlueprintSystemMessageSubscriber` listens to a new `tt_team_blueprint_status_changed` action emitted from `TeamDevelopmentRestController::set_blueprint_status()` and posts an `is_system=1` message on every status transition (draft → shared → locked + reopen).

`FrontendTeamBlueprintsView::renderEditor()` now branches on `?tab=comments`; the Comments tab delegates to `FrontendThreadView::render('blueprint', $id, $user_id)`.

## Phase 4 — share-link + mobile drag-drop polish

Migration `0078_team_blueprint_share_token_seed` adds an additive `VARCHAR(32)` column on `tt_team_blueprints`. `ensureShareTokenSeed()` lazily writes the row's own uuid on first share-link build. `rotateShareTokenSeed()` sets a fresh `wp_generate_password(16, false, false)` value; every prior URL fails immediately.

`BlueprintShareToken` builds HMAC-SHA256 over `(blueprint_id, uuid, share_token_seed)` keyed on `wp_salt('auth')`. Mirrors `ParentConfirmationController::tokenFor()`.

`?tt_view=team-blueprint-share&id=<uuid>&token=<hmac>` is the public read-only render. Switches the `tt_current_club_id` filter to the blueprint's club. Renders status pill + flavour pill + chemistry headline + `PitchSvg` + lineup table. 404 on token-fail.

Editor share buttons gated on `tt_manage_team_chemistry`. Mobile drag-drop fallback via `PointerEvents` — long-press 300ms → pickup → drag preview → drop on slot or roster, with `navigator.vibrate(50)` on pickup + drop.

## Translations

11 new NL msgids.

## Notes

No new caps; no cron; no license flips. Renumbered v3.109.8 → v3.110.9 across multiple rebases against parallel ship train v3.110.0-8. **Closes #0068.**
