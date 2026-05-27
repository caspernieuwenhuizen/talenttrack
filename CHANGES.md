# TalentTrack v4.3.20 — Activity guest UX defect bundle (closes #943)

Four cleanups on the activity edit form's "+ Add guest" surface (`?tt_view=activities&id=N`). All concentrated in the same handful of files, so they ship as one PR.

## (1) Adding a guest now visibly reflects on the form without manual reload

Coach adds a guest via the modal → modal closes → activity form shows the new guest row immediately.

**Fix**: `assets/js/components/guest-add.js` triggers a `window.location.reload()` on successful POST. Per the spec's recommendation — cheaper than maintaining client/server payload-shape parity, and guarantees the row is exactly what a fresh GET would produce.

## (2a) "Add as player" promote-to-player shortcut removed

Promoting a guest to a roster player is a broader process (trial → assess → assign team → permissions). Doesn't belong on a single activity row.

**Removed**:
- `FrontendActivitiesManageView.php` — the `$promote_url` block + the "Add as player" anchor on the anon-guest row.
- `guest-add.js` — the matching JS append on dynamically-inserted rows.
- `TT_GuestAdd.strings.promote` localisation key.

## (2b) Guests table columns sized 35ch Player / 10ch Status / rest Notes

Per CLAUDE.md mobile-first, uses `ch` widths via a `<colgroup>` on `data-tt-guest-table` + a `@media (min-width: 480px)` rule in `frontend-activities-manage.css`. Below 480px the existing `.tt-attendance-row` stacked layout via `data-label` kicks in, so the widths only matter on tablet / desktop.

## (3) "Evaluate" shortcut removed from linked-guest rows; guests surface in the rating wizard alongside roster players

**Removed**:
- `FrontendActivitiesManageView.php` — the `$eval_url` block + the "Evaluate" anchor on linked-guest rows.
- `guest-add.js` — the matching JS append.
- `TT_GuestAdd.strings.evaluate` localisation key.

**Wired** (per the shaping delta on #943):
- `RateActorsStep::ratablePlayersForActivity()` extended to include attendance rows where `is_guest = 1 AND guest_player_id IS NOT NULL`. The join switches from `att.player_id` to `COALESCE(att.guest_player_id, att.player_id)`; `DISTINCT` guards against a player appearing both as a roster row and a linked-guest row for the same activity.
- Anonymous guests (`guest_player_id IS NULL`) remain excluded — no `tt_players` row to evaluate against; their notes input on the activity form stays as the capture mechanism.

## (4) Remove-guest prompt uses the app's `<dialog>` modal

`window.confirm()` replaced with a `<dialog>`-backed app modal mirroring the v3.110.104 `frontend-archive-button.js` pattern.

- New `promptRemove()` + `ensureRemoveDialog()` helpers in `guest-add.js`; injected once per page, reused for every remove click.
- Strings localised via `TT_GuestAdd.strings.confirmRemove{,Title,Confirm,Cancel}` — the triple matches the archive-button shape.
- Falls back to `window.confirm()` only on runtimes without `HTMLDialogElement` (defensive — every supported browser has it).

`GuestAddModal.php` help text reworded to drop the promote reference (`No TalentTrack record needed. Fill in the basics; the guest is recorded against this activity only.`).

## Files touched

| File | Change |
|---|---|
| `src/Shared/Frontend/FrontendActivitiesManageView.php` | Notes-cell rebuilt: no Evaluate / Promote anchors; just notes input + Remove. `<colgroup>` added to the guest table. Localisation keys updated. |
| `src/Shared/Frontend/Components/GuestAddModal.php` | Anonymous-tab help text reworded. |
| `src/Modules/Wizards/Evaluation/RateActorsStep.php` | `ratablePlayersForActivity()` join uses `COALESCE(att.guest_player_id, att.player_id)`; `DISTINCT`. |
| `assets/js/components/guest-add.js` | Reload on successful POST. `appendGuestRow()` simplified (kept as a fallback; no more Evaluate / Promote anchors). `window.confirm` → `<dialog>` modal via new `promptRemove()` helper. |
| `assets/css/frontend-activities-manage.css` | Column widths for `.tt-guest-table`. |

## Why patch

UX refinements on an existing surface within the 4.3 minor. No schema change, no migration, no REST contract change.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.19` → `4.3.20`.
