# TalentTrack v3.110.13 — Goals module polish: searchable player picker + methodology-link wizard fixes

Pilot polish round on the Goals module. Two items shipped:

## What landed

### Searchable player picker on the new-goal form

Replaced `PlayerPickerComponent` (a long flat select of every player) with `PlayerSearchPickerComponent` configured `show_team_filter=true`. The new picker renders an "All teams" dropdown above a search input; selecting a team filters the player list while "All teams" (the default) shows every player in the user's context. Same shape as v3.110.11's new-evaluation form, applied to the goals form for consistency. The v3.110.3 player-profile preset path (`?player_id=N` from the empty Goals tab CTA → hide picker, render hidden input) keeps working.

### Methodology-link wizard step (LinkStep) — fixes + context-driven label + translations

Three layered fixes on the new-goal wizard's "Methodology link" step (`Modules\Wizards\Goal\LinkStep`):

- **Position dropdown was empty.** The position + value queries did `WHERE lookup_type = %s AND archived_at IS NULL AND club_id = %d`, but `tt_lookups` has NO `archived_at` column — the initial schema (migration 0001) didn't include it (lookups use HARD delete via the lookups admin), so MySQL threw "Unknown column 'archived_at'" and the entire query failed. The second-select rendered empty for every install since the wizard shipped. Same root cause was already documented + fixed in `BasicsStep` (team wizard's age-group dropdown); this is the matching fix for the goals wizard. Dropped the bogus `archived_at IS NULL` clause from both the `position` and the `value` cases.
- **Label was always "Pick the entity to link" regardless of chosen type.** Now context-driven: when the operator picks "Position", the second-select's label reads "Position"; for "Football action" it reads "Football action"; etc. New private helper `LinkStep::secondSelectLabel( $type )` reuses the same translatable strings as the first-select option labels in `self::types()` so there's no string duplication.
- **English text on Dutch installs.** Three methodology-step strings were wrapped in `__()` but missing from `nl_NL.po` — the step label "Methodology link", the explanatory paragraph "Optionally link this goal to a methodology entity. …", and the "(no entries configured for this type)" empty-state. Added all three. The other methodology strings (`Linked principle`, `Linked football action`, `Optional. Anchor this goal to …`, `— no link —`, `— pick one —`, the four type labels) were already translated.

## Affected files

- `src/Shared/Frontend/FrontendGoalsManageView.php` — switch to `PlayerSearchPickerComponent` with embedded team filter
- `src/Modules/Wizards/Goal/LinkStep.php` — drop bogus `archived_at` filter; context-driven `secondSelectLabel()` helper
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version + ship metadata
- `languages/talenttrack-nl_NL.po` — 3 new translations (methodology-link wizard step strings)
