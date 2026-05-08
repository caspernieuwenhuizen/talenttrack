# TalentTrack v3.110.18 — Activities module polish: present-% cap + wizard guest opt-in + connected-principles rename

Pilot polish round on the Activities module. Three items shipped:

## What landed

### Presence % capped at 100%

`ActivitiesRestController::format_row()` was returning attendance percentages above 100% in the activities-list `Att. %` column. The denominator is the active-status roster (`tt_players WHERE team_id = activity.team_id AND status = 'active'`); the numerator is every `Present` row in `tt_attendance` regardless of the player's current team. A player who moved teams between activity creation and attendance recording still has their attendance row counted, but their `tt_players.team_id` may have shifted, so `present_count > roster_size` in that edge case. Hard-clamped to 100. The pct calculation itself stays correct for the "real" case (denom > num); the clamp only kicks in on the team-move edge.

### "Add a guest after creating" opt-in on the new-activity wizard

The flat-form (`FrontendActivitiesManageView::renderForm`) has shipped a guest section on create AND edit since #0037 — the "+ Add guest" button auto-saves the activity, redirects to the edit URL with `&open_guest=1`, and re-opens the modal so the coach can pick a guest in one motion. The wizard, however, redirected to the activities list after submit, so coaches creating a friendly with a trial guest had to re-open the activity to add the guest.

Added a checkbox at the bottom of the wizard's Review step: **Add a guest player after creating (e.g. trial, friendly drop-in).** When checked, the post-submit redirect lands on `?tt_view=activities&id=N&action=edit&open_guest=1` so the existing #0037 guest-add modal pops open immediately. Default off — most activities don't have guests. State key: `continue_to_guests` (bool); validated in `ReviewStep::validate()`.

### "Principles practiced" → "Connected principles"

The methodology multiselect was labelled **Principles practiced** in three places (frontend activity edit form, wizard PrinciplesStep, ReviewStep DT label, admin ActivitiesPage). Renamed to **Connected principles** to match the user's vocabulary and to align with the goal-side **Linked principle** terminology (singular vs plural is fine — goals link to one, activities can connect to multiple). Step explanatory paragraph also rewritten: "Optionally connect this activity to one or more methodology principles. Hold Ctrl/Cmd to select multiple. Leave blank to skip."

Also surfaced the connected principles on the read-only activity detail page (`renderDetail()`) — was previously only visible on the edit form, so coaches landing on the detail view couldn't see what the activity was anchored to without clicking Edit. Defensive: skipped when the Methodology module isn't loaded.

## Affected files

- `src/Infrastructure/REST/ActivitiesRestController.php` — clamp `attendance_pct` to 100
- `src/Modules/Wizards/Activity/ReviewStep.php` — `continue_to_guests` checkbox + validate + post-submit redirect branch
- `src/Modules/Wizards/Activity/PrinciplesStep.php` — label rename + intro copy
- `src/Shared/Frontend/FrontendActivitiesManageView.php` — label rename + linked principles in `renderDetail()`
- `src/Modules/Activities/Admin/ActivitiesPage.php` — admin-form label rename
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version + ship metadata
- `languages/talenttrack-nl_NL.po` — 3 new translations
