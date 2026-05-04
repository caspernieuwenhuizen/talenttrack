# TalentTrack v3.92.0 — Tile bucket fixed; "Mijn POP" no longer leaks to coaches; "My settings" works for everyone; player file polish

Five fixes from the pilot install's same-day feedback. Quick-fix bundle while the bigger player-file UX redesign and breadcrumb sweep are shaped separately.

## (1) Tile bucket: PDP now lands in *Werk van vandaag → Development*, not Setup

`FrontendTileGrid::splitByKind` bucketed groups by *label name* (hardcoding `Development` and `Administration` as Setup). But two distinct groups carry the label `Development`:

- Player-development group registered in #0079 with the PDP + PDP-planning tiles (`kind=work`).
- Plugin idea-pipeline group with Submit-an-idea / Development-board / Approval-queue / Development-tracks tiles (`kind=setup`).

The label rule routed both to Setup, even though the player-development tiles declared `kind=work`. Coaches and head coaches saw the PDP tile under Setup → Development.

Now uses the tile's own `kind` field. A group is bucketed as `work` if any of its tiles is `kind=work`, otherwise `setup`. The idea-pipeline group label is also renamed to `Idea pipeline` (NL `Ideeënkanaal`) to disambiguate visually — same heading appearing twice in the same dashboard was confusing even after the bucketing was fixed.

## (2) "Mijn POP" tile no longer leaks to coaches / HoD / scout

The Me-group "My PDP" tile used the data entity `pdp_file` which coaches read at team scope and HoD/scout at global. Matrix-active installs granted them the tile via the data-entity grant — Casper (Trainer) saw "Mijn POP" under his "IK" group on the dashboard alongside the player surfaces.

Disambiguated via a new tile-visibility entity `my_pdp_panel`, only granted to `player` (`r[self]`) and `parent` (`r[player]`). Same disambiguation pattern as #0079's coach-side `*_panel` entities. The data entity `pdp_file` keeps its REST + repository role; only the Me-group tile gate moves to the new entity.

New migration `0064_authorization_seed_topup_my_pdp_panel.php` walks the seed and `INSERT IGNORE`s the new tuples (per the v3.91.1 precedent — see `feedback_seed_changes_need_topup_migration.md`).

## (3) Staff-development "My PDP" tile relabeled to "My staff PDP"

Two tiles labelled "My PDP" in the same dashboard (player-side + staff-development) made coaches read the staff one as the player surface. Renamed the staff-development tile label to `My staff PDP` (NL `Mijn staf-POP`); icon and entity unchanged.

## (4) "My settings" works for every logged-in persona

`my-settings` was in `$me_slugs` and dispatched through the player-record check. A coach clicking the user-menu dropdown got *"This section is only available for users linked to a player record."* My settings is account-level (display name, email, password) and applies to every logged-in user, not just users who happen to also be a player.

Routed to a separate `$account_slugs` branch with no player gate; works for every logged-in persona. `FrontendMySettingsView::render` signature relaxed to `?object $player = null` so the Me-view dispatch path keeps working when a player happens to land via that route. New `dispatchAccountView` method.

## (5) Player file polish (cosmetic; full redesign deferred)

The player file page (`?tt_view=players&id=N`) rendered the player's name **three times** — once in the breadcrumb crumb, once as the page `<h1>`, once as the hero `<h2>`. Dropped the redundant hero `<h2>` so the name appears in the breadcrumb + the page title.

Page title rewritten from just the player name to `Player file of {name}` (NL `Spelersdossier van {name}`) — the player's name is in the breadcrumb already; the title carries the contextual "this is a player file about ..." framing.

Profile tab field order rearranged. Was: age tier / date of birth / position / foot / jersey / status. Now: date of birth / position / foot / jersey / status / age tier. Date of birth and position are the most-asked facts on a player file; age tier is a derived convenience that lands last.

The bigger player-file UX redesign (visually appealing hero, empty-state CTAs across all tabs, "create the first goal/evaluation/activity from here" guidance) is the next thing to shape — out of scope for this PR.

## What is not in this PR

- **Player file UX redesign.** Empty-state CTAs in goal/evaluation/activity/PDP/trial tabs, hero card layout polish, "create your first ..." guidance. Will be its own spec.
- **Breadcrumb sweep across detail/manage views.** The breadcrumb component is great on the player file but not yet on Teams, Goals, Evaluations, Activities, Trials, etc. Mechanical follow-up; will ship as a single sweep PR.
- **Out-of-scope tile redirections.** The tile bucket fix means *every* group with a `kind=work` tile lands in Werk van vandaag now, including ones that were intentionally Setup-only via the label heuristic. Verified all currently-registered groups: only `Idea pipeline` (all `kind=setup`) and `Administration` (all `kind=setup`) bucket as Setup, which matches operator expectation.

## Affected files

- `src/Shared/Frontend/FrontendTileGrid.php` — `splitByKind` rewritten to use tile `kind`.
- `src/Shared/CoreSurfaceRegistration.php` — idea-pipeline group label renamed; Me-group "My PDP" tile uses `my_pdp_panel`; staff-dev tile label updated.
- `config/authorization_seed.php` — new `my_pdp_panel` entity for player + parent.
- `database/migrations/0064_authorization_seed_topup_my_pdp_panel.php` — new top-up migration.
- `src/Shared/Frontend/DashboardShortcode.php` — `my-settings` moved out of `$me_slugs` into `$account_slugs`; new `dispatchAccountView` method.
- `src/Shared/Frontend/FrontendMySettingsView.php` — `render` signature relaxed to `?object $player = null`.
- `src/Shared/Frontend/FrontendPlayerDetailView.php` — duplicate `<h2>` removed; page title rewritten; profile field order rearranged.
- `languages/talenttrack-nl_NL.po` — three new strings (`Idea pipeline`, `My staff PDP`, `Player file of %s`).
- `talenttrack.php` + `readme.txt` — version bump 3.91.6 → 3.92.0.
- `CHANGES.md` + `SEQUENCE.md` — release notes.
