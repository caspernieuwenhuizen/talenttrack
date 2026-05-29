# TalentTrack v4.10.0 — Tournament wizard rework + post-creation Add-match (closes #975)

Reworks steps 2–5 of the `new-tournament` wizard end-to-end as a faithful port of `.local-mockups/tournament-wizard/index.html`, and adds a brand-new post-creation **Add match** surface so coaches can append matches to an already-created tournament without round-tripping through the REST API.

## Friction the redesign addresses

| # | Friction in v4.7.x baseline | Redesign response |
|---|---|---|
| 1 | Steps 2–5 shipped in raw `<input>` / `<select>` chrome — no cards, no typography hierarchy, no live affordances | Every step renders inside `.ttw-card` containers with an UPPERCASE card-title rhythm; field grid collapses to single column at 360px, 2 cols at 640px+ |
| 2 | Formation step was a bare radio group | Radio-card grid with hand-drawn dot glyphs of the formation shape (per #975 locked decision — not PitchSvg) |
| 3 | Squad picker used position TYPE chips (GK/DEF/MID/FWD) only | Specific position chips: GK · CB · LB · RB · DM · CM · AM · LW · RW · ST. Trial players visible with a `Trial` badge, unchecked by default |
| 4 | Substitution windows = comma text input, easy to fat-finger | Chip editor: Enter / comma adds, Backspace from empty pops, × removes. Live-validated against duration_min |
| 5 | Match-card headline didn't update until save | Headline live-updates from opponent / label inputs; falls back to "New match — fill in opponent below" in italic when empty |
| 6 | Review step was a flat `<dl>` with no edit affordance | Card per step with an Edit link top-right that jumps the wizard back to that step preserving state |
| 7 | No UI to add a match after creation — the `POST /tournaments/{id}/matches` REST endpoint existed but no frontend surface invoked it | New `?tt_view=tournament-match&action=new&tournament_id=N` surface with the same chip editor + an "Insert at position N" select. + Add match button lands on the planner detail page next to Edit |

## What ships

**CSS** — `assets/css/frontend-tournament-wizard.css`, mobile-first per CLAUDE.md §2. Scoped to `.tt-tournament-wizard` so the rules never leak. Base styles target 360px; 480px / 640px / 768px breakpoints scale up to the desktop card+grid layout in the mockup.

**JS** — `assets/js/components/tournament-wizard.js`, vanilla JS, no framework. Wires the formation radio-card .is-checked toggle, the squad search filter + position chips + Mark-all-present, the match-card chip editor + live headline + remove + clone-blank, the chip-editor max-value hint live-update from `duration_min`, and the Review step's Edit jump links (POSTs with `tt_wizard_jump_to=<slug>` so the framework lands the user directly on the named step).

**Wizard steps** — rewritten:

- `BasicsStep` — no Format select (the format is derived from the anchor team's age group server-side, per #975 locked decision). The card carries an inline hint reading "Format (7v7 / 9v9 / 11v11) is inferred from the team's age group."
- `FormationStep` — radio-card grid. Each card carries a 64×80 pitch glyph with hand-drawn dots distributed across rows parsed from the formation label ("2-3-1" → 2 defenders, 3 mids, 1 forward). The "No default" sentinel card sits first.
- `SquadStep` — toolbar (search + count + Mark-all-present) above a single-column list. Each row carries a checkbox, name, optional `Trial` badge, and a 10-chip strip for the specific positions. Trial players default unchecked; everyone else defaults checked on first visit.
- `MatchesStep` — one `.ttw-match-card` per match: sequence circle + headline + Remove. Field grid + chip editor for substitution windows. + Add another match dashed-tile clones a blank card and renumbers the list.
- `ReviewStep` — card per upstream step with an Edit link. Squad card includes a "Players" preview (first 8 names, "+M more" tail) and a "By position" breakdown counted off the specific position codes.

**REST controller** — `TournamentsRestController::normalisePositionsJson()` accepts the new specific codes (GK/CB/LB/RB/DM/CM/AM/LW/RW/ST). Legacy GK/DEF/MID/FWD payloads from v4.7.x and earlier are coerced (DEF → CB, MID → CM, FWD → ST) so existing `tt_tournament_squad` rows + any in-flight wizard state survive the bump. Auto-balance gains a `playerCoversBucket()` helper that maps specific codes back into formation-line buckets — a player with `CB/LB/RB` eligibility correctly matches a 'DEF' slot.

**New view** — `src/Modules/Tournaments/Frontend/FrontendTournamentMatchAddView.php`. Reachable at `?tt_view=tournament-match&action=new&tournament_id=N`. Reuses the wizard's card + chip-editor styles. Form POSTs to `admin-post.php?action=tt_tournament_match_add` (mirroring the #940 admin-post pattern for write surfaces). The handler validates cap (`tt_edit_tournaments`) + nonce + payload, optionally shifts downstream `sequence` values up by 1 when inserting mid-tournament, inserts the match, fires `tt_tournament_match_created`, and redirects to the planner detail view.

**Wizard framework** — `FrontendWizardView::handleAdminPostStep()`'s `back` action now honours `tt_wizard_jump_to=<step-slug>` when the POST carries it. Validated against the wizard's declared steps; unknown values fall back to the standard pop-history Back behaviour. Scoped change; no impact on existing wizards.

**Planner detail header** — gains the **+ Add match** primary action next to Edit.

## Backend untouched

Schema unchanged — same `tt_tournaments` / `tt_tournament_matches` / `tt_tournament_squad` / `tt_tournament_assignments` tables that landed in #0093. Existing REST endpoints (`POST /tournaments`, `POST /tournaments/{id}/matches`, etc.) keep the same shapes; the only behavioural change is the broader position-code vocabulary in `eligible_positions`. Existing `tt_view_tournaments` / `tt_edit_tournaments` cap gating is preserved across every new surface.

## Mobile-first

Every new selector targets 360px first; breakpoints scale up at 480px (squad row 3-col), 640px (field grid 2-col + formation card grid wider), 768px (form chrome widens to 880px). Every interactive target ≥ 48×48 — chip-editor input has `min-height: 48px`, position chips bumped to `min-height: 30px` (tap-target friendly without overpowering the visual rhythm), formation cards meet 48px via `min-height`. Numeric inputs carry `inputmode="numeric"`. No hover-only functionality; all chip toggles work via tap + keyboard (Enter / Space).

## Version bump

Minor — 4.9.x → **4.10.0**. New behaviour-changing UI work + new write surface counts as a feature epic per SemVer.

# TalentTrack v4.7.0 — Activities list date-bucket redesign (closes #973)

Rewrites the `?tt_view=activities` list surface end-to-end as a faithful port of the design-of-record mockup committed to `.local-mockups/activity-list/`. Backend is untouched — the same `tt_activities` rows, the same `tt_view_activities` capability gate, the same entry-point URL — but the visual contract goes from a generic `FrontendListTable` with two filters to a date-bucketed card list with a Type filter and a persistent past-toggle.

## Friction the redesign addresses

| # | Friction in v4.6.x baseline | Redesign response |
|---|---|---|
| 1 | Flat list scrolls past months of training; coaches scan dates to find "what's tomorrow" | Date buckets make temporal context instant. |
| 2 | No type filter — coaches looking for the next match scroll past trainings | Type picker in the filter row, lookup-backed via `QueryHelpers::get_lookups('activity_type')`. |
| 3 | Cancelled / completed past activities create scroll noise | Past pinned to top, collapsed by default, one-tap reveal. |
| 4 | Past PLANNED (never marked completed/cancelled) is a TODO signal that gets buried | "Needs attention" pseudo-bucket above Today; date badges painted `--tt-warn`. |

## Layout

- **Filter row** — Team picker beside the new Type picker, side-by-side 2-column grid at every viewport (the mockup keeps both on one row at 360px too). Both honour the existing `tt-input` 48px floor; the Type select is built from `tt_lookups` so renamed / added activity types appear without code changes.
- **Past toggle** — single button pinned above the bucket list. Label switches `N past activities hidden · Show ▼` ⇄ `N past activities shown · Hide ▼`. URL state `?include_past=1` persists across refresh / shared links. Chevron rotates 180° via CSS transform when expanded.
- **Buckets, top→bottom** (empty buckets collapse to nothing):
  - ⚠ **Needs attention** — `session_date < today AND plan_state = 'planned'`. Header rendered in `--tt-warn`; each row's 44px date badge painted the same orange.
  - **Today** — `session_date = today`. Header carries day-of-week + date (e.g. "Today · Wed 28 May"); badge in `--tt-accent` blue.
  - **This week** — `today < session_date <= upcoming Sunday`.
  - **Next week** — next Mon → next Sun.
  - **Later this month** — beyond next week, up to end-of-month.
  - **Later** — beyond end-of-month.
- **Activity cards** — `grid-template-columns: 44px 1fr auto`: date badge | title + meta line (type pill, optional status pill, team + time) | chevron. The whole card is a link to the activity detail page.

## Bucket math

"Today" comes from `current_time('Y-m-d', true)` so the GMT-stored value is converted via `wp_timezone()`. Week bucket boundaries are computed in PHP with `DateTimeImmutable` anchored to `wp_timezone()`: end-of-this-week = the upcoming Sunday (`'this week'`'s definition in PHP starts on Monday by ISO-8601), next-week range = `(end-of-this-week + 1 day)` through `(end-of-this-week + 7 days)`, end-of-this-month from `'last day of this month'`. Buckets sort their rows by `session_date ASC` so the next-upcoming row sits at the top of each.

## Type / status pills

Colour-coded per the mockup:

| Type/status | Background | Text |
|---|---|---|
| Training | `#e1eef5` | `#0d4a7a` |
| Match / Game | `#fde6e2` | `#8a2a26` |
| Friendly | `#fff3d9` | `#8a5e0a` |
| Other | `--tt-mute` | `--tt-ink-soft` |
| Status: Completed | `#e0efe5` | `--tt-success` |
| Status: Cancelled | `#ffe0e0` | `#8a2a26` |

Future-bucket rows show only the type pill — the bucket position already conveys "planned". Past-bucket rows additionally carry a Completed / Cancelled status pill.

## What's untouched

- **Schema** — `tt_activities` is unchanged. No migration.
- **REST** — `/talenttrack/v1/activities` and `/activities/{id}` keep the same shape; this view does NOT consume them. The view now reads `tt_activities` directly via a dedicated server-side query that mirrors `ActivitiesRestController::list_sessions`'s WHERE / scope rules (club_id, demo scope, head-coach team scope, archived filter, team filter). The REST endpoint remains the contract for non-WordPress consumers per CLAUDE.md §4.
- **Capability gate** — `tt_view_activities` continues to gate the surface. Cross-entity links from the dashboard widget, team detail, and the activity detail page keep their existing URLs (`?tt_view=activities`, `?tt_view=activities&id=N`).
- **Other modes of the view** — `?action=new`, `?action=edit`, and the read-only detail (`?id=N` without `action`) render the same forms / detail pages as before. Only the default list mode is rewritten.

## Files touched

- `src/Shared/Frontend/FrontendActivitiesManageView.php` — `renderList()` rewritten; new `bucketize()`, `renderBucket()`, `renderActivityCard()`, `renderPastToggle()`, `loadActivitiesForList()`, `typeKeyForPill()` helpers; the existing `render()`, `renderDetail()`, `renderForm()`, and attendance / guest helpers are untouched.
- `assets/css/frontend-activities-manage.css` — mockup tokens + selectors added (`.tt-act-list`, `.tt-act-filters`, `.tt-act-past-toggle`, `.tt-act-bucket-head`, `.tt-act-card`, `.tt-act-date`, `.tt-act-meta`, etc.). The legacy attendance-table rules (still used by the edit form) are preserved at the bottom of the file.
- `talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.6.0` → `4.7.0`.
- `docs/activities.md` + `docs/nl_NL/activities.md` updated for the new list shape.
- `languages/talenttrack.pot` + `languages/talenttrack-nl_NL.po` updated for the new strings (no duplicate msgids).

## Why minor

New feature epic. Surface behaviour visible to every coach changes (new filter, new bucket grouping, new past-pinned toggle), but no operator-breaking removal: the URL, the cap, the data model are unchanged.
