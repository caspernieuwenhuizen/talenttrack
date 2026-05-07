# TalentTrack v3.110.11 — Evaluations module polish: detail page + Open + Delete + average column + rateable-activities + single picker with team filter

Pilot polish round on the Evaluations module (`?tt_view=evaluations`). Five items shipped:

## What landed

### Evaluation read-only detail page

`?tt_view=evaluations&id=N` now renders a real detail page. Previously the URL was unhandled and the user landed back on the list — meaning every link from the list went to the player / team / coach detail and there was no way to actually open the evaluation itself.

The new detail view shows:

- Eval header: date, player (linked), team (linked), coach (linked when a `tt_people` row exists). When the evaluation captures a game (`opponent` / `competition` / `game_result` / `home_away` / `minutes_played` set), those fields are rendered alongside.
- Ratings table grouped by main category, with subcategory ratings indented underneath. Pulls from `EvalRatingsRepository::getForEvaluation()` and uses `EvalCategoriesRepository::displayLabel()` for translated category labels. Subs whose parent main wasn't directly rated render at the top level so nothing gets dropped.
- Notes section (when `notes` is non-empty), rendered with `white-space: pre-wrap` so multi-line coach notes preserve their formatting.

Wraps in `.tt-record-detail` so the v3.108.3 generic detail-card chrome applies.

### Average column + Open + Delete on the evaluations list

The list table gained two columns and an action set:

- **Average** — correlated subquery `SELECT AVG(r.rating) FROM tt_eval_ratings r WHERE r.evaluation_id = e.id AND r.club_id = e.club_id`. The cell renders the value to one decimal place and links to the eval detail page; this is the operator's primary way to open the evaluation from the list.
- **Actions** — `Open` button (frontend link to the new detail page) + `Delete` button (uses the v3.108.2 `.tt-record-delete` generic handler with the existing `DELETE /evaluations/{id}` REST endpoint). Cap-gated on `tt_edit_evaluations`. Each row now carries `data-tt-row` so the JS row-fade-out works.

The previous list rendered Player / Team / Coach as `.tt-record-link` links to those entities — kept as-is so coaches can still drill into the related records, but the eval itself now has a clear way in.

### Recently-rateable activities now match the operator's mental model

Two bugs in the new-evaluation wizard's activity-picker step (`?tt_view=wizard&slug=new-evaluation`):

- **30-day window was too tight.** Pilot cadences (one match every 2-3 weeks) regularly missed the cutoff; the activity they completed last week wouldn't appear because the previous match was 32 days ago and shifted the window. Bumped the default to 90 days, which lines up with a typical season half-block. The class now exports a `DEFAULT_DAYS = 90` constant rather than open-coding the literal in two places.
- **No `plan_state = 'completed'` filter.** The query only filtered on date and team membership, so scheduled-but-not-played-yet activities also appeared in the picker. Filter added so only completed activities show — matching the operator's expectation that "rateable" means "the activity actually happened." Combined with the `plan_state = 'completed'` auto-transition that fires on attendance save (in `ActivitiesRestController::update_session`), marking an activity as completed via the frontend now correctly surfaces it in the picker.

The empty-state copy and the explanatory paragraph were both rewritten to spell out the rules: "Pick a completed activity from the last 90 days … only appear here once they are marked completed and their type is rateable."

### New-evaluation form: single picker with embedded team filter

The previous form had two stacked dropdowns: a `Team` picker that disabled the `Player` picker until a team was chosen, and the `Player` picker as a separate field below. That was friction for the common case of "I know the player by name, I don't care which team" — the user had to first remember the team in order to search the player.

Replaced with one `PlayerSearchPickerComponent` configured with `show_team_filter=true`. The component renders an `All teams` dropdown above the search input. Selecting a team filters the player list; selecting `All teams` (the default) shows every player in the user's context. The player search remains the primary affordance.

The separate `eval_team_id` form field is gone — the REST controller never read it (the team is always derived from the player record), it existed only to filter the picker, and the embedded team filter on the picker handles that natively. The `tt-eval-team-wrap` data attribute and the JS handler that bridged the team-select onto the picker via `tt-psp:set-team` are also gone — the picker hydrator handles its own team filter.

The "Recording evaluation for *Name*" preset path (added in v3.110.3 for the player-profile CTA) keeps working — when `?player_id=N` is in the URL, the form renders the headline and a single hidden `player_id` input instead of the picker.

## Affected files

- `src/Shared/Frontend/FrontendEvaluationsView.php` — `?id=N` handling + `renderDetail()` + Open/Delete/Average columns
- `src/Shared/Frontend/CoachForms.php` — single-picker form layout, drops `eval_team_id` + cross-filter JS
- `src/Modules/Wizards/Evaluation/ActivityPickerStep.php` — 90-day default + `plan_state = 'completed'` filter + clearer empty-state copy
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version + ship metadata
- `languages/talenttrack-nl_NL.po` — 3 new msgids (Average / Open / Actions column headers; eval-detail headings reuse existing strings)
