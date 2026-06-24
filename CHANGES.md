# TalentTrack v4.49.1 — Players complete their profile when accepting an invite (#1819)

The player invitation-acceptance page now collects first name, last name,
date of birth, and preferred foot (alongside the existing jersey number),
written straight to the player record on accept. First and last name are
pre-filled from the invite so the player just confirms or corrects them.

# TalentTrack v4.49.1 — Players can't change their account display name (#1820)

Following the title-case "First Last" default, the display-name field on a
player's My settings page is now read-only — a player's name is owned by
the academy and set from their player record, so it can't be edited there
(enforced server-side as well).

# TalentTrack v4.49.1 — Player accounts: click a linked player to see which WP account it's linked to (#1823)

On the Player accounts page, a linked player's green chip is now a click-to-reveal disclosure: tapping it shows the actual WordPress account behind the link — email, username, and WP user id — so you can tell two accounts apart even when they share a display name. Read-only, inline, no wp-admin needed.

# TalentTrack v4.49.1 — Player accounts: compact rows for not-yet-connected players (#1824)

Rows for players without an account were much taller than connected rows because the link controls wrapped onto several lines. On tablet/desktop the account dropdown + Link + Invite buttons now sit on a single line, so an unconnected row is no taller than a connected one. Also fixes the "WordPress user to link" screen-reader label leaking visible under canvas mode (it relied on the theme's screen-reader-text class, which canvas isolation strips) by giving the plugin its own SR-only utility.

# TalentTrack v4.49.0 — Safe permanent delete for VCT exercises, custom widgets + injuries (#1784)

Extends the referential-integrity delete framework (#1783) to the last of
the rollout entities, plus a framework enhancement: cascade plans can now
**table-qualify** a reference column, so an ambiguous column name (e.g.
`exercise_id`, which keys both `tt_exercises` and the VCT tables) is scanned
on the right tables only.

- **VCT exercise** — cascades its coaching points; clears the exercise link
  on any session block. New `/vct/exercises/{id}/permanent` route.
- **Custom widget** — standalone; removed directly. New
  `/custom-widgets/{id}/permanent` route (uuid- or id-keyed).
- **Injury** — removes the injury and its journey-timeline events (a minor's
  medical record), so a right-to-erasure delete actually erases. New
  `/player-injuries/{id}/permanent` route.

All fail-closed, gated by `tt_edit_settings` (VCT: `can_admin`). No
migration. The `archived_by`-column migration + list-view delete
affordances for the full archive-lifecycle UI remain on #1784.

# TalentTrack v4.49.0 — Configurable dashboard tile colour scheme (#1809)

A new academy-wide **Tile colour scheme** setting recolours the dashboard tiles without changing their size or layout. Six schemes are available — Default, Brand border, Gold-topped (the new default), Soft green fill, Solid green and Left accent — and they draw entirely from the academy's brand colours, so they track your Primary/Secondary colour choices automatically. The setting sits alongside Tile size and Tile layout on the Appearance configuration surface and is stored under the `tile_style` configuration key.

# TalentTrack v4.49.0 — Team planner export buttons are now compact icon buttons (#1812)

The team planner's Export PDF / Export XLSX / Weekly PDF actions render as
icon buttons matching the height of the "Schedule activity" button, instead
of taller text buttons. On phones they collapse to icon-only circles like
the other page-header actions; each keeps an accessible label.

# TalentTrack v4.49.0 — My Journey: position changes read as a list, not raw JSON (#1818)

A "position changed" entry on a player's journey now reads e.g.
"Positie: geen → CB, LB" instead of showing the raw stored array
("[\"CB\",\"LB\"]"). New position-change events store the formatted value.

# TalentTrack v4.49.0 — Player accounts get a proper "First Last" display name (#1820)

When a player accepts their invitation, their account's display name now
defaults to their first and last name in title case (e.g. "Luuk
Nieuwenhuizen") taken from the player record, rather than an inconsistent
or lower-cased value.

# TalentTrack v4.48.2 — Security: parents can no longer open another family's child profile (#1725)

The player detail view only checked the coarse `tt_view_players` capability, never that the viewer was actually linked to *that* player — so a parent could open any child's profile by id and the "Parents · Guardians" card would expose every co-guardian's name, email, and phone (a safeguarding leak for minors). The view now enforces the canonical per-player scope (`AuthorizationService::canViewPlayer`: own record / global / player's team / parent-of-this-player), and the guardians card renders for staff only (admin/HoD or the team's coach) — never for a parent viewing their own child. Also fixes an adjacent bug where the activities REST endpoint queried `tt_player_parents` with a non-existent `wp_user_id` column (correct: `parent_user_id`), which had wrongly blocked parents from their own child's activities.

# TalentTrack v4.48.2 — PDP (and team-scoped surfaces) now visible to a player's head coach (#1758)

A head coach assigned to a team the legacy way could not see their own players' PDP files — the files tab was empty even though the coverage tab counted the PDP, while HoD/admin saw it fine. Cause: the legacy `head_coach_id` backfill (migration 0006) created the `tt_team_people` link but never the `tt_user_role_scopes` team grant that `get_teams_for_coach()` reads, so `coach_owns_player()` returned false. A new idempotent backfill (migration 0171) creates the missing team-scope grant for every team-people link, so legacy and modern assignments converge on the single matrix source of truth. Head coaches now see their team's PDPs (and every other team-scoped surface); HoD/admin visibility is unchanged.

# TalentTrack v4.48.2 — Safe permanent delete for holidays, test trainings + trial tracks (#1784)

Extends the referential-integrity delete framework (#1783) to three more
record types via new `/permanent` REST routes (gated by `tt_edit_settings`,
fail-closed). **Holidays** are removed directly; **test trainings** clear
any workflow-task link first; **custom trial tracks** block while a trial
case still uses them and built-in (seeded) tracks are refused. No migration.

The remaining archivable entities (custom widget, injury, VCT exercise) and
the list-view affordances stay tracked on #1784.

# TalentTrack v4.48.1 — CI gate: contain new inline styles (#1389)

A new **Inline-style containment** CI gate fails any pull request that
*adds* an inline `style="…"` attribute or a `<style>` block inside
`src/**/*.php`. The repo's large existing backlog is grandfathered — the
gate is diff-only, so it never trips on untouched code — but new inline
styling must now move into an enqueued stylesheet (reading the design
tokens, never raw hex), which is what keeps the spacing/colour drift from
reappearing (CLAUDE.md §2). For a genuinely dynamic value that can't live
in CSS (e.g. a computed progress-bar width), a trailing
`/* tt-inline-ok */` on the same line grandfathers it. The rule is now
documented in CLAUDE.md §2. No runtime change.

# TalentTrack v4.48.1 — Trial case page 2026 card layout + Save/Cancel on trial config forms (#1646)

The trial-case detail page now wraps each section in a token-styled 2026
card with cleaner headings, matching the teams and activity-detail surfaces;
the regenerate-letter form's inline margin moved into the enqueued sheet. The
trial-tracks editor and letter-template editor both gained a proper Cancel
button alongside Save (via the shared `FormSaveButton` helper, honouring any
`tt_back` hint), and the letter editor's monospace HTML textarea moved into a
CSS class. Visual and markup only — no data, query, or permission changes.

# TalentTrack v4.48.1 — Standardize report interfaces to the 2026 card/table/KPI pattern (#1760)

The standard-reports, report-detail and scheduled-reports surfaces now share
the same 2026 look as the attendance report: a KPI strip, card-wrapped tables
(`.tt-report-card` + `.tt-table`), and a consistent page head. The shared
primitives moved into the app-chrome sheet so every report surface inherits
one definition. No data or permission behaviour changed.

# TalentTrack v4.48.1 — Safe permanent delete for tournaments + trial cases (#1784)

Extends the referential-integrity delete framework (#1783) to two more
record types. Permanently deleting a **tournament** now cascades its
matches, squad and per-match assignments and clears a linked activity's
tournament reference; permanently deleting a **trial case** cascades its
staff assignments, staff inputs and extension history and clears any
workflow-task / prospect link. Both are fail-closed — they refuse and name
the dependents if anything undeclared still references them.

Adds the `/tournaments/{id}/permanent` (+ `/restore`) and
`/trial-cases/{id}/permanent` REST routes, and the Restore + Delete-
permanently row actions on the tournaments list. Gated by `tt_edit_settings`.
The remaining archivable entities (which need an `archived_by` column
migration) stay tracked on #1784.

# TalentTrack v4.48.1 — List filter bar: roomier controls + a search icon (#1803)

The search and filter controls on list views now have a comfortable left
text inset instead of hugging the border, and the search box shows a
magnifier icon. Both live in the shared list component, so every list
inherits them.

# TalentTrack v4.48.1 — Team planner: actions moved to the page header (#1804)

The team planner's "Schedule activity" button and the PDF / XLSX / weekly
export actions now sit in the page header, alongside the title, like the
players list — instead of crowding the filter bar. The filter toolbar now
holds only the team picker, the period selector, and the week navigation,
and the period dropdown is sized to match the team dropdown.

# TalentTrack v4.48.1 — Evaluation detail page uses the full width on desktop (#1806)

The evaluation detail page now spans the full content width on desktop,
matching the other pages, instead of rendering as a narrow centred card.
Mobile is unchanged.

# TalentTrack v4.48.0 — Referential-integrity-checked permanent delete (#1783)

Permanent delete is now fail-closed across the archive lifecycle. A new
declarative cascade framework (`CascadeRegistry` + `GenericCascadeDeleter`)
checks, before removing a record, what still references it — then cascades
the record's own children, clears references on rows that outlive it, or
refuses the delete with a message naming what still points at it. A
permanent delete can no longer silently orphan child rows.

Deleting an **evaluation** now also removes its category ratings and
evidence links; deleting a **goal** removes its links and conversation
thread and clears any spawned-goal task link. **Team** and **activity**
permanent-delete now **block** while anything still references them
(previously they deleted the row and stranded its children) — full cascades
for those two are tracked as a follow-up (#1784). Player / person / PDP
deletes are unchanged.

# TalentTrack v4.48.0 — Players list toolbar now matches the standard register card (#1791)

The players list filter/search bar now renders as the standard 2026
"register" card — white surface, soft shadow, comfortable padding, and
rounded, bordered controls — instead of the earlier soft-grey strip with
square-cornered inputs. The toolbar and the table read as two matching
cards, the same chrome every other list uses. The rounded-control fix is
in the shared list-table component, so any list that didn't already style
its own controls now gets rounded search/filter inputs too. Restyle only;
filtering, search, and sort behaviour are unchanged.

# TalentTrack v4.48.0 — Record-name links look the same regardless of the active theme (#1792)

Links to a record (player name, team name, and similar) no longer pick up
the surrounding theme's underline or link colour. The shared record-link
styling is now pinned so an aggressive theme `a` rule can't override it,
so the same install renders these links identically whatever theme is
active. Visual only — link targets and behaviour are unchanged.

# TalentTrack v4.48.0 — Activities list adopts the standard toolbar and full desktop width (#1793)

The activities list Team/Type filter bar now renders as the standard 2026
"register" card, matching the players list, and the list spans the full
content width on desktop instead of a narrow centred column. The period
quick-filter chips (All / This week / Next week / …) are unchanged and
still sit below the filter bar. Restyle only; filtering and the activity
buckets behave exactly as before.

# TalentTrack v4.48.0 — Permanently deleting an archived player no longer fails on PDP calendar links (#1794)

Permanently deleting an archived player who had a PDP with a scheduled
conversation failed with a server error and deleted nothing — the deletion
cascade tried to match PDP calendar links on a column that doesn't exist.
Calendar links are keyed by conversation, so the cascade now reaches them
through the conversation and PDP file, and the delete completes cleanly,
removing those links with the rest of the player's data. The cascade
remains all-or-nothing, so no partial deletes occur. Right-to-erasure of a
player with a full PDP history works again.

# TalentTrack v4.48.0 — Dashboard tile grid adopts the 2026 green/gold look (#1695)

The frontend dashboard renders through `FrontendTileGrid` (the tile
landing shown when no persona template takes over), which carried its own
flat, grey tile styling — it was missed by the earlier persona-landing
(#1769) and `TileGridStandard` (#1790) restyles. Its tiles now match the
2026 mockup: a green left-accent and 12px radius on each tile card, a gold
left-accent on the "Mijn werk" rail rows, green-deep section labels, and
ink/line/paper/muted design tokens throughout (with a green-tinted hover
shadow and brand-green focus rings). Everything reads from the shared
tokens, so the club-colour editor re-themes the dashboard too. Visual
only — no markup, query, or navigation change.

# TalentTrack v4.47.1 — Spond import no longer overwrites notes after the first import (#1774)

A Spond-imported activity's notes are now seeded from the event's description
on the first import only, then owned by TalentTrack — the same "set once, then
TalentTrack wins" model already used for the activity type. Previously every
hourly re-sync rewrote the notes from Spond's description, wiping any notes a
coach had added or edited in TalentTrack. Title, date, location, and the time
fields still follow Spond on every sync. Trade-off: a later edit to the
description in Spond no longer flows into an already-imported activity.

# TalentTrack v4.47.0 — Evaluations list now matches the player-file count when filtered to a player (#1755)

Opening the evaluations list filtered to a single player previously applied
coach team/author scoping, so a coach could see a non-zero "N evaluations"
badge on a player's file yet an empty or short list — evaluations authored by
another coach for a player on a team they don't coach were hidden. When the
list is filtered to one player and the viewer can open that player's file, it
now returns all of that player's non-archived evaluations (club-scoped),
matching the player-file badge count and the player-file Evaluations tab. The
unfiltered evaluations list keeps its coach team/author scoping; access is
gated on the same can-view-player check used to reach the file, so no players
become visible that weren't already.

# TalentTrack v4.47.0 — Team planner "Principles trained" bar: rebalanced label/bar/count (#1756)

The "Principles trained — last 8 weeks" coverage rows under the team planner
laid out poorly: cramped principle labels, an over-wide bar, and no room to
read the count. The row grid is rebalanced — the label column flexes wider
(and long labels wrap instead of truncating), the bar track is narrower at a
fixed width, and the activity count sits clearly to the right of the bar with
breathing space. CSS-only; selectors and markup unchanged.

# TalentTrack v4.47.0 — PDP planning grid follows the configured block count (#1759)

The PDP planning matrix used to derive its number of block columns from the
highest block sequence found across stored conversations, so a legacy or
seed conversation carrying block 4 made the grid show 4 columns even when the
season was configured for 2. The grid now follows the academy's configured
PDP block count for the season (`tt_pdp_blocks`); blocks beyond the configured
count are no longer drawn. When a season has no blocks configured, it falls
back to the previous data-derived behaviour so legacy even-divide installs are
unchanged.

# TalentTrack v4.47.0 — Academy admins can switch individual export tiles off (#1762)

Academy admins can now disable individual export tiles — for example to hide
the Audit log, the Full club-data backup, or Federation registration — from
the Modules management page, under the Export module. There's one toggle per
bulk export tile, all enabled by default, so nothing changes until one is
turned off. Disabling a tile both hides it from the Exports page (for everyone
in the academy, admins included) and rejects that export at the endpoint, so it
can't be run via a direct link either. Toggles are per-academy (club-scoped)
via FeatureRegistry and audit-logged; they only ever narrow access — a user
still needs the underlying capability to see an enabled tile.

# TalentTrack v4.47.0 — Archive a scouting visit from the UI (#1764)

The scouting-visit detail view now has an **Archive visit** action. The
archive (soft-delete) capability already existed in the REST API
(`DELETE /scouting-visits/{id}`) but nothing surfaced it, so a visit could
never be cleared from the list. The button is shown to the visit owner (or
a scope admin), confirms before firing, calls the existing endpoint with a
nonce, and returns the user to the scouting-visits list with a "Scouting
visit archived." notice. No new business logic — the REST route already
enforced the capability and row-ownership check; this only wires it into
the UI.

# TalentTrack v4.47.0 — Player accounts view — link/unlink a WP account to a player (#1771)

A new **Player accounts** view (`?tt_view=player-accounts`, academy/club
admin) lists every player with their account status — No account / Invited
/ Linked — and lets an admin directly **link** an existing WordPress user
to a player or **unlink** one, the primary account-mapping workflow.
Invitations stay the secondary self-service path (the Invite button reuses
the existing flow).

- Link is offered only for accounts not already bound to another player or
  a staff/parent record (no double-binding), and grants the player role.
- Unlink keeps the player record and removes the player role only when the
  account isn't linked elsewhere, so a coach-who-once-played keeps their
  access.
- Resource-oriented REST: `POST /players/{id}/account` (link) and
  `DELETE /players/{id}/account` (unlink), gated by `tt_manage_players`;
  the view and REST share one `PlayerAccountService` so a future
  non-WordPress front end gets the same answers.

Builds on the one-account-one-player DB guarantee from #1772, and supplies
that issue's app-layer "already linked" guard.

# TalentTrack v4.47.0 — Enforce one WP account per player (#1772)

`tt_players.wp_user_id` had no uniqueness guard and no cleanup when a WP
user was deleted, so two players could share an account and the
derived-player scope resolver could surface the wrong child's record — a
safeguarding risk for minors.

- New migration `0170` deduplicates any players sharing a `(club_id,
  wp_user_id)` (keeping the active, data-richest, newest row and
  **unlinking** — never deleting — the rest, with an audit-log entry per
  unlink), normalises "no account" from `0` to `NULL`, and adds a
  `UNIQUE (club_id, wp_user_id)` index.
- New `delete_user` cleanup nulls `tt_players` / `tt_people` account links
  and removes `tt_player_parents` rows for the deleted user, so a
  re-issued WP user id can't inherit someone else's record.
- The player/parent scope resolvers now order deterministically, and every
  write path stores `NULL` (not `0`) for an unlinked player.

No behaviour change for correctly-linked accounts; the link UI and an
app-layer "already linked" guard land with the Player accounts view (#1771).

# TalentTrack v4.46.5 — List toolbar restyled to the 2026 "register" look (#1753)

The filter/search toolbar above every list view adopts the 2026 register style (Option D): a white filter card with a soft shadow and rounded corners, and an uppercase micro-label above each control (Search, Team, Status, Sort…) matching the table header treatment. It's mobile-first — controls stack full-width at phone size and collapse to one inline register row at ≥768px, with 16px inputs (no iOS zoom) and 48px touch targets. Implemented once in the shared `FrontendListTable` + `frontend-admin.css`, so every list inherits it; the filter set stays per-list (each list still declares its own controls). Functionality is unchanged — search, filters, sort, no-JS apply, and the status line all behave as before. Stable `.tt-list-table-*` selectors preserved.

# TalentTrack v4.46.4 — Usage stats: see active users by name, not just role buckets (#1765)

The Application KPIs view gains an **Active users** panel listing the actual people active in the window — each with their role and last-seen time — below the existing role-bucket summary. Each name links through to that user's activity timeline. A new `UsageTracker::activeUsers()` method provides the data (role classification mirrors `activeByRole()`), keeping the query out of the view. The panel stays behind the same admin capability as the rest of the usage dashboard, so names never leak beyond admins. One new string ("Active users (%d days)"), Dutch added; the role-bucket table also now shows translated role labels instead of raw keys.

# TalentTrack v4.46.3 — Report cards regain the contextual back pill (#1761)

Opening a report from the Reports launcher now shows the contextual "← Back to Reports" pill, so you can return to where you came from in one tap. The destination report views already auto-render the pill from a `tt_back` URL hint, but the launcher tiles linked without one — so the pill never appeared. The launcher now stamps each tile link with the launcher page as its back-target via `BackLink::appendTo()`. Breadcrumb chain is unchanged (still ends at Dashboard); no third affordance is added (CLAUDE.md §5).

# TalentTrack v4.46.2 — App-chrome user chip: wider name box + roomier avatar circle (#1751, #1752)

Two small fixes to the signed-in user chip in the top-right app chrome. The display name no longer clips — its box widens from a 14-character cap to 20, with a touch more padding on the chip (#1751). And two-letter initials (e.g. "CN") now sit fully inside the avatar circle: it grows from 32px to 36px with a slightly smaller, properly centred glyph (#1752). CSS-only in `frontend-app-chrome.css`; selectors and the 48px touch target are unchanged.

# TalentTrack v4.46.1 — New-evaluation player picker: team-scoped dropdown instead of blank search (#1731)

The player-first new-evaluation wizard's Player step no longer hides every
player behind a type-to-search box. It now shows a team-scoped native
dropdown: pick a team, then choose the player from the list. A coach who
manages exactly one team lands with that team pre-selected and its players
already listed, so no typing is needed. The team filter repopulates the
player list on change, and Head of Development / Academy Admin keep an
"All teams" option for cross-team reach. The change is opt-in via a new
`style => 'dropdown'` arg on `PlayerSearchPickerComponent`; the ~6 other
surfaces that use the picker keep the existing search behaviour unchanged.

# TalentTrack v4.46.1 — Deep-rate step: collapsible category accordion with aligned stars (#1732)

The player-first new-evaluation Rating step is no longer a flat table of
stars with a Basic/Detailed toggle. Each main category is now a collapsible
block (collapsed by default) whose summary shows the category name, a
read-only star mirror, and the average word — so a coach can scan what's
rated without expanding anything. Expanding reveals the editable
category-level stars and the sub-skill rows; rating sub-skills still sets the
category to the rounded average of the non-zero subs, and the summary
reflects it live. The #1643 training default still surfaces the Mental
category first and opens it. All inline styles moved to a stylesheet; the
star column lines up across categories and sub-rows. Ratings submit and
restore exactly as before — no data-shape change.

# TalentTrack v4.46.1 — Dutch eval-category labels no longer leak English (#1733)

The New-evaluation rating screen (and anywhere eval categories render) leaked
English labels — "Tactical", "Physical", "Short pass", "Dribbling", "Offensive
positioning" — alongside the few that already showed Dutch. The category
vocabulary is seeded in `tt_eval_categories` and resolved through
`tt_translations`, but only a handful of Dutch rows existed, so the rest fell
back to the raw English label on nl_NL installs.

A new idempotent migration seeds the authoritative Dutch label for every
default eval-category and sub-skill straight into `tt_translations`, keyed by
the stable `category_key`. It only seeds a category whose label is still the
seeded English default, so an academy that renamed a category keeps its own
wording; re-running is a no-op. No `.po` or code change — `displayLabel()`
already prefers `tt_translations`.

# TalentTrack v4.45.25 — Spond import maps start → kickoff time and meet-up → presence time, and stops dropping the time of day (#1741)

Activities imported from Spond now keep their **time of day**. Previously the sync stored only the date and discarded the start time, so every imported activity came in time-less. The import now reads Spond's start/end timestamps — converting them from UTC to the site timezone (which also fixes a possible off-by-one calendar day for late-evening events) — and stores them as the activity's start/end time. For **match** types (game, tournament), the Spond start becomes the **kickoff time** and Spond's meet-up time (its "meet X minutes before start" setting, read from `meetupTimestamp` or `meetupPrior`) becomes the **presence time** ("Aanwezig", added in #1729) — both then print on the weekly planner PDF. Times are treated as schedule fields, so a re-sync overwrites them from Spond (consistent with title/date/location); a coach-changed activity type is still preserved. No schema change, no new strings.

# TalentTrack v4.45.24 — Weekly planner PDF: ISO week number in the badge instead of academy initials (#1730)

When no academy logo is configured, the Team Planner weekly PDF's top-left badge previously fell back to the academy/team initials (e.g. "J") — a meaningless orphan, since the week number already sits in the title. The badge now shows the ISO week number (digits only, e.g. "26") instead. A configured academy logo still wins and is shown unchanged. PDF-only cosmetic change; the now-unused `initials()` helper was removed.

# TalentTrack v4.45.23 — Match presence time + fix match start-time not printing in the weekly planner (#1729)

Match-type activities (game, tournament, and any operator-added match/friendly types) can now capture a **presence time** — the arrival/"be present by" time families act on — via a new optional field on the activity form, shown only for match types. It round-trips through the REST activities endpoint and prints in the Team Planner weekly plan PDF as `Present HH:MM` ahead of kickoff. This ship also fixes a latent bug: a match never printed any time in the weekly PDF. The activity form only ever writes `start_time` (kickoff_time stays null), but the weekly-PDF match branch read kickoff_time alone, so a match with a start time showed nothing — it now falls back to start_time and prints `Kickoff HH:MM`. New nullable `time_of_presence` column on `tt_activities` (migration 0168); the wp-admin activities form is unchanged (it captures no time fields, so a lone presence field there would be orphaned). New strings "Present %s" and "Presence time (optional)", Dutch added.

# TalentTrack v4.45.21 — Team planner restyled to the 2026 look (#1683)

The team-planner view body adopts the 2026 chrome: day cells become white cards with rounded corners and a soft shadow, the current day is marked by a gold "today" ring instead of the old blue outline, and activity cards pick up the brand green for their titles with a subtle lift on hover/focus. The "principles trained — last 8 weeks" coverage list is reworked from wrapped chips into a vertical list of proportional gold bars, each scaled against the most-trained principle in the window (the bar is hidden below 520px, leaving the chip + count). This is CSS plus a small markup tweak only — no data, query, or REST changes.

# TalentTrack v4.45.21 — Shared frontend app chrome: top bar + persona chip + KPI tile (#1690)

The global dashboard header — rendered once for every `?tt_view=` route by `DashboardShortcode::renderHeader()` — adopts the 2026 design: a dark-green top bar with a gold brand mark (the academy's initials when no logo is configured) and a **persona chip** showing the signed-in user's initials avatar, name, and resolved persona label (Head of Development, Coach, Speler, Ouder, …). The chip *is* the existing user-menu trigger, so no new navigation affordance is introduced (CLAUDE.md §5) and the dropdown, persona switcher, and docs drawer are untouched — the change is additive (nothing moved). A new `FrontendAppChrome` component (`src/Shared/Frontend/Components/FrontendAppChrome.php`) carries the chip, a brand-initials helper, and a reusable `kpiTile()` for views to call; styling is a new mobile-first `assets/css/frontend-app-chrome.css` reading the existing `--tt-primary` / `--tt-secondary` tokens (no new palette). Persona labels resolve through the SaaS-portable `PersonaResolver`, not role-string checks. Below 560px the chip collapses to the avatar alone. This is the foundation for the per-view visual-parity work (#1680); one new string ("Observer"), Dutch added.

# TalentTrack v4.28.0 — Pixel-faithful image-capture PDF for match-prep + team-sheet print (#1475)

The match-prep print sheet and the match-day team sheet now produce a PDF that visually matches the live page instead of a separately-styled DomPDF rebuild that drifted from what the coach laid out. Both surfaces open a clean, chrome-free print page in a new tab; an **Export as PDF (A4 landscape)** action there captures the visible page with html2canvas and assembles an A4-landscape PDF (jsPDF), scaled to width and split across multiple pages when the content overflows. The capture libraries are vendored locally under `assets/js/vendor/` and lazy-loaded only when the user clicks Export, so nothing extra weighs on the always-loaded front end. The browser's own **Print → Save as PDF** stays on the same page as a text-based fallback, and the server-side DomPDF team-sheet exporter remains registered as a fallback path. The print routes stay cookie-authenticated and capability-gated (`tt_edit_activities` for match prep, `tt_view_activities` for the team sheet). Trade-off accepted for fidelity: captured text in the image PDF is not selectable.

# TalentTrack v4.21.36 — Fix activities table typo that broke saving on fresh installs (#1511)

The plugin activator created the activities table as `tt_activitys` (a misspelling from the #0035 sessions→activities rename) while the entire codebase reads `tt_activities`. Installs that upgraded from `tt_sessions` were fine, but any install created fresh after #0035 got the wrong-named, half-built table and could not save activities ("De activiteit kon niet worden opgeslagen") — and the activities feature was broken throughout. The activator typo is fixed for new installs, and a new idempotent repair migration (0159) adopts an orphaned `tt_activitys` under the correct name and backfills the missing columns. It's a no-op on correctly-built installs.

# TalentTrack v4.21.33 — Group the Reports launcher by purpose (#1503)

The Reports launcher was a flat grid of a dozen tiles under a single "Pick a report." line. It now reads under five purpose-based sections — **Development & performance**, **Playing time**, **Recruitment**, **Staff & quality**, and **Season overview** — so the right report is easy to find. The existing scope filter is unchanged: academy-admin-only reports still hide for regular coaches, and a section with no visible tiles (e.g. Recruitment, Season overview for a coach) renders no header. No new reports, no data or query changes — purely how the existing tiles are laid out.

# TalentTrack v4.21.32 — Fix wizard 404 on subdirectory installs (#1491)

On a subdirectory WordPress install (e.g. `http://host/wordpress`), starting a wizard 404'd after the first step, with the subdirectory doubled in the URL (`/wordpress/wordpress/?tt_view=wizard&…`). `FrontendWizardView::wizardStepUrl()` built the wizard's step/return URL by passing the full `REQUEST_URI` path — which already includes the subdirectory — into `home_url()`, which prepends the subdirectory a second time. The same latent bug sat in `RecordLink::dashboardUrl()`'s last-resort fallback. Both now combine the canonical scheme+host with the request path (no re-prepended home path), mirroring the `currentDashboardUrl()` fix from #1455. Root and subdomain installs were unaffected and stay unchanged.

# TalentTrack v4.21.18 — Surface planned attendance on the activity page + match prep (#1453)

Planned attendance was already captured at activity creation (the roster step writes `record_type='expected'` rows) but never shown back. Two surfaces now read it:

- **Activity detail page** gains an **Expected attendance** panel listing the planned players (guests tagged) with the count, so a coach knows who to expect before the session. It shows nothing when the activity was saved with "Set attendance later".
- **Match prep — Availability step** now seeds its defaults from the planned roster instead of marking everyone Present: planned players default to Present, and team players the coach left out of the plan are pre-marked **Absent** with the reason "not in planned roster". Activities without a planned roster keep the all-Present default.

No new table — this reads the existing `tt_attendance` expected rows. A shared `ActivitiesRepository::plannedRosterForActivity()` backs both surfaces and a new read endpoint, `GET /wp-json/talenttrack/v1/activities/{id}/planned-attendance`, so a non-WordPress front end gets the same data.

# TalentTrack v4.21.17 — wp-admin menu: grouped headings for the modern menu (#1449)

When the legacy entity menus are off, the wp-admin TalentTrack submenu was a flat jumble of operator/utility pages. It now reads under separator headings: **Configuration** (Dashboard layouts, Custom widgets), **Data & demo** (Demo data, Demo data review, Seed review), **Help** (Help & Docs), **Advanced** (Impersonate user), and **Developer** (Module completeness, WP_DEBUG only). Dashboard and Account stay at the top. Each heading auto-hides when its group has no visible row (so module-disabled or cap-gated groups don't leave an orphan heading).

Two pages that registered their own raw `add_submenu_page` — Impersonate user and Module completeness — now register through `AdminMenuRegistry` like every other page, so they group, order, and gate consistently. Ordering is driven by a new `sort` weight on the registry, applied only in the modern menu; the legacy menu's layout is unchanged. (The earlier #1449 ship, v4.21.12, removed the stray Eval Type Categories item and translated "Demo data review".)

# TalentTrack v4.21.16 — PHPStan baseline loads via `includes`, CI actually analyses (#1437)

`phpstan.neon` declared the baseline under `parameters.baseline`, which PHPStan 1.12 rejects (`Unexpected item 'parameters › baseline'`). Because the release workflow runs PHPStan with `|| true`, the config error was swallowed and the job went green without analysing anything — static analysis had been a silent no-op gate. The baseline is now loaded the supported way, via a top-level `includes:` entry, so `vendor/bin/phpstan analyse` parses its config and runs. The grandfathered baseline (`phpstan-baseline.neon`) is still honoured. Making the job actually gate (dropping `|| true`) is a separate follow-up.

# TalentTrack v4.21.15 — Frontend Modules toggle (#1451)

Module enable/disable is now reachable from the frontend admin surface at `?tt_view=modules` (and a Modules tile under Configuration), not only `wp-admin/admin.php?page=tt-modules`. It's gated by a new `tt_manage_modules` capability (administrator + academy admin by default) and exposed over REST (`GET`/`POST /wp-json/talenttrack/v1/modules`) so a non-WordPress front end can read/toggle modules — per the SaaS-readiness principle. Disabling a module prompts a confirm + reload reminder. The wp-admin page stays as the power-user fallback.

# TalentTrack v4.21.14 — Data migration: export for moving data between installs (#1464, phase 1)

First phase of install-to-install migration. The Backups page gains a **Data migration** section: pick which data sets to include (players, teams, staff & roles, evaluations, activities & attendance, goals, lookups & configuration) and download a portable `.ttmig` archive (gzipped JSON, same envelope as a backup, stamped `kind: migration`). Export is read-only and data-only — WordPress users and media aren't included.

The import side (upload + entity/record selection + interactive conflict resolution + user mapping + ID remapping) lands in follow-up phases of #1464.

# TalentTrack v4.21.13 — Dashboard links self-heal off a stale/trashed page (#1462)

Internal dashboard links could point at a trashed page when `dashboard_page_id` config pointed at a page that was later trashed/deleted (e.g. a duplicate dashboard page). Both link resolvers (`RecordLink::dashboardUrl()` + `FrontendAccessControl::dashboardUrl()`) now only trust the configured page when it's published; otherwise they fall through — RecordLink rediscovers the live dashboard page and re-caches its id, FrontendAccessControl falls back to the front page. The setup wizard also now pins `dashboard_page_id` when it creates the dashboard page, so the link-builder and homepage can't drift.

# TalentTrack v4.21.12 — Admin menu cleanup: Dutch labels, no stray Eval Type Categories (#1449)

The wp-admin TalentTrack menu is tidier: **Eval Type Categories** is removed from the menu (it's a low-level evaluation setting — the page stays reachable by URL via a null parent), and the last English-leaking label, **"Demo data review"**, is now translated ("Demogegevens beoordelen"). The remaining items already had Dutch labels, so the menu now reads consistently in the site language.

# TalentTrack v4.21.11 — Dashboard page renders full-width on block themes (#1457)

The dashboard looked narrow because block themes constrain post content (e.g. theme.json `contentSize` ~645px) and the dashboard page held a bare `[talenttrack_dashboard]` shortcode. The setup wizard now creates the dashboard page with the shortcode wrapped in an `alignfull` group block, so it breaks out of the content constraint; the plugin CSS then caps it at 1600px on desktop (#1457's cap). Existing dashboard pages can be updated the same way (wrap the shortcode in a full-width group, or set the page to a full-width template).

# TalentTrack v4.21.10 — Wizards no longer 404 on subdirectory installs (#1455)

Pressing Next in any wizard (activity, team-blueprint, …) 404'd when WordPress is installed in a subdirectory: `WizardEntryPoint::currentDashboardUrl()` rebuilt the URL with `home_url($path)` where `$path` (from REQUEST_URI) already contained the subdir, doubling it (`/wordpress/wordpress/…`). It now combines the site's scheme+host with the request path, so the subdir appears once. Domain-root installs are unaffected.

# TalentTrack v4.21.9 — Dashboard uses desktop width (#1457)

The dashboard was capped at 1100px on every screen. It now widens from the 1024px breakpoint up (to `min(94vw, 1600px)`), so desktops use far more of the viewport while phone/tablet keep the comfortable reading width. If a block theme constrains page-content width below this, a full-width page template is the follow-up.

# TalentTrack v4.21.8 — Version moved into the dashboard header row (#1452)

The operator version indicator now sits in the dashboard header actions row, next to the help button, instead of a footer at the bottom of the page. Still operator-only.

# TalentTrack v4.21.7 — Running version shown on the dashboard (#1452)

Operators now see the running plugin version (`v<x.y.z>`) as a subtle footer at the bottom of the frontend dashboard, so they can confirm what's deployed without opening wp-admin. Gated to operators (`tt_edit_settings`) so player and parent dashboards stay clean.

# TalentTrack v4.21.6 — Installed-version stamp advances after auto-migration (#1448)

After a plugin update via PUC (which doesn't re-fire the activation hook), the kernel ran the migration runner on every request because `tt_installed_version` was only ever set on activation. The kernel now stamps the version once migrations apply cleanly (zero failures), so the runner stops re-firing post-update. A failed migration intentionally leaves the stamp behind so the SchemaStatus retry path still engages.

# TalentTrack v4.21.5 — Plugin boots on init, ending the textdomain notice (#1438)

The kernel now boots on the `init` hook (early priority) instead of `plugins_loaded`. Several modules translate strings (`__()`) during `boot()`; doing that before `init` tripped WP 6.7's `_load_textdomain_just_in_time` "called incorrectly" notice on every request. Booting on `init` means translations resolve cleanly. Module-registered `init` callbacks (default priority) still fire, REST routes, admin menus, and the frontend shortcode are unaffected — verified on a live install (0 notices, 174 REST routes, dashboard renders).

# TalentTrack v4.21.4 — Setup wizard creates the dashboard page and sets it as the homepage (#1441)

The setup wizard gains a dedicated **Dashboard page** step (now six steps). It creates a WordPress page holding the `[talenttrack_dashboard]` shortcode — reusing an existing one if present, never duplicating — and sets it as the site homepage (`show_on_front` / `page_on_front`), so signing in lands straight on the dashboard. The final **Go to dashboard** button now opens that frontend page rather than the wp-admin dashboard. The step can be skipped, and the homepage is changeable later under Settings → Reading.

# TalentTrack v4.21.3 — Lookup values ship translated in all 5 languages (#1442)

Seed lookup vocabularies now carry curated nl_NL / fr_FR / de_DE / es_ES display labels, so dropdowns and status badges render in the site language out of the box instead of falling back to English. A new `LookupTranslationSeeds` map covers the player/coach/parent-facing types — foot, age group (Senior), eval categories + types, activity types + statuses, competition types, game subtypes, goal statuses + priorities + approval decisions, attendance statuses, journey events, player values, behaviour ratings, potential bands, audience types, tournament formats, VCT theme statuses, and the generic certificate types. Migration 0151 seeds them into `tt_translations` with `INSERT IGNORE`, so existing operator edits and earlier backfills are preserved. Locale-invariant codes (age-group U-codes, position codes, UEFA grades) are intentionally left untranslated.

# TalentTrack v4.21.2 — All 17 canonical age groups seeded (#1439)

Installs seeded before the canonical age-group list grew only had 7 options (U8, U10, U12, U14, U16, U19, Senior). The odd-numbered groups (U7, U9, U11, U13, U15, U17, U18, U20, U21, U23) are now present. Migration 0150 tops up existing installs (idempotent, per club) and normalises the display order to age order; the Activator seeds the full set on fresh installs. Custom age groups are preserved.

# TalentTrack v4.21.1 — Setup wizard age-group dropdown shows the site language (#1440)

The setup wizard's "First team" age-group dropdown rendered the raw canonical English value (e.g. `Senior`) regardless of site language. It now uses `QueryHelpers::get_lookup_label_pairs()`, so the visible label honours the site language (e.g. `Senioren` on `nl_NL`) while the submitted value stays the canonical English name — no change to what's persisted for existing teams.

# TalentTrack v4.21.0 — Player motivational layer (#1385)

The player dashboard now feels *for* the player instead of just *about* them. All seven player/parent KPIs — previously permanent "—" stubs — are wired to real data and surfaced on the player landing as progress cards:

- **My rating trend** (rolling average + since-last-month delta), **My activities attended %** (rolling 4-week), **My evaluations received**, **My goals completed**, **My PDP conversations done**, and **My next milestone** (nearest-due goal).
- **My team podium position** is wired too but, per #1384, only appears when the academy has enabled the player-visible rank toggle — so the default landing never shows a permanent dash for it.

The **"A note from your coach"** card is now live: it surfaces the most recent of the player-facing evaluation feedback (#1386) or a comment on one of the player's goals, and hides itself when there's nothing new (no more permanent "No new notes" stub). A **My check-ins** tile anchors the weekly self-evaluation — the one place the academy asks something *of* the player.

KPI business logic lives in the repository layer (`EvaluationsRepository`, `GoalsRepository`, `PdpFilesRepository`, `TeamStatsService`, `ThreadMessagesRepository`); the per-player rating trend is additionally exposed at `GET /players/{id}/rating-trend`. No schema change.

Completes the player-login launch gate (#1384/#1385/#1386).

# TalentTrack v4.20.131 — Player rank is now opt-in, with a growth trend (#1384)

The player-visible "#N of M" team rank on **My team** is now **opt-in per academy** and **off by default**. By default a player sees a growth-framed **personal trend chip** instead: how their rolling rating moved since last month (up / down / level) and the skill category they're improving most. Academies that want the numeric standing can enable it under **Configuration → Rating scale → "Show each player their team rank"**, and it then shows alongside the trend. No other teammate's rank is ever exposed; staff surfaces are unchanged.

The trend is computed in `EvaluationsRepository::personalTrendForPlayer` (two adjacent rating windows + top-improving main category) and is also reachable at `GET /wp-json/talenttrack/v1/players/{id}/rating-trend`, gated per-player by `AuthorizationService::canViewPlayer`. No schema change.

Second slice of the player-login launch gate (#1384/#1385/#1386).

# TalentTrack v4.20.130 — Player-visible evaluation feedback (#1386)

Coaches can now add an optional **Feedback for the player** field when recording an evaluation — a growth-framed message shown to the player (and their parents) on their My evaluations screen, alongside the scores. It is deliberately separate from the existing **Notes** field, which stays staff-only and is never surfaced to player or parent personas. The field is available on both the evaluation wizard (per-player, with interruption-buffer support) and the flat evaluation form, and rides the existing player/parent read surface so no new capability grant is required. **Schema**: one forward-only migration (0156) — additive `player_feedback` column on `tt_evaluations`, no operator action required.

First slice of the player-login launch gate (#1384/#1385/#1386).

# TalentTrack v4.20.95 — Demo→production conversion, PDP archive/delete, pilot-feedback drains, auto-release pipeline

Cumulative release covering every ship since v4.20.51 (2026-06-04). Forty-four patches: two feature epics shipped in slices (demo→production conversion, PDP archive + hard delete), two pilot-feedback drains (2026-06-10 + 2026-06-11), the i18n stabilisation arc, and the release-pipeline automation that makes PUC auto-update on pilot sites work without manual tagging. **Schema changes**: 8 forward-only migrations (0144–0152) — additive columns + backfills, no operator action required on upgrade.

## Demo→production conversion (#1272, v4.20.60–.62 + .75)

Operators who seeded demo data and then started entering real records can now convert in place instead of reinstalling. Admin **Demo Review** page ships a read-only inventory of every demo-tagged row (v4.20.60), a per-batch convert form driven by `DemoConversionService` — promote (strip demo tags) or delete per entity batch (v4.20.61), a terminal lock-out state + audit-log entry once conversion runs (v4.20.62), and per-record overrides on top of the per-batch toggle for the rare row that turned real mid-demo (v4.20.75).

## PDP archive + hard delete (#1274, #1293, #1294, v4.20.63–.65 + .73–.74)

PDP files gain a full lifecycle end: soft archive (schema + repo + REST + cap, v4.20.63), player-archive cascade (v4.20.64), hard delete with a five-table cascade behind the new `tt_delete_pdp` cap (v4.20.65), inline Archive/Restore buttons + show-archived toggle on the PDP list (v4.20.73), and a typed-name destructive-confirm surface with pre-delete CSV export to `wp-content/uploads/tt-pdp-deletes/` (v4.20.74).

## Pilot-feedback drain 2026-06-10 (v4.20.79–.84)

- Player profile Activities tab sorts chronologically with the recent-25 window preserved (#1316).
- Attendance Status select no longer collapses to `Aa▾` on Dutch installs (#1311).
- Goal-intake print gains a 7-block picker — snapshot / doelen 1-3 / afsluiting / handtekeningen / reminder — with a Print-alles escape hatch; team batch shares one selection (#1313).
- **Head-coach persona bug**: coaches assigned via the Staff section landed on the assistant_coach dashboard because no write path ever set `tt_team_people.is_head_coach`. Fixed at both canonical insert sites + backfill migration 0149 (#1314), then the dead `tt_teams.head_coach_id` column was retired outright — all four read sites moved to the modern path, column dropped in migration 0150 (#1315).
- Activities cap checks route through `AuthorizationService::userCanOrMatrix` so Functional-Role-only operators see the same UI the REST API already allowed (#1319).

## Pilot-feedback drain 2026-06-11 (v4.20.85–.92)

- Blueprint assignment refs repair migration for the silently-failed 0129 dbDelta (#1331); save-as-blueprint loud-fail + redirect to editor (#1328); open-saved-blueprint into the chemistry board (#1325); Delete affordance on blueprint list + editor (#1329).
- Goal detail page gains a Print doelenintake action (#1332).
- **Match-day team-sheet PDF now mirrors match-prep** — the exporter reads `tt_match_prep_lineup` + availability instead of never-populated `tt_attendance` columns, match-prep saves write through to `tt_attendance.lineup_role`/`position_played` as a projection, and the match-prep toolbar gains a Print-team-sheet button (#1194).
- **Activities ↔ Tournaments link**: tournament-typed activities carry a `tournament_id` FK (migration 0152), detail view shows the linked tournament with a cap-gated planner deep-link, edit form gains a team-scoped picker; create-new CTA stays admin-only (#1324).

## i18n stabilisation arc (v4.20.72 + .77–.78 + .93 + gates)

The audit-4 translator bundle landed 672 Dutch msgstrs across 11 surface batches (#1279 + 10 siblings, v4.20.77), with a msgctxt hotfix for the demo/PDP `Promote` collision (v4.20.78). The weekly drift report + PR-time drift gate shipped as v4.20.72 (#1223). When `i18n-sync.yml` kept failing post-merge on duplicate-msgid landmines, the PR gate learned to surface msgmerge fatal errors + run msguniq (#1338), and the landmines were cleared — 7 Dutch-literal msgids converted to English, 29 Dutch→Dutch obsolete pairs purged (#1339, v4.20.93). v4.20.95 itself repairs two regressions from that arc (a stderr line interleaved into the .po by the #1339 sweep, and a duplicate `Tournament` msgid that raced the gate).

## Release pipeline — PUC auto-update fixed (#1376, #1318)

PUC on pilot sites checks the latest GitHub **release**, but releases required manual tag pushes and lagged main by dozens of versions — auto-update was structurally broken. New `auto-release.yml` publishes a release (tag created via the release API) on every version bump that lands on main; idempotent against existing releases; the manual tag path stays. Supporting fixes: the legacy-sessions CI gate stopped tripping on every rename-away migration (blanket migrations-dir exclude, #1318), and audit-1's phantom-entity/cap-without-entity CI harness shipped as v4.20.71 (#1191).

## Activities repository extraction (#1320, v4.20.91 + .94)

Option-B per-surface extraction under way: `listForPlayer` (player profile Activities tab) and `listRecentCompletedForPlayer` (hero popovers + status capture) moved into `ActivitiesRepository`; remaining slices tracked on the issue.

## Other

New-activity wizard gains an AttendanceRosterStep with guest disclosure (#1297, v4.20.76). Team planner redirect snaps to the saved activity's week (#1271). Player profile date helpers guard the zero-date sentinel (#1281). `preferred_foot` lookup-type slug consolidation across six callsites (#1278). Audit-11 player-picker pattern coverage doc (#1296).

---

# TalentTrack v4.20.51 — Architectural audit drain, REST security hardening, scope-filter consistency

Cumulative release covering every ship since v4.20.21 (2026-06-03). Thirty patches across the **architectural-audit drain** (10 audits filed, ~47 follow-up issues, 28 fixes shipped) plus four follow-ups to the v4.20.21 pilot-feedback batch. No new feature epics — this release is consolidation: cross-cutting bug families surfaced by the audits and the REST-security class flagged by audit 2. **No operator-breaking changes** — no schema migrations, no capability matrix mutations, no API contract changes.

## Architectural audit infrastructure (#1175 - #1184)

Ten audits filed against the v4.20.21 codebase, each producing a `docs/audits/2026-06-audit-N-<slug>.md` findings doc and a slate of `ready-for-dev` follow-up issues. Audit numbering:

1. Authorization matrix entity catalogue completeness (#1175)
2. REST controller cross-club rewrite class (#1176) — flagged 5 critical CVEs
3. Standard reports scope-filter parity (#1177)
4. i18n hardcoded English literals (#1178)
5. Wizard reactivity (#1179)
6. Persona-dashboard KPI deep-link parity (#1180)
7. Entity scope-filter consistency across reads (#1181) — 7 follow-ups
8. Cross-entity picker privacy (#1182)
9. Form save/cancel + redirect-shape polish (#1183)
10. Documentation surface drift (#1184)

The findings docs ship in `docs/audits/` for future reference. The audit drain ran autonomously overnight with a cron-triggered queue executor.

## Audit 2 — REST security: cross-club rewrite class closed

Five REST controllers accepted attacker-controllable `player_id` / `team_id` / `tournament_id` without scope checks. Single-tenant pilot blunts impact today (`CurrentClub::id()` resolves to 1), but the SaaS-readiness contract (CLAUDE.md §4) requires these closed pre-emptively:

- **#1197 / v4.20.37** — `EvaluationsRestController::update_eval` + `delete_eval` skipped `club_id` in WHERE; `update_eval` never re-ran the `coach_owns_player` gate that `create_eval` enforces. A coach in club A who knew an eval id from club B could rewrite or soft-archive it.
- **#1198 / v4.20.38** — `GoalsRestController::create_goal` accepted any `player_id`; no club lookup, no coach roster gate for non-admins.
- **#1199 / v4.20.39** — `TournamentsRestController::update_assignments` inserted `tt_tournament_assignments` rows with `player_id` straight from the payload; off-squad player_ids now silently drop.
- **#1200 / v4.20.40** — `TeamsRestController::add_player_to_team` accepted any `team_id` from the URL path; cross-club reassign now 404s with `team_not_found`.
- **#1201 / v4.20.41** — `FrontendTrialsManageView::handlePost` accepted `player_id` from POST without club validation; trial cascade now starts from a verified-in-club player_id.

Each fix adds `QueryHelpers::get_*` lookup (which is club-scoped) before mutating; non-admin writers get the existing `coach_owns_player` gate. Error responses (403 `forbidden_player`, 404 `team_not_found`) stay backwards-compatible with create-side shapes so the JS handler doesn't need updates.

## Audit 7 — Entity scope-filter consistency across reads (8 fixes)

Eight reads across coach, admin, and parent surfaces silently mixed archived rows, guest call-ups, and (post #788 ship 2) planned-vs-actual attendance into operational queries. The canonical reference is `TeamRosterTableWidget.php:229-243` — every other read now mirrors that scope:

- **#1222 / v4.20.44** — 4 `tt_activities` reads (KPI snapshot exporter, season-summary KPI strip, per-team match_count column, PDP activities-timeline) add `archived_at IS NULL`.
- **#1224 / v4.20.45** — `CommsScheduledCron` attendance-flag + goal-nudge detection get `att.is_guest = 0`, `att.record_type = 'actual'`, `a.archived_at IS NULL`, plus cross-tenant `pl.club_id = ...` join condition.
- **#1225 / v4.20.46** — `PlayerDashboardView` tabs (evals, goals, attendance) add `archived_at IS NULL` filters; attendance adds `record_type = 'actual'`.
- **#1226 / v4.20.47** — `PeopleRepository::list()` default-hides archived rows; mirrors `PeopleRestController::list_people`. Fixes the parent-link picker and functional-roles surface offering archived parents.
- **#1227 / v4.20.48** — 3 `tt_attendance` reads (player profile KPI tile, activity edit form's per-player attendance map, admin Activities page roster) add `record_type = 'actual'` for stability through #788 ship 2.
- **#1228 / v4.20.49** — `FrontendPdpManageView::renderActivitiesTimeline` adds `is_guest = 0` + `record_type = 'actual'`.
- **#1230 / v4.20.50** — `Wizards\TeamBlueprint\SetupStep` team picker adds `archived_at IS NULL`.
- **#1232 / v4.20.51** — `ReportsPage::runLegacy` "Top 10 players" fallback adds `pl.archived_at IS NULL`; `FrontendComparisonView` misleading pre-#0038 comment rewritten.

Per-helper `club_id` WHERE clauses were deliberately NOT added across this slice. Per #1188 below, tenancy is enforced at the request layer in SaaS, not by individual repository helpers.

## Audit 6 — Persona-dashboard KPI deep-link parity (6 fixes)

#1207 surfaced the foundation bug: `KpiCardWidget` never honoured `linkUrl()` overrides — every per-KPI deep-link fix landed since v3.50.x silently no-op'd in the dominant placement. Fix routes through a new `AbstractWidget::kpiHrefFor()` helper that prefers `KpiDataSource::linkUrl()` over `linkView()`. The five downstream fixes (#1209-#1213) re-enable filter parity between dashboard tiles and their destination views:

- **#1207 / v4.20.22** — `KpiCardWidget::kpiHrefFor()` helper introduced; 11 KPIs migrated to use it.
- **#1209 / v4.20.23** — `ActivePlayersTotal` carries `filter[status]=active`.
- **#1210 / v4.20.24** — 5 academy KPIs (EvaluationsThisMonth, NewEvaluationsThisWeek, AttendancePctRolling, RecentAcademyEvents, GoalsByPrincipleKpi) ship `linkUrl()` overrides with date-window deep-links matching #771's pattern.
- **#1211 / v4.20.25** — `OpenTrialCases` carries `status=open,extended`.
- **#1212 / v4.20.26** — `MyTeamAttendancePct` + `MyTeamAvgRating` pass `filter[team_id]` to destination.
- **#1213 / v4.20.27** — `MyEvaluationsThisWeek` aligns 7d window with destination.

## Audit 3 — Standard reports AC scope leak

Two of the analytics module's reports leaked academy-wide data to the assistant-coach persona (same family as #1147 closed in v4.20.4):

- **#1187 / v4.20.29** — `FrontendStandardReportsView` 6 slug handlers + launcher gain a `scope()` helper that narrows via `get_teams_for_coach` for non-admins. AC-only team/player pickers replace the academy-wide pickers.
- **#1193 / v4.20.34** — `FrontendMinutesTeamReportView` `listTeams()` + URL-tamper guard close the same shape on the minutes-team report (shipped slightly later via #1034, missed by v4.20.4's pass).

## #1188 / v4.20.30 — SaaS-readiness direction-setter

`QueryHelpers::get_player()` historically required a strict `club_id = CurrentClub::id()` match, drifting from the on-screen player loader which doesn't. The drift surfaced as #1149 (Print doelenintake "Player not found" despite player profile rendering) and a family of follow-up scope-mismatch bugs. **Fix** drops the strict club_id clause from `get_player`. **Implication beyond the fix**: this set the direction for every subsequent audit-7 follow-up — per-helper `club_id` filtering is being phased out in favour of request-layer enforcement, which is the right tenancy model for SaaS (CLAUDE.md §4). Inline `What this is NOT` notes throughout the audit drain cite #1188 so subsequent edits don't reflex-revert.

## Audit 1 — Authorization matrix entity catalogue

The matrix admin UI's "no tile uses this entity" warning fired on 17 false-orphan entries because their consumer pages are wp-admin surfaces using either a WordPress cap (`administrator`, `manage_options`, `read`) or a `tt_*` cap that maps via `LegacyCapMapper` to a different entity (e.g. Spond admin uses `tt_edit_teams`).

- **#1189 / v4.20.31** — `CoreSurfaceRegistration` exports tile entity aligned to `reports`. Closes the non-admin-denial half of the bug class.
- **#1192 / v4.20.33** — `MatrixEntityCatalog::ADMIN_ONLY_ENTITIES` widened with 17 entries (`roles`, `authorization_matrix`, `matrix_preview_apply`, `backup`, `demo_data`, `custom_css`, `impersonation_action`, `usage_stats_details`, `documentation`, `persona_templates`, `rating_scale`, `translations`, `translations_config`, `custom_widgets`, `football_actions`, `spond_integration`, `thread_messages`), each with an inline comment naming the consumer surface.

## Audit 9 — Form save/cancel + redirect-shape polish

- **#1195 / v4.20.35** — `FrontendTestTrainingsView` post-save redirect `dashboard` → `list` (same bug class as #795). The `dashboard` shape was unparsed by `public.js` so saves succeeded but the operator saw a blank form.
- **#1196 / v4.20.36** — 3 Cancel buttons (tournament create/edit, VCT defaults card, PHV flag panel) now honour `tt_back` per CLAUDE.md §6 point 5.

## Audit 8 — Cross-entity picker privacy

- **#1202 / v4.20.42** — `FrontendTeamBlueprintsView` "Other team" picker narrows to coach scope. Head-coach editing their own blueprint could browse the entire academy roster across every other team — a privacy leak under CLAUDE.md §1 (minors).

## Audit 4 — i18n

- **#1220 / v4.20.43** — 38 `wp_die()` English literals across Development + Invitations handlers (`IdeaPromoteHandler`, `IdeaRefineHandler`, `IdeaRejectHandler`, `IdeaSubmitHandler`, `TrackDeleteHandler`, `TrackSaveHandler`, `InvitationAcceptHandler`, `InvitationCreateHandler`, `InvitationRevokeHandler`, `MessageSaveHandler`) wrapped in `__()` + 2 misc (`BaseController` field-required sprintf, `BackupSettingsPage` unknown-error fallback). 5 new msgids ship with Dutch translations.

## Audit 5 — Wizard reactivity

- **#1186 / v4.20.28** — `tournament-wizard.js` `rebuildChipHidden` dispatches a change event on the hidden CSV input so autosave fires.

## Architecture — ActivitiesRepository extraction

- **#1190 / v4.20.32** — New `Activities\Repositories\ActivitiesRepository` (`findById`, `listRosterAttendance`, `attendanceMapByPlayer`) shared between `FrontendActivitiesManageView` and `ActivityBriefPdfExporter`. Closes the data-source divergence that produced subtle differences between the edit form and the brief PDF.

## What's not in this release

- **i18n batches #1204-#1219** — Translator-quality work for 10 follow-up batches the audit-4 drain queued. Skip-flagged: needs human review for Dutch nuance, not autonomous patching.
- **#1191 / #1223** — Workflow file edits flagged by audits 1 + 7. Blocked by the release.yml self-modification guardrail.
- **#1194** — Multi-day UI build flagged by audit 9. Out-of-scope for a patch-level audit drain.
- **#1017 / #1129** — Chemistry algorithm (design call needed) + VCT-8 catalogue seed (content-heavy, pilot-coach review gated).
- **#1221** — Direction ambiguous post-#1188's loosened `get_player()`; skip-flagged with three possible directions documented on the issue.

## Upgrade notes

No schema migrations. No matrix seed changes. No new caps. No new tiles. Drop the new zip in place; PUC handles the rest.

---

# TalentTrack v4.19.9 — VCT Phase 2, standard reports, pilot polish

Cumulative release covering every ship since v4.17.2 (2026-05-31). Twenty-two patches across three feature epics — the **VCT module Phase 2 UI**, the **standard-reports module** (12 reports across 2 PRs), and the **2026-06-03 pilot-feedback batch** — plus three rounds of authorization-scope refinement and the foundation rewrites that unblocked them (touch-friendly rating input, lookup-translation completeness, match-prep print rebuild).

The plugin version on disk advanced one minor (4.18.x = VCT Phase 2 UI) and a second minor (4.19.x = standard reports) since v4.17.2; this release rolls both up to a single tag. There are **no operator-breaking changes** — three new schema migrations (0140 PHV extension, 0141 + 0142 lookup-translation backfill, 0143 AC seed trim, 0144 activity time fields) all ship as additive + idempotent.

## VCT module — Phase 2 UI complete (#905)

The Voetbal Conditionele Training module's safety-critical core (schema, rules engine, REST, workflow task) shipped in Phase 1 before v4.17.2. This release closes the Phase 2 UI epic across **eight child PRs**:

- **VCT-12 — Configuration tiles** (#1087, v4.18.1). Two new HoD-gated tiles on the Configuration grid linking into the existing `?tt_view=vct-config` sub-tabs (macro-blocks + age-profiles), with live counts and a NEW pill matching the `.local-mockups/vct-config-tiles/` design-of-record.
- **VCT-13 — Team-defaults panel** (#1088, v4.18.2). Inline panel on team detail with weekday chips + default start time + duration, driving the new-VCT-session wizard's basis-step prefill. Cap-gated on `tt_vct_admin_library`.
- **VCT-14 — PHV per-player panel + hero pill** (#1089, v4.18.3). Schema migration 0140 adds `reason_key` + `intensity_ceiling` columns; Profile-tab panel with reason picker + ceiling dropdown + notes; orange `PHV` pill on the hero when active. Privacy gating per CLAUDE.md §1 (other parents see nothing, AC-also-parent sees own kid via parent persona only).
- **VCT-11 — Exercise library inline edit + search + intensity edge** (#1086, v4.18.4). Each library row gets an inline edit form, a client-side search input, and a 4px intensity-band coloured edge keyed to the mockup's intensity ramp.
- **VCT-9 — New-VCT-session wizard step 1 start time** (#1084 first slice, v4.18.5). Step 1 picks up an optional start-time field; prefills from the team's VCT defaults (#1088). Persists through `VctTrainingComposer` to `tt_vct_sessions.start_time`.
- **VCT-10 — Sideline PHV exclusion banner** (#1085 first slice, v4.18.6). Coach-view banner lists actively-flagged players on the team roster so the sideline reads the same data `WorkloadCapRule` enforces.
- **VCT epic closeout — docs + spec move** (#905, v4.19.3). New `docs/vct.md` (en + nl) with the per-surface URL map, capability matrix, shipped-feature index, parked follow-ups, and the inter-surface data-flow narrative; spec moved to `specs/shipped/0095-feat-vct-module.md`.
- **VCT-8 catalogue seed spun out** as #1129 (content-heavy, gated on pilot-coach review). The engine functions correctly with operator-added exercises today; the catalogue seed is an accelerator, not a blocker.

## Standard reports module — 12 reports across 2 PRs

The standard-reports mockup batch (#1063) shipped its implementation half:

- **6 explorer-bound presets** (#1119, v4.19.0) covering `evaluations_received`, `goal_progress`, `activity_volume`, `evaluation_coverage`, `attendance_vs_squad`, `prospects_logged_per_scout`. Each preset registers a new KPI keyed against the mockup vocabulary and adds an "Explorer →" button on the relevant entity surface (player Goals/Evaluations tabs, team detail, activity detail, Reports launcher). Central URL builder `\TT\Modules\Analytics\Domain\ExplorerUrl::build()` keeps every preset call site to two lines.
- **6 curated per-persona reports** (#1120, v4.19.1) — Player Minutes played, Team Minutes distribution, Team Squad evaluation summary, Season summary, Season Trial funnel, Scout report card. Slug-dispatched on `?tt_view=standard-report&slug=<key>` with shared chrome (KPI strip, empty state, entity pickers when player_id/team_id is absent). Every curated view's "Explorer →" action lands on the matching preset KPI from v4.19.0 with the same entity filter pre-applied.

Each report inherits the host surface's cap gate; the explorer re-checks `tt_view_reports` + the KPI's `context` (COACH / ACADEMY / PLAYER_PARENT). No new permission surfaces.

## 2026-06-03 pilot-feedback batch — 7 issues, 4 PRs

Pilot triage on 2026-06-03 raised seven issues around the activity surfaces, teamplanner, and methodology principles; all closed across four PRs:

- **Planner bugs** (#1133, v4.19.6) closes #1121 (`LabelTranslator::activityType()` routed through `LookupTranslator::byTypeAndName` so operator-added activity-type rows render their Dutch label), #1124 (planner team list scope-filters via `QueryHelpers::get_teams_for_coach()` for non-admins; admins unchanged — was leaking sibling teams to AC users after #1060), and #1127 (planner activity query gains `archived_at IS NULL` so archived activities stop rendering as cards).
- **Principles render polish** (#1134, v4.19.7) closes #1123 (activity detail's linked principles move from an inline `<dt>/<dd>` to a dedicated "Gekoppelde spelprincipes" section with linked-pill palette keyed off the code's first letter) and #1125 (planner card chips show the bare code + bucket colour, up to 4 per card with `+N` overflow).
- **Two-level principle picker** (#1135, v4.19.8) closes #1122. Both `PrinciplesStep` (new-activity wizard) and `FrontendActivitiesManageView::renderForm` (edit form) replace the hold-Ctrl flat multiselect over 18 principles with a stack of `<details>` sections — one per team function — with a small Dutch-cap label per team-task sub-bucket and one checkbox per principle inside. 44px minimum row height so each principle is a real tap target on phones.
- **Activity start/end time fields** (#1136, v4.19.9) closes #1126. Migration 0144 adds `start_time` + `end_time` (both nullable TIME columns) after `session_date`. Wizard step + edit form gain optional time inputs; activity detail, team detail Aankomende activiteiten, planner card, and the REST payload all render the time window when set. Empty fields render nothing — no placeholder.

## Authorization-scope refinements

Three rounds of AC scope-creep audit followed up on #1060's foundational tightening:

- **#1105 / v4.17.3** removed `podium_panel` from the AC default seed — the Podium tile linked to an evaluations-derived leaderboard AC could no longer read.
- **#1106 / v4.19.5** completed the per-entity audit confirming `rate_cards` + `compare` REMOVE (both aggregate development-judgment data) while `reports` / `people` / `vct` KEEP (operational, gated at the next layer or shared with HC by spec). Migration 0143 mirrors #1105's idempotent + `is_default = 1`-only DELETE pattern.
- **#1107 / v4.17.5** locked down the player-detail view's Evaluations / PDP / Trials tabs + avg-rating KPI so they cap-check at render time, not just at the tab-set generator. Defense in depth.
- **#1104 / v4.17.4** added `ORDER BY id DESC` to `AuthorizationService::getPersonIdByUserId` (deterministic resolution when a WP user has multiple active `tt_people` rows) + migration 0139 dedupes existing rows. Closes the AC-dashboard-empty silent disagreement between resolver and admin Persoon edit page that took three hours to diagnose during pilot.
- **#1102 / v4.17.6** added a green-check / amber-warning hint to the persona dashboard editor when a widget's cap is invisible to the persona's default WP role. Editor-time signal so admins don't ship layouts AC users can't see.

## Lookup-translation completeness (#902)

`tt_translations` had three distinct gaps the operator-facing Lookups admin exposed:

- **Gap 1 — positions.** Migrations 0086/0106/0109 called `__('GK')`, but `.po` files only have msgids for the long forms ("Goalkeeper"); the gettext-equal-source guard skipped every INSERT. Migration 0141 drives the long form through gettext via `LabelTranslator::positionLongForm()`.
- **Gap 2 — player values.** The 8 values seeded by migration 0031 (`Commitment`, `Coachability`, etc.) were never wrapped in `__()`. Migration 0142 ships hardcoded translations across nl_NL / fr_FR / de_DE / es_ES; new `LabelTranslator::playerValueLabel()` anchors them for future extractor coverage.
- **Gap 3 — fr/de/es position translator content.** 10 of 11 positions had empty `msgstr` in fr/de/es `.po` files. Filled with standard football vocabulary (Défenseur central / Innenverteidiger / Defensa central, etc.).

## Match prep print rebuild (#1059)

PR #1041's "align browser print to the legacy `MatchPrepPdfExporter` template" decision had both outputs consistent but consistently wrong vs. the on-screen view. New `MatchPrepPrintableRenderer` (v4.19.4) is the single source of truth — formation pitches per half (reusing `FrontendMatchPrepView::defaultSlotLayouts()`), Dutch labels (Algemeen / Aanvallen / Verdedigen / Spelhervattingen ×2), one row per available player on the "Doen per speler" column. `MatchPrepPdfExporter` delegates to the same renderer so print + PDF stay in lockstep going forward.

## Touch-friendly rating input (#1067 / v4.18.0)

Replaces typed-number rating inputs with a chip-grid + inline-slider component wherever a coach captures a rating. New `\TT\Shared\Frontend\Components\RatingInputComponent` ships two render methods: `renderSingle()` emits an 11-chip grid for a single overall rating (no keyboard, one-tap commits a final value); `renderListRow()` emits a label + range slider + tabular value-readout row that fits a 360px viewport with all four canonical category names. Slider rows track an empty state (`data-tt-rating-empty="1"`) so unrated values don't post. Dropped into `PostGameEvaluationForm`, `PlayerSelfEvaluationForm`, `RateActorsStep`, and `HybridDeepRateStep`. Server-side validators upgraded to floats + snap-to-0.5; `EvaluationInserter` mirrors the float+snap before writing.

## Other ships within this release

- **v4.17.0** — printable season-start goal-setting intake + selectable methodology reference card (#1064). Per-player A4 portrait (snapshot + 3 goals + reflection) and team-batch concatenation.
- **v4.17.1** — per-eval-type category allowlist (#819). New `tt_eval_type_categories` join table + admin matrix; wizard filters the category list per eval type.
- **v4.17.2** — `LookupTranslator` into Evaluations repository (#806 first slice). `EvaluationsRepository::recentForCoach()` now pulls + localises lookup-backed fields at the repository boundary so view code that does `echo $row->type_name_localised` gets the localised string by construction. (Architectural worked example; four follow-up tickets file the same pattern in Goals / Activities / Players / PDP repos.)

## What's not in this release

- **VCT-8 — 80-exercise per-club catalogue seed.** Content-heavy, gated on pilot-coach methodology review. Tracked as #1129.
- **Phase 2 mockup-fidelity polish** items the VCT child PRs documented as deferred — wizard MD-context chip-bar visualization, bottom-sheet exercise picker, current-block teal highlight, live timer on the coach view, A4/A6 print polish. Each ships when pilot reports the friction.
- **Per-report cap audit** inside the Reports launcher. Some legacy reports may not check the per-entity cap at the next layer; tracked as a follow-up if pilot finds a leak.

## Upgrade notes

Four schema migrations land in this release. All additive + idempotent; no operator action required:

- **0140** — `tt_player_phv_flags.reason_key VARCHAR(64)` + `intensity_ceiling TINYINT` (VCT-14).
- **0141 + 0142** — backfill `tt_translations` for positions + player values across nl_NL / fr_FR / de_DE / es_ES.
- **0143** — DELETE `(persona='assistant_coach', entity IN ('rate_cards','compare'), is_default=1)` rows from `tt_authorization_matrix`. Operator overrides (`is_default=0`) survive.
- **0144** — `tt_activities.start_time TIME` + `end_time TIME`, both NULL.

`MatrixRepository::clearCache()` fires at the end of 0143 so in-flight AC sessions pick up the change on their next request.

---

# TalentTrack v4.16.0 — Assistant Coach scope tightened to operational-only (closes #1060)

Default authorization matrix defaults change. **AC is operational, HC is development.**

The assistant coach persona inherited too much of the head coach's read access — evaluations, PDP files, behaviour ratings, team chemistry sandbox. The pilot raised this in the context of an AC who is also a parent of a player on the same / sibling team: the kid's evaluations are HC professional-judgment data + safeguarding territory, and shouldn't be visible to the AC even if (or especially when) they're a parent. The fix is broader than that single case — AC's job is operational (run trainings, manage attendance, prep matches, take VCT sessions), HC's job is development (rate, plan PDP, set per-player goals).

## What ships

**Matrix seed change** (`config/authorization_seed.php`) — the AC persona block loses these entities and tile-visibility panels:

- `evaluations` — HC's per-player ratings.
- `pdp_file`, `pdp_verdict`, `pdp_conversations` — Personal development planning + verdicts (safeguarding territory).
- `team_chemistry` — chemistry sandbox + blueprint reads.
- `dev_ideas` — development authoring (AC was previously able to create ideas).
- `player_behaviour_ratings` — behaviour data is dev signal.
- `evaluations_panel`, `team_chemistry_panel`, `pdp_panel` — tile-visibility entities that would render empty tiles without the data caps above.

AC keeps every operational entity (team, players-identity, people, activities, goals, attendance, methodology, reports, rate_cards, compare, documentation, workflow, my-evaluations self-scoped, player_status, trial_inputs, player_timeline, invitations, player_notes, vct, every staff-development entity) plus `pdp_calendar_export` at `self` scope (AC exports own calendar slots).

**Backfill migration** (`database/migrations/0136_assistant_coach_scope_tightening.php`) — DELETEs the 10 removed AC rows from `tt_authorization_matrix` on existing installs, **scoped to `is_default = 1`** so any row an operator explicitly customised via the Authorization admin stays. Flushes the matrix read cache via `MatrixRepository::clearCache()` so AC sessions pick up the change on the next request. Idempotent; forward-only (reverting would re-grant AC access to development data, which is the safeguarding regression this closes).

**No per-surface code changes** — every gated view already routes through `current_user_can()` or `MatrixGate::can()`. Removing matrix rows automatically blocks:

- The Evaluations tab on the player profile (gated on `tt_view_evaluations`).
- The PDP tab + PDP file uploads (gated on the `pdp_file` matrix entity).
- Behaviour ratings card / rate-actor wizard (gated on `tt_rate_player_behaviour` + the `evaluations` matrix entity respectively).
- Team chemistry sandbox + per-blueprint editor (gated on `tt_manage_team_chemistry`).
- The `dev_ideas` authoring surface.

Match-prep and match-execution surfaces are unchanged — those gate on `match_prep` / `match_execution` entities (kept for AC), so HC's per-player notes still flow through to AC inside those operational windows. The AC-with-kid case is handled by the existing parent role: as parent of their own kid the AC sees that kid's evaluations/PDP via the `'parent'` persona block, independent of the AC matrix.

## Verification

Use `wp-admin/admin.php?page=tt-auth-chain-debug`, pick an AC user from the dropdown, confirm these caps return false post-migration:

- `tt_view_evaluations`
- `tt_edit_evaluations`
- `tt_view_player_behaviour`
- `tt_view_pdp`
- `tt_edit_pdp`
- `tt_manage_team_chemistry`

And confirm these stay true (operational entities):

- `tt_view_activities`, `tt_edit_activities`, `tt_mark_attendance`
- `tt_edit_match_prep`, `tt_edit_match_execution`
- VCT caps
- `tt_view_player_notes`

## Out of scope (follow-up issues if pilot raises)

- **Per-tab visibility on the player profile** when AC reaches a player's page. The Goals tab still renders since `goals` matrix entity stays operational (match-prep flow needs it). If pilot reports the tab feeling out of place, file a follow-up that introduces per-tab gating distinct from data-entity gating.
- **`tt_drill_analytics` cap** for the explorer view per §3 of #1060. Current behaviour: explorer view gates on the `analytics` matrix entity (kept HC-only). The optional belt-and-braces cap is a follow-up.
- **Aggressive migration**: today's migration preserves operator customisations. An aggressive variant that flips every AC row regardless of `is_default` is available if a future install needs the harder reset (e.g. SaaS multi-tenant onboarding).

## Pilot impact

AC users see the same operational surfaces they always did (activities, attendance, match prep/execution, methodology library, VCT, their own calendar + staff development). They no longer see other players' evaluations, PDP files, behaviour ratings, or the team chemistry sandbox. The AC-also-parent case sees their own kid's development data through the parent persona, unchanged from before.

---

# TalentTrack v4.13.0 — Team chemistry page rework, single-tier blueprint port (closes #1002, supersedes #1007)

Full surface rework of `?tt_view=team-chemistry`. Ports the design-of-record mockup at `.local-mockups/team-chemistry/index.html` onto the live surface: three-column shell with a roster sidebar on the left, the pitch in the centre, and a stacked KPI scoreboard plus coach-marked pairings panel on the right. The chemistry surface is single-tier — the chemistry engine scores primary cells only, so the secondary / tertiary tier stack the blueprint editor exposes is irrelevant here. Each pitch position renders one slot card.

## What ships

**PHP — view rebuild**

- `src/Modules/TeamDevelopment/Frontend/FrontendTeamChemistryView.php` (rewrite) — replaces the v1 single-column inline-styled layout with a mockup-driven three-column grid. New methods: `renderToolbar()` (formation picker + style summary + Suggested / Try-a-lineup segmented toggle + Save-as-blueprint), `renderRosterSidebar()` (sorted by best team-fit score, searchable), `renderPitchCard()` (hands off to `PitchSvg` with the legend chrome), `renderRightColumn()` + `renderScoreboard()` (the headline link-chemistry card plus composite / formation / style / depth / coverage sub-cards from the mockup) + `renderPairingsCard()` (inline coach-pairings list with a collapsible add form). The depth-chart table is dropped — the data still flows to the picker via the localised payload, but the standalone three-column "1st / 2nd / 3rd choice" table is gone in favour of the per-slot picker.
- Asset enqueue moved out of the cap-gated sandbox path. The chemistry CSS now enqueues on every entry to the view (team picker + board + error states) so styling is consistent everywhere; the JS still cap-gates on `tt_manage_team_chemistry`.

**JS — selector retarget + new wiring**

- `assets/js/frontend-team-chemistry.js` (rewrite) — selectors retargeted from `.tt-chem-sandbox*` to `.tt-tc-sandbox*`. New `wireSegmentedToggle()` replaces the v1 single-button `wireToggle()` and binds both segments (Suggested / Try a lineup) instead of toggling one. New `wireRosterFilter()` does live substring filtering on the sidebar (case-insensitive, name + position). New `wireFormationAutosubmit()` replaces the v1 inline `onchange="this.form.submit()"` so CSP-strict installs work. Sandbox + bottom-sheet picker + save-as-blueprint modal behaviour is unchanged from v3.110.174 / v3.110.184; only the surface they bind to has moved.

**CSS — mockup port**

- `assets/css/frontend-team-chemistry.css` (rewrite) — full token system from the mockup (`--tt-tc-bg`, `--tt-tc-panel`, `--tt-tc-line`, `--tt-tc-accent`, `--tt-tc-accent-2`, `--tt-tc-strong`, `--tt-tc-weak`, etc.). Mobile-first base CSS for ~360px; tablet at 768px (two-col with right column going full-width below); desktop at 1180px (three columns, right column sticky). Touch targets ≥ 44px on every interactive surface (toolbar select / segmented buttons / pairing form inputs / pairing-x remove). 16px input font-size for iOS no-zoom on focus. `prefers-reduced-motion` honoured on the picker + sandbox-on slot animations. The bottom-sheet picker + save-as-blueprint modal styles are preserved from v3.110.174 / v3.110.184 with the new token names.

## Bugs caught + fixed from #1007 (supersedes)

The `?tt_view=team-chemistry` v1 surface had four investigation-grade defects the rework folds in alongside the layout port:

1. **Inline `onchange="this.form.submit()"` on the formation dropdown.** Breaks under CSP `script-src 'self'` and produces a console warning on stricter dashboard themes. v4.13.0 replaces it with a `data-tt-tc-autosubmit` attribute handled by the chemistry JS.
2. **Team picker had no stylesheet enqueued.** The CSS file was only loaded inside `enqueueChemistrySandboxAssets()`, which was cap-gated on `tt_manage_team_chemistry`. Read-only viewers landing on the team picker saw unstyled `<a>` cards. v4.13.0 enqueues `frontend-team-chemistry.css` from the top of `render()` so every code path picks it up.
3. **No empty-state for installs without `tt_formation_templates` rows.** v1 emitted a one-line `tt-notice` and returned, with no styling. v4.13.0 renders a `.tt-tc-emptystate` card with a clear "Configure one in Settings → Team development" pointer.
4. **Help link button "How does this work?" stacked above the board.** Pushed the toolbar + pitch down 60px on phones for no benefit. v4.13.0 moves the help row below the board so the chemistry score is the first thing on screen.

## Out of scope

- Chemistry algorithm: unchanged (`BlueprintChemistryEngine::computeForLineup()` / `computeForSuggested()`, `ChemistryAggregator::teamChemistry()`).
- REST contracts: unchanged. `GET /teams/{id}/chemistry`, `POST /teams/{id}/chemistry/preview`, pairings CRUD, blueprint create + assignments PUT — all hit unchanged.
- Schema: no migration.
- Caps: same — `tt_view_team_chemistry` for read (dispatcher-gated), `tt_manage_team_chemistry` for sandbox + pairings CRUD.
- Multi-team chemistry comparison: separate ship if asked.
- Per-player chemistry detail drilldown: separate surface.
- The reasoning panel in the mockup ("Why?" with Default / Slot / Link states) is shape-only and deferred to a follow-up — the mockup's `body[data-sel]` switch is a JS state machine that needs server-side explanation strings the engine doesn't currently emit. Tracked separately.

## Why minor bump

Meaningful surface rework + restored functionality on a previously-broken page (#1007). Patch bump would understate the visual + interaction change.

# TalentTrack v4.12.15 — Match prep print polish + short player names (closes #1023)

Two scopes ship in one PR because they share files (the match-prep view + CSS) and the on-screen short-name change is what the print CSS inherits.

## A. Print polish (six items)

1. **Hide the dashboard brand banner / DEMO strip / breadcrumbs on print.** The `@media print` block now adds `display: none !important;` to `.tt-dash-header`, `.tt-dash-brand`, `.tt-dash-actions`, `.tt-dash-demo-pill`, `.tt-dash-help`, `.tt-user-menu`, `.tt-back-pill` on top of the existing `.tt-breadcrumbs` / `.tt-back-link-wrap` / `.tt-mp-toolbar` rules. The shared dashboard chrome (rendered by `DashboardShortcode::render()`) was leaking the JG4IT brand row + tagline + DEMO pill onto every printed page.
2. **Page title is now the first line on paper.** Source string changed from `Match prep — %1$s · %2$s` to `Match preparation — %1$s · %2$s` so the Dutch translation `Wedstrijdvoorbereiding — …` lands as the first visible printed line at 12pt bold (≈16px), no top margin. CSS rule `.tt-match-prep-title { font-size: 12pt !important; margin: 0 0 3mm !important; }` inside the print block.
3. **Player-name labels visible on both pitches.** The on-screen `.tt-mp-slot .tt-mp-slot-name` uses translucent backgrounds and inherited colours; the print block now forces `color: var(--tt-mp-ink) !important; background: #fff !important;` plus `-webkit-print-color-adjust: exact` so the slot number circle AND the player-name label both render on paper.
4. **Restore `!` (red) and camera (green) icon colours on print.** `.tt-mp-dps tbody td.tt-mp-col-spec.tt-mp-on` forces `color: var(--tt-mp-danger)` and `.tt-mp-dps tbody td.tt-mp-col-cam.tt-mp-on` forces `color: var(--tt-mp-success)`, both with `print-color-adjust: exact;` so they survive the "Background graphics off" default in print dialogs.
5. **Compact Wedstrijddoelen so it fits one landscape-A4 page.** Goal-box font 9pt → 7.5–8pt; row padding halved (`0.25mm 1mm`); section heading padding halved (`0.5mm 2mm`); `.tt-mp-goals-row` forced to two columns so attacking + defending sit side by side; grid columns tightened from `50mm / 1fr / 70mm` to `48mm / 1fr / 64mm`; body font 10pt → 9pt; line-height 1.25 → 1.2. The whole spreadsheet now fits on one landscape-A4 sheet at 100% print scale on Chrome / Edge / Firefox.
6. **Empty goal lines print blank, no placeholder dots.** Placeholder text (`…`, `Goal 1…` etc.) is now `color: transparent !important; opacity: 0 !important;` on every print-time goal-line input via `::placeholder` / `::-webkit-input-placeholder` / `::-moz-placeholder`. The horizontal underline rule remains visible — coaches see a clean line to write into.

## B. Short player names (whole match-prep surface)

New helper `TT\Shared\Util\PlayerShortName` resolves a list of players into a `[ player_id => short_name ]` map:

- Default: first name only (`Daan`, `Senna`, `Javi`).
- Disambiguation: when two players in the input set share a first name, both render as `<firstName> <lastInitial>` (`Daan P`, `Daan A`). The disambiguation scope is the input set, not the whole club.
- Graceful fallback for players with missing first or last names (returns the available part, or `—`).
- v1 assumes Western "first last" order — East-Asian "last first" conventions deferred.

`FrontendMatchPrepView::render()` computes the short-name map once from the team roster and threads it into every render site:

- Roster column (Selectie · Minuten).
- Doen per speler column (Player focus).
- Rollen & standaardsituaties column (Roles & set pieces).
- Pitch slot labels — the bootstrap payload's `players[].name` is the short form, so the JS `renderPitches()` / `renderRoster()` / `renderDps()` / `renderRoles()` paths pick it up without code changes.
- Availability drawer (`renderDrawer()`) — same `state.players[].name` source, same short form, same vocabulary across every sub-surface.

The full name is still passed on the bootstrap as `players[].full` so a future view variant can show the long form if needed; current renderers only consume `name`.

## Files

- `src/Shared/Util/PlayerShortName.php` (new) — the short-name resolver.
- `src/Modules/MatchPrep/Frontend/FrontendMatchPrepView.php` — title string change, short-name map, threaded into roster / Doen / Rollen / bootstrap.
- `assets/css/frontend-match-prep.css` — print-block rewrite for items 1-6.
- `.local-mockups/match-preparation/index.html` — mockup parallel-tracked so the design-of-record stays current.
- `talenttrack.php`, `readme.txt` — version 4.12.12, changelog stanza.
- `CHANGES.md` — this stanza.

## Out of scope

- Other surfaces (Player profile, Activities list, Team detail) still use full names. The short-name helper is `Shared\Util` so future call sites can adopt it, but this PR is match-prep only per the spec.
- Per-locale name ordering (East-Asian "last first") — v1 assumes Western order.
- Retrofit of any other view's print-CSS — same six items might apply to VCT-print / match-execution-print, deferred.

## DoD

- [x] Print one A4-landscape page fits everything (CSS-spec'd via 8pt body / halved padding / 48mm-1fr-64mm columns / two-up goal grid).
- [x] No dashboard brand chrome on print (`.tt-dash-header`, `.tt-user-menu`, breadcrumbs, back-pill all hidden).
- [x] Page title is first visible line at 12pt bold (Wedstrijdvoorbereiding — … via Dutch translation of the new source string).
- [x] Player names appear on both pitches in print (forced `color` + opaque `background` + `print-color-adjust: exact`).
- [x] `!` red, camera green on print (`tt-mp-on` rules in print block).
- [x] Empty goal lines print as clean rules with no placeholder text.
- [x] On-screen + print: every player label uses the short form (resolver threaded into PHP renders + JS bootstrap).
- [x] `.local-mockups/match-preparation/index.html` mirrors the changes.
- [x] Patch bump v4.12.12.

(closes #1023)

---

# TalentTrack v4.12.10 — PHPStan rule enforcing vocabulary constants (PR-set 8 of 8 — closes #988 umbrella)

Final PR-set in the #988 umbrella migration. Lands the custom PHPStan rule that flags raw string-literal comparisons against any value already enumerated under `TT\Domain\Vocabularies\Lookups\*` or `TT\Domain\Vocabularies\Enums\*` — the regression gate that prevents PR-sets 1-7's work from silently un-doing itself as new code lands.

## What ships

**PHP - PHPStan rule**

- `tests/PhpStanRules/VocabularyConstantsRule.php` (new) — implements `PHPStan\Rules\Rule`. On first node visit, scans `src/Domain/Vocabularies/{Lookups,Enums}/*.php` via reflection and builds a flat index of `string value -> [Class::CONST, ...]` suggestions. Walks four AST node families on every analyse run:
    - `BinaryOp\Identical` (`===`) and `BinaryOp\NotIdentical` (`!==`).
    - `BinaryOp\Equal` (`==`) and `BinaryOp\NotEqual` (`!=`).
    - `FuncCall` to `in_array($needle, [ 'literal_1', 'literal_2' ], $strict)` — the most common allowlist shape in the codebase.
  For each `String_` operand whose value matches a known vocabulary value, emits one error per literal: `String literal 'present' matches a TalentTrack vocabulary value. Use the typed constant AttendanceStatus::PRESENT instead (umbrella issue #988).` Identifier `talenttrack.vocabularyConstants`. Tip text directs the reader to `src/Domain/Vocabularies/{Lookups,Enums}/` and acknowledges the deliberately-out-of-scope contexts (SQL string literals, array keys, migration seeds — those may be locally suppressed when the rule lands a false-positive).

  Out of scope by design:
    - `switch ( $value ) { case 'present': ... }` arms — walking `Stmt\Case_` nodes is straightforward but reserved for a v2 iteration once the rule has burned in.
    - SQL string literals inside `$wpdb->prepare()` arguments — DB is the canonical source of truth; the literal there IS the canonical value.
    - Array keys like `[ 'present' => __( 'Aanwezig', 'talenttrack' ) ]` — the key IS the canonical value; rewriting it to `AttendanceStatus::PRESENT => ...` is correct but is a separate sweep.
    - Default-parameter literals (`function ( string $status = 'manual' )`). Reachable later via a `Param` walk; out of scope for v1.

- `tests/PhpStanRules/vocabulary-constants-rule.neon` (new) — opt-in PHPStan overlay. Registers the rule via `services:` with the `phpstan.rules.rule` tag. NOT included from `phpstan.neon` by default — operators wire it on by including this overlay from their own local config (`includes:` array in `phpstan.local.neon`). The header comment in the .neon file documents the wire-up.

**PHP - autoload wiring**

- `composer.json` — gains an `autoload-dev` PSR-4 mapping for `TT\Tests\PhpStanRules\` -> `tests/PhpStanRules/` so PHPStan can resolve the rule class via the composer autoloader. The mapping is in `autoload-dev`, not `autoload`, so the runtime plugin classmap stays unchanged. `composer dump-autoload` is required locally to pick up the new map; CI's `composer install` step covers this automatically.

**Default-disabled rationale**

Per #988's locked decisions (2026-05-28), PR-set 8 ships as infrastructure but with the rule **disabled by default**. The backwards-compat allowlist documented in `docs/rest-api.md` keeps raw string-literal comparisons legal until the one-release deprecation window closes — flipping the rule into the default `phpstan analyse` run today would flood the build with errors on the same call sites the allowlist deliberately tolerates (REST endpoints accept BOTH the raw literal AND the typed constant for one release). The wire-up is one `includes:` line away when the allowlist sunsets in the next minor.

**Rule severity**

The rule emits PHPStan-native `error`-level diagnostics — there is no `info` / `warning` tier in PHPStan core. "Disabled by default" is the equivalent of `info` for this rule until enabled.

## Why patch

PR-set 8 of 8 in a refactor umbrella. No new feature, no behaviour change, no schema migration, no REST change, no UI change. The 29 existing vocabulary classes under `src/Domain/Vocabularies/` are unchanged. The plugin runtime is byte-equivalent — only `composer.json` autoload-dev + two new files under `tests/` (which are not included in the plugin's runtime classmap).

## Test plan

- `composer install --dev` resolves the `autoload-dev` map; `vendor/composer/autoload_psr4.php` lists the `TT\Tests\PhpStanRules\` namespace.
- `vendor/bin/phpstan analyse -c phpstan.neon` runs unchanged — the rule overlay is NOT included; the analyse output is byte-equivalent to v4.12.9.
- Create a local `phpstan.local.neon` with the documented two-line `includes:` overlay. `vendor/bin/phpstan analyse -c phpstan.local.neon` emits at least one error of identifier `talenttrack.vocabularyConstants` on each existing `=== 'present'` / `=== 'completed'` / etc. site in `src/`.
- The rule does NOT flag SQL-prepare string literals (e.g. `'WHERE status = %s'` is a single literal, no equality operator near a vocabulary value).
- The rule does NOT flag literals inside `src/Domain/Vocabularies/` itself (the constants there ARE the canonical values; the equality check `'present' === self::PRESENT` would otherwise self-report).
- The rule's index is populated at first node visit, not per-node; analyse run time is negligibly affected (one-time `scandir` + 29 `ReflectionClass` constructions).

## Closes

The #988 umbrella issue. Each of PR-sets 1-7 closed its corresponding `partial #988` slice; this PR-set is `closes #988` since it is the final infrastructure piece (the PHPStan rule the umbrella's checklist named explicitly as PR-set 8). The rule itself is disabled by default per the locked decisions; flipping it on is a separate, single-line config edit in a future minor when the backwards-compat allowlist sunsets.

---

# TalentTrack v4.12.9 — Vocabulary constants for auth + ideas + invitations + behaviour (PR-set 7 of #988)

Seventh of eight PR-sets in the umbrella migration of #988 (~131 hardcoded vocabulary string literals -> typed constants under `TT\Domain\Vocabularies\*`). PR-set 1 (attendance + activity) shipped in v4.11.1; PR-set 2 (goals + tasks) in v4.12.3; PR-set 5 (reports + journey + scouting) in v4.12.5; PR-set 6 (tournament + match) in v4.12.6; PR-set 3 (PDP + trial) in v4.12.7; PR-set 4 (player + team) in v4.12.8; this ship — landing as v4.12.9 — covers the auth + ideas + invitations + behaviour vocabularies. Same architectural pattern, same backward-compat allowlist, same patch-bump rhythm.

## What ships

**PHP - new vocabulary classes**

- `src/Domain/Vocabularies/Lookups/IdeaStatus.php` (new) — nine constants for the values stored on `tt_dev_ideas.status`: `SUBMITTED`, `REFINING`, `READY_FOR_APPROVAL`, `REJECTED`, `PROMOTING`, `PROMOTED`, `PROMOTION_FAILED`, `IN_PROGRESS`, `DONE`. Mirrors the PR-set 1 / 2 / 3 / 4 / 5 / 6 file shape (`const ALL` + static `isValid()`). The nine values are the canonical lifecycle set per the `IdeaRepository::transition()` chokepoint, the `GitHubPromoter` start / failure paths, the kanban board's `boardColumns()` filter, and the `AuthorNotifier` notification arms.
- `src/Domain/Vocabularies/Lookups/IdeaType.php` (new) — four constants for `tt_dev_ideas.type`: `FEAT`, `BUG`, `EPIC`, `NEEDS_TRIAGE`. Maps directly to the type marker that goes into the promoted GitHub file (`<!-- type: feat -->` etc.) and the `<type>` segment of the assigned filename.
- `src/Domain/Vocabularies/Lookups/InvitationStatus.php` (new) — four constants for `tt_invitations.status`: `PENDING`, `ACCEPTED`, `EXPIRED`, `REVOKED`. Backs the `invitation_status` lookup seeded by migration 0108 with display labels for en_US / nl_NL / fr_FR / de_DE / es_ES.
- `src/Domain/Vocabularies/Lookups/InvitationKind.php` (new) — three constants for `tt_invitations.kind`: `PLAYER`, `PARENT`, `STAFF`. Drives the role resolver that maps a `kind` to a WP role (`tt_player` / `tt_parent` / staff functional role) on acceptance.
- `src/Domain/Vocabularies/Lookups/BehaviourRating.php` (new) — five constants for the 1..5 scale captured on `tt_player_behaviour_ratings.rating`: `CONCERNING` ('1'), `BELOW_EXPECTATIONS` ('2'), `ACCEPTABLE` ('3'), `STRONG` ('4'), `EXEMPLARY` ('5'). The column is DECIMAL so non-integer values (e.g. 3.5) are accepted when a coach captures a between-tier judgement; the five constants below are the canonical anchor points each `behaviour_rating_label` row maps to. Documentation-only addition this PR-set — no PHP-side `'1'..'5'` comparison literals surfaced; the class documents the seeded anchor set for future PHPStan rule consumption (PR-set 8).
- `src/Domain/Vocabularies/Lookups/PotentialBand.php` (new) — five constants for `tt_player_potential.potential_band`: `FIRST_TEAM`, `PROFESSIONAL_ELSEWHERE`, `SEMI_PRO`, `TOP_AMATEUR`, `RECREATIONAL`. Backs the `potential_band` lookup seeded by migration 0042 with display labels in en_US / nl_NL; consumed by `PlayerStatusCalculator::POTENTIAL_BAND_SCORES` (100 / 80 / 60 / 40 / 20 weights) and the trainer-facing potential-capture surface.
- `src/Domain/Vocabularies/Enums/ImpersonationEndReason.php` (new) — two constants for `tt_impersonation_log.end_reason`: `MANUAL`, `EXPIRED`. Code-only enum (not operator-editable), lives under `Vocabularies\Enums\*` per #988's locked sub-namespace split. `MANUAL` is the actor's "Switch back" click + the `ImpersonationService::end()` default-parameter case; `EXPIRED` is the daily orphan-cleanup cron closing a session older than 24h whose `ended_at` was still NULL.

**PHP - legacy classes converted to deprecated aliases**

- `src/Modules/Development/IdeaStatus.php` — the nine `public const *` declarations now delegate to `TT\Domain\Vocabularies\Lookups\IdeaStatus::*` via `use … as CanonicalIdeaStatus`. Each constant carries a `@deprecated since v4.12.9 — removed in next minor` docblock. The module-local `label()` / `authorFacingLabel()` / `boardColumns()` / `all()` helpers stay in place — they encode rendering rules that aren't part of the vocabulary contract.
- `src/Modules/Development/IdeaType.php` — same pattern: four `public const *` declarations delegate to the canonical `Vocabularies\Lookups\IdeaType::*` values; `label()` / `isValid()` / `all()` helpers stay.
- `src/Modules/Invitations/InvitationStatus.php` — same pattern: four `public const *` declarations delegate to `Vocabularies\Lookups\InvitationStatus::*`; `label()` helper stays.
- `src/Modules/Invitations/InvitationKind.php` — same pattern: three `public const *` declarations delegate to `Vocabularies\Lookups\InvitationKind::*`; `label()` / `isValid()` / `all()` helpers stay.

**PHP - literal -> constant replacements**

- `src/Infrastructure/PlayerStatus/PlayerStatusCalculator.php` — the `POTENTIAL_BAND_SCORES` map's five string keys (`'first_team'` ... `'recreational'`) swap to `PotentialBand::FIRST_TEAM` ... `RECREATIONAL` constants. Use statement added.
- `src/Infrastructure/REST/PlayerStatusRestController.php` — `setPotential()`'s allowlist literal-array `[ 'first_team', 'professional_elsewhere', ... ]` → `PotentialBand::ALL`. Use statement added.
- `src/Shared/Frontend/FrontendPlayerStatusCaptureView.php` — the form-handler's allowlist literal-array → `PotentialBand::ALL`; the `<select>` option-label map's five string keys → `PotentialBand::*` constants. Use statement added.
- `src/Shared/Frontend/FrontendPlayerDetailView.php` — the potential-popover `$bands` map's five `key` literals → `PotentialBand::*` constants. Use statement added (alongside the existing `PlayerStatus` import from PR-set 4).
- `src/Modules/Authorization/Impersonation/ImpersonationService.php` — `end()` method's `string $end_reason = 'manual'` default-parameter literal → `ImpersonationEndReason::MANUAL`. Use statement added.
- `src/Modules/Authorization/Impersonation/ImpersonationAdminPost.php` — `end()` handler's `ImpersonationService::end( 'manual' )` call-site literal → `ImpersonationEndReason::MANUAL`. Use statement added.
- `src/Modules/PersonaDashboard/Widgets/SystemHealthStripWidget.php` — `countPendingInvitations()`'s defensive `class_exists()` fallback literal `'pending'` → `InvitationStatus::PENDING` (canonical). Use statement swap: `TT\Modules\Invitations\InvitationStatus` → `TT\Domain\Vocabularies\Lookups\InvitationStatus`.

**Out of scope for this PR-set**

- `CertificationType` — empirical grep on the codebase surfaced zero PHP-side string-literal comparisons against the six `cert_type` lookup keys (`uefa_a`, `uefa_b`, `uefa_c`, `first_aid`, `gdpr`, `child_safeguarding`) seeded by migration 0048; the values live in `tt_lookups` and are read-only on the operator-facing surface (the cert-type lookup-id is the FK in `tt_staff_certifications.cert_type_lookup_id`, not a string-key comparison). A constants class would document them without making any literal-to-constant swap. Deferred to a future PR-set if call sites surface — same shape as PR-set 4's `PlayerValue` / `AgeGroup` / `Position` deferral.
- `BehaviourRating` is **declared-only** in this PR-set — the column is DECIMAL so the canonical 1..5 anchor values are stored numerically; PHP-side comparison literals against the five anchor keys don't surface in the call sites. The class documents the seeded anchor set for future PHPStan rule consumption (PR-set 8).
- Other auth-related state machines — MFA enrollment-state (timestamps + counters, no discrete vocabulary), audit log payloads (free-form), comms log status (separate cleanup task) — out of scope; the auth surface in PR-set 7's title refers specifically to the impersonation `end_reason` code-only enum.
- SQL string literals (`SET end_reason = 'expired'` in `ImpersonationService::cleanupOrphans()`'s UPDATE statement), `tt_lookups` seed values in `LookupCanonicalSeeds.php`, migrations 0024 / 0025 / 0042 / 0048 / 0108 / 0115 default values, .po / .pot files, test fixtures, and JavaScript stay as literals per the umbrella's locked plan.

## Why patch

PR-set 7 of 8 in a refactor umbrella. No new feature, no behaviour change, no schema migration. The constants are byte-equivalent to the literals they replace; the REST endpoints continue to accept BOTH the raw literal AND the new constant for one release (per #988's backward-compat allowlist) so external integrations do not break. The PHPStan rule (#988 PR-set 8) that will forbid raw literals is deferred until the allowlist drops in a subsequent minor.

## Test plan

- Admin submits a new dev idea via the `?tt_view=ideas-submit` surface: stored with `type=needs-triage`, `status=submitted`. Idea board renders the new card in the Submitted column.
- Admin refines the idea (Type → `feat`, Status → `ready-for-approval`): stored. `refined_at` / `refined_by` populated by `IdeaRepository::transition()`. Idea moves into the Ready-for-approval column on the board.
- Admin promotes the idea: `GitHubPromoter::promote()` flips status to `promoting`, then `promoted` on success or `promotion-failed` on API failure. Author notification fires on each transition arm.
- Admin invites a player via the `?tt_view=configuration&config_sub=invitations` surface: row inserted with `kind=player`, `status=pending`.
- Invitee opens the acceptance URL: `AcceptanceView` renders the player-details step; accept POST flips status to `accepted`.
- Admin revokes a pending invitation: row's status flips to `revoked`.
- A pending invitation past `expires_at` is opened: `InvitationService` lazy-flips it to `expired` before rendering the "this link has expired" page.
- System health strip widget on the admin dashboard reports the count of `pending` invitations.
- Coach records a behaviour rating of 3 via the player status capture: row inserted with `rating=3.0` against the seeded `behaviour_rating_label` 1..5 vocabulary.
- Coach sets a player's potential to `semi_pro`: row inserted in `tt_player_potential` with `potential_band=semi_pro`. `PlayerStatusCalculator` scores the band at 60 (vs 100 for `first_team`, 20 for `recreational`).
- Frontend player detail view's potential-popover renders the five bands with the canonical English labels (First-team / Professional elsewhere / Semi-pro / Top amateur / Recreational).
- REST `POST /players/{id}/potential` with `potential_band=first_team`: 200. With `potential_band=top_pro` (typo): 400 `bad_input` with `allowed` array listing the five canonical bands.
- Admin starts an impersonation session, then clicks "Switch back": `tt_impersonation_log.end_reason` carries `manual`.
- The daily `ImpersonationCron` runs against an orphan session > 24h old: `end_reason` carries `expired`. Both are equality-comparable against `ImpersonationEndReason::MANUAL` / `EXPIRED`.

---

# TalentTrack v4.12.8 — Vocabulary constants for player + team (PR-set 4 of #988)

Fourth of eight PR-sets in the umbrella migration of #988 (~131 hardcoded vocabulary string literals -> typed constants under `TT\Domain\Vocabularies\*`). PR-set 1 (attendance + activity) shipped in v4.11.1; PR-set 2 (goals + tasks) in v4.12.3; PR-set 5 (reports + journey + scouting) in v4.12.5; PR-set 6 (tournament + match) in v4.12.6; PR-set 3 (PDP + trial) in v4.12.7; this ship — landing as v4.12.8 — covers the player-side roster vocabularies. Same architectural pattern, same backward-compat allowlist, same patch-bump rhythm.

## What ships

**PHP - new vocabulary classes**

- `src/Domain/Vocabularies/Lookups/PlayerStatus.php` (new) — five constants for the lifecycle values stored in `tt_players.status`: `ACTIVE`, `TRIAL`, `INACTIVE`, `RELEASED`, `GRADUATED`. Mirrors the PR-set 1 / 2 / 5 file shape (`const ALL` + static `isValid()`). The five values are the canonical set per `JourneyEventSubscriber::emitStatusTransition()`, `LabelTranslator::playerStatus()`, the `PlayersPage` status dropdown, and the trials / workflow forms that write the column. Lifecycle vs archive: the `archived_at` column from migration 0010 is the soft-delete / bulk-archive marker (NULL vs timestamp); `status` is the orthogonal lifecycle marker, so archived players still carry one of the five values. Migration 0061 already back-filled legacy `status='deleted'` rows from v3.89.1-and-earlier delete paths back to `'active'` (with `archived_at` populated), so the five-value vocabulary is the only stored set on every install. `GRADUATED` is intentionally part of `ALL` even though `PlayersPage`'s status dropdown currently exposes only four of the five values — the `JourneyEventSubscriber` already emits a `graduated` journey event when the column flips to that value, so the vocabulary documents the canonical five-state set; surfacing the fifth dropdown option is a separate UX task.
- `src/Domain/Vocabularies/Lookups/PreferredFoot.php` (new) — three lowercase constants for `tt_players.preferred_foot`: `LEFT`, `RIGHT`, `BOTH`. Backs the `foot_option` lookup (operator-editable, seeded by migration 0001 with TitleCase display labels), but the stored player-record value is the lowercase key per `RosterDetailsStep::validate()`'s `sanitize_key()` + allowlist. The empty-string sentinel ("not specified") is intentionally not part of `ALL` — it represents the absence of one of the three options. Chemistry / compatibility engines that compare against `'left'` / `'right'` slot sides are NOT consumers of this vocabulary — those are `position_side_preference` / `slot_side` comparisons (a different left / right / center vocabulary) and stay out of scope for this PR-set.

**PHP - literal -> constant replacements**

- `src/Modules/Players/Admin/PlayersPage.php` — replaces the four literals in the `$status_options` map (`'active'` / `'inactive'` / `'trial'` / `'released'`), the `selected( $player->status ?? 'active', ... )` default, the `handle_save` `$_POST` fallback, and the `stub` row creation with `PlayerStatus::ACTIVE / INACTIVE / TRIAL / RELEASED` constants. SQL string literal `WHERE pl.status='active'` in `render_list()` is kept as a literal per the spec (DB is the source of truth).
- `src/Modules/Players/PlayerCsvImporter.php` — `status` default on row sanitisation: `'active'` → `PlayerStatus::ACTIVE`.
- `src/Shared/Frontend/FrontendPlayerDetailView.php` — trial-player gate on the trials tab empty state: `(string) $player->status === 'trial'` → `=== PlayerStatus::TRIAL`.
- `src/Shared/Frontend/FrontendTrialsManageView.php` — inline player-create on the trial-case create form + the status flip on the existing player: both `'trial'` literals → `PlayerStatus::TRIAL`.
- `src/Infrastructure/Journey/JourneyEventSubscriber.php` — the three-arm `emitStatusTransition()` match — status comparisons swap to `PlayerStatus::*` constants. Pairs cleanly with PR-set 5's `JourneyEventType::*` swap on the `EventEmitter::emit()` emit-arg side: this PR-set replaces the `$new === 'released'` LHS comparisons; PR-set 5 already replaced the `'released'` second-positional emit arg with `JourneyEventType::RELEASED`. Result is a fully-typed branch with no raw literals on either side of the assignment.
- `src/Infrastructure/Query/LabelTranslator.php` — `playerStatus()` switch cases swap to `PlayerStatus::*` constants. Adds a `case PlayerStatus::GRADUATED` arm for symmetry (missing previously). The legacy `case 'deleted'` arm is preserved as a literal — it's a historical-display safety net for migration-0061-pre installs that may still surface a value not in the canonical five-state set.
- `src/Modules/Tournaments/Wizard/SquadStep.php` — trial-badge gate on the squad picker: `$pl->status === 'trial'` → `=== PlayerStatus::TRIAL`.
- `src/Modules/Wizards/Player/ReviewStep.php` — status assignment on wizard submit: `$path === 'trial' ? 'trial' : 'active'` → `? PlayerStatus::TRIAL : PlayerStatus::ACTIVE`.
- `src/Modules/Wizards/Player/RosterDetailsStep.php` — preferred-foot allowlist in `validate()`: `[ '', 'left', 'right', 'both' ]` → `[ '', PreferredFoot::LEFT, PreferredFoot::RIGHT, PreferredFoot::BOTH ]`.
- `src/Modules/Workflow/Forms/RecordTestTrainingOutcomeForm.php` — the new-player insert on prospect-admission: `'status' => 'trial'` → `PlayerStatus::TRIAL`.
- `src/Modules/Workflow/Forms/AwaitTeamOfferDecisionForm.php` — the accepted-offer update: `[ 'status' => 'active' ]` → `[ 'status' => PlayerStatus::ACTIVE ]`.
- `src/Modules/DemoData/Generators/PlayerGenerator.php` — the seeded player insert + the `tt_player_created` hook payload: both `'status' => 'active'` → `PlayerStatus::ACTIVE`.

**Out of scope for this PR-set**

- `PlayerValue` / `AgeGroup` / `Position` — empirical grep on the codebase surfaced zero PHP-side string-literal comparisons against the eight player-value keys (the 0031 PDP-cycle seed), the U7-U23 / Senior age-group codes (the 0001 + 0051 seeds), or the 11 position abbreviations (the 0001 seed). The values live in `tt_lookups` and are read-only on the operator-facing surface; a constants class would document them without making any literal-to-constant swap. Deferred to a future PR-set if call sites surface — the issue's "every value" rule is satisfied at the call-site replacement layer, not by ahead-of-need declaration.
- `TeamLevel` / `AgeGroupCode` — `tt_teams` has no level / tier column (squad tier sits on `tt_team_blueprint_assignments.tier` per migration 0072, scoped for PR-set 7's `BlueprintTier` enum); the `age_group` column on `tt_teams` is VARCHAR but no equality comparisons surfaced in code.
- `PlayerOnePagerPdfExporter::statusLabel()` — has a defensive 6-value map (`active` / `archived` / `trial` / `released` / `contracted` / `inactive`) for display fallback against historical / drifted values; left as literals because the map intentionally accepts values outside the canonical five-state set and acts as a defensive translation surface, not a vocabulary contract.
- SQL string literals, `tt_lookups` seed values, .po / .pot files, test fixtures, and JavaScript stay as literals per the umbrella's locked plan.

## Why patch

PR-set 4 of 8 in a refactor umbrella. No new feature, no behaviour change, no schema migration. The constants are byte-equivalent to the literals they replace; the REST endpoints continue to accept BOTH the raw literal AND the new constant for one release (per #988's backward-compat allowlist) so external integrations do not break. The PHPStan rule (#988 PR-set 8) that will forbid raw literals is deferred until the allowlist drops in a subsequent minor.

## Test plan

- Coach creates a new player via the admin form: stored with `status=active`. Status dropdown lists Active / Inactive / Trial / Released — unchanged from previous behaviour.
- Coach edits an existing trial player to `status=active` (signing flow): `JourneyEventSubscriber::emitStatusTransition()` writes a `signed` journey event via `EventEmitter::emit()` exactly as before.
- Coach edits a player to `status=released` or `status=graduated`: corresponding journey events fire.
- Player-create wizard, roster path: `status=active`. Trial path: `status=trial`. Preferred-foot dropdown accepts `left` / `right` / `both` and persists the lowercase key.
- CSV bulk import without a `status` column: defaults to `active`.
- Frontend trial-case create with inline new-player: new `tt_players` row carries `status=trial`; the trial case ties to it. Existing-player promotion flips the row to `trial`.
- Tournament wizard squad step: trial players surface with the Trial badge, unchecked by default.
- Workflow form "Record test-training outcome" (prospect admitted): new `tt_players` row carries `status=trial`.
- Workflow form "Await team offer decision" (accepted): existing player row flips to `status=active`.
- Demo-data seed run: every generated player carries `status=active` and the `tt_player_created` hook payload reflects the same.
- LabelTranslator round-trip: `playerStatus('graduated')` returns "Graduated" (previously fell through to `humanise()`); other arms unchanged.

---

# TalentTrack v4.12.7 — Vocabulary constants for PDP + trial (PR-set 3 of #988)

Third of eight PR-sets in the umbrella migration of #988 (~131 hardcoded vocabulary string literals -> typed constants under `TT\Domain\Vocabularies\*`). PR-set 1 (attendance + activity) shipped in v4.11.1; PR-set 2 (goals + tasks) shipped in v4.12.3; this ship covers the PDP-cycle and trial-case vocabularies. Same architectural pattern, same backward-compat allowlist, same patch-bump rhythm.

## What ships

**PHP - new vocabulary classes**

- `src/Domain/Vocabularies/Lookups/PdpStatus.php` (new) — three lowercase constants for `tt_pdp_files.status`: `OPEN`, `COMPLETED`, `ARCHIVED`. Mirrors the PR-set 1 / 2 file shape (`const ALL` + static `isValid()`). The column is VARCHAR(20) with `DEFAULT 'open'` per migration 0031; `PdpFilesRepository::setStatus()` is the gate that rejects any value outside the three.
- `src/Domain/Vocabularies/Lookups/PdpVerdictDecision.php` (new) — four constants for `tt_pdp_verdicts.decision`: `PROMOTE`, `RETAIN`, `RELEASE`, `TRANSFER`. Backs the `pdp_verdict_decision` lookup seeded by migration 0112 with per-locale translations through `tt_translations`. `PdpVerdictsRepository::upsertForFile()` is the gate.
- `src/Domain/Vocabularies/Lookups/TrialCaseStatus.php` (new) — four constants for `tt_trial_cases.status`: `OPEN`, `EXTENDED`, `DECIDED`, `ARCHIVED`. Backs the `trial_case_status` lookup seeded by migration 0116.
- `src/Domain/Vocabularies/Lookups/TrialCaseDecision.php` (new) — six constants for `tt_trial_cases.decision`: `ADMIT`, `DENY_FINAL`, `DENY_ENCOURAGEMENT`, `OFFERED_TEAM_POSITION`, `DECLINED_OFFERED_POSITION`, `CONTINUE_IN_TRIAL_GROUP`. Backs the `trial_case_decision` lookup seeded by migration 0116. The three rolling-membership decisions (#0081 child 4) sit alongside the classic admit / decline triad — single vocabulary, one canonical list.

**PHP - literal -> constant replacements**

- `src/Modules/Pdp/Repositories/PdpFilesRepository.php` — insert default for new files moves from `'open'` to `PdpStatus::OPEN`; the `setStatus()` allowlist `in_array( $status, [ 'open', 'completed', 'archived' ], true )` becomes `PdpStatus::isValid( $status )`.
- `src/Modules/Pdp/Repositories/PdpVerdictsRepository.php` — drops the private `ALLOWED_DECISIONS` literal array; the `upsertForFile()` gate switches to `PdpVerdictDecision::isValid()`. The `label()` switch cases reference `PdpVerdictDecision::*` constants.
- `src/Modules/Pdp/Rest/PdpVerdictsRestController.php` — drops the private `ALLOWED_DECISIONS` literal array; the PUT-handler validation switches to `PdpVerdictDecision::isValid()`; the error payload's `allowed` key uses `PdpVerdictDecision::ALL`.
- `src/Modules/Pdp/Frontend/FrontendPdpManageView.php` — the list-filter `$status_options` keys, the verdict-form `$decisions` keys, and the private `statusLabel()` switch cases all reference the new constants.
- `src/Modules/Pdp/Frontend/FrontendMyPdpView.php` — the read-only verdict `decisionLabel()` switch cases reference `PdpVerdictDecision::*`.
- `src/Modules/Trials/Repositories/TrialCasesRepository.php` — the `STATUS_*` and `DECISION_*` class constants now alias `TrialCaseStatus::*` and `TrialCaseDecision::*` rather than carrying duplicate raw strings. Backward compatible: every existing internal caller compiles and produces the same stored value. The `recordDecision()` allowlist switches from the self-constant triad to the `TrialCaseDecision::ADMIT|DENY_FINAL|DENY_ENCOURAGEMENT` triad; the status / decision label switches reference the new constants directly.
- `src/Infrastructure/Journey/JourneyEventSubscriber.php` — the post-trial-decision branches (signed / released journey events) switch from `'admit'` / `'deny_final'` literals to `TrialCaseDecision::ADMIT` / `TrialCaseDecision::DENY_FINAL`.
- `src/Modules/Trials/TrialGroupTeam.php` — the two `wpdb->prepare()` bindings for the trial-group active-member queries switch from the `'continue_in_trial_group'` literal to `TrialCaseDecision::CONTINUE_IN_TRIAL_GROUP`.
- `src/Modules/PersonaDashboard/Kpis/TrialGroupActiveCount.php` — the KPI's active-trial-group-member query binding switches to `TrialCaseDecision::CONTINUE_IN_TRIAL_GROUP`.
- `src/Modules/Workflow/Templates/ReviewTrialGroupMembershipTemplate.php` — the chain-step gate for the `continue_in_trial_group` branch switches to `TrialCaseDecision::CONTINUE_IN_TRIAL_GROUP`.

**Out of scope for this PR-set**

- SQL string literals (`status IN ('open','extended')` in `TrialCasesRepository::findOpenForPlayer` and `listEndingBetween`, `status NOT IN ('completed','archived')` in `SeasonCarryover::copyOpenGoals`) stay as literals — DB is the source of truth, not the PHP layer.
- Form-internal radio-button values in `ReviewTrialGroupMembershipForm` (`offer_team_position`, `decline_final`) stay as form-input literals — they're transient HTML radio values mapped to canonical `TrialCaseDecision::*` values inside `serializeResponse()`, not themselves stored. Replacing them would conflate two vocabularies.
- The local `pdpFileStatusLabel()` switch in `PdpPrintRouter` translates an `'open'`/`'closed'` enum that is separate from the `tt_pdp_files.status` vocabulary — kept local per the existing comment.
- `LookupCanonicalSeeds.php` has stale / drift-prone entries for `pdp_verdict_decision` and `trial_case_status` ("On track / Behind / Ahead / At risk / Released" and "Open / In progress / Decision pending / Accepted / Rejected") that don't match the canonical pools. That's a #987 cleanup item, out of scope for #988.
- Migrations, `tt_lookups` seed values, .po / .pot files, test fixtures, and JavaScript stay as literals per the umbrella's locked plan.

## Why patch

PR-set 3 of 8 in a refactor umbrella. No new feature, no behaviour change, no schema migration. The constants are byte-equivalent to the literals they replace; the REST endpoints continue to accept BOTH the raw literal AND the new constant for one release (per #988's backward-compat allowlist) so external integrations do not break. The PHPStan rule (#988 PR-set 8) that will forbid raw literals is deferred until the allowlist drops in a subsequent minor.

## Test plan

- Coach opens the PDP manage list at `?tt_view=pdp`: the status filter dropdown still shows Open / Completed / Archived; selecting one filters the file list as before.
- Coach opens a PDP file: the verdict-form dropdown still offers the four `promote` / `retain` / `release` / `transfer` decisions with the academy-progression labels; submitting still upserts the verdict.
- Coach records a trial decision via `TrialCasesRepository::recordDecision()` with `admit` / `deny_final` / `deny_encouragement`: stored as before; the journey subscriber emits the signed / released events on `admit` / `deny_final`.
- HoD landing's "Players in trial group" KPI counts trial cases with `decision = 'continue_in_trial_group'` (byte-identical to prior).
- ReviewTrialGroupMembershipTemplate chain-step gates the re-spawn on `decision === 'continue_in_trial_group'` (byte-identical to prior).
- Player / parent opens the read-only PDP at `?tt_view=my-pdp`: the verdict-decision label resolves through `PdpVerdictDecision::*` or the operator-edited `tt_translations` value, identical to prior behaviour.

---

# TalentTrack v4.12.4 — Match prep widen + landscape A4 print + save-indicator + in-place print button (closes #998)

Four bundled UX defects on the head-coach match-preparation surface (`?tt_view=match-prep&activity_id=<id>`), shipping together as one patch because they sit on the same three files.

## What ships

**(1) Widen on-screen** — `.tt-dashboard:has(.tt-match-prep)` lifts the wrapper max-width from 1100px to 1320px on the match-prep route only; every other dashboard view stays at 1100px. Desktop grid columns widen from `12.5rem | 1fr | 20rem` to `14rem | 1fr | 22rem`. Mobile and tablet breakpoints untouched.

**(2) Landscape A4 print CSS** — new `@page { size: A4 landscape; margin: 8mm }` plus an `@media print` block that drops the dashboard chrome (`.tt-breadcrumbs`, `.tt-back-link-wrap`, page-head actions, `.tt-mp-toolbar`) and every overlay (`.tt-mp-picker(-backdrop)?`, `.tt-mp-drawer(-backdrop)?`) so only the spreadsheet renders on paper. Selectors verified against the live markup rather than guessed. Forces the 3-column grid on regardless of print viewport width. Pitch tints, panel-head shading, and "on pitch" green cells preserved via `print-color-adjust: exact`. `break-inside: avoid` on each player row, goal box, and set-piece row prevents page-break splits.

**(3) Save-indicator layout shift** — `.tt-mp-save-state` gains `min-height: 1.4em`, `min-width: 12ch`, `display: inline-flex` so its bounding box stays stable while the textContent toggles between dirty / saving / saved / empty. Pure CSS defence; the JS textContent flip is unchanged.

**(4) Print button** — replaces the toolbar's `<a href="?tt_view=exports&exporter=match_prep_pdf&...">PDF (landscape A4)</a>` with a `<button type="button" data-tt-mp-print>Print (landscape A4)</button>` plus a one-line `window.print()` handler in `frontend-match-prep.js`. The `$pdf_url = add_query_arg([...])` block in `FrontendMatchPrepView::render()` is removed. The browser's "Save as PDF" within the print dialog handles file-output for free. The exports page's match-prep PDF exporter route stays available for direct visits to `?tt_view=exports`. Dutch string `Afdrukken (liggend A4)`.

## Files touched

- `assets/css/frontend-match-prep.css` — wrapper widening, grid column widths, save-state stability, print block.
- `assets/js/frontend-match-prep.js` — `data-tt-mp-print` click handler.
- `src/Modules/MatchPrep/Frontend/FrontendMatchPrepView.php` — PDF anchor → Print button; drop unused `$pdf_url`.
- `.local-mockups/match-preparation/index.html` — mirror the changes (mockup is design-of-record).
- `languages/talenttrack-nl_NL.po` — add `Print (landscape A4)` → `Afdrukken (liggend A4)`.
- `languages/talenttrack.pot` — add the same `msgid`.
- `docs/match-prep.md` + `docs/nl_NL/match-prep.md` — rewrite "Print to PDF" section to describe browser-print flow.
- `talenttrack.php` + `readme.txt` — version bump to 4.12.4, changelog stanza.

No schema, no REST, no behavioural change beyond the four items above.

---

# TalentTrack v4.12.3 — Vocabulary constants for goals + tasks (PR-set 2 of #988)

Second of eight PR-sets in the umbrella migration of #988 (~131 hardcoded vocabulary string literals -> typed constants under `TT\Domain\Vocabularies\*`). PR-set 1 (attendance + activity) shipped in v4.11.1; this ship covers the goal-side workflow vocabularies. Same architectural pattern, same backward-compat allowlist, same patch-bump rhythm.

## What ships

**PHP - new vocabulary classes**

- `src/Domain/Vocabularies/Lookups/GoalStatus.php` (new) — six lowercase snake_case constants for `tt_goals.status`: `PENDING`, `PENDING_APPROVAL`, `IN_PROGRESS`, `COMPLETED`, `ON_HOLD`, `CANCELLED`. Mirrors the PR-set 1 file shape (`const ALL` + static `isValid()`). The lowercase snake_case form is the canonical stored value per `LabelTranslator::goalStatus()` and the REST controller's defaults; the `goal_status` lookup row `name` column carries the TitleCase display label, but the table is the operator-facing surface and unaffected here.
- `src/Domain/Vocabularies/Lookups/GoalPriority.php` (new) — three lowercase constants for `tt_goals.priority`: `LOW`, `MEDIUM`, `HIGH`.
- `src/Domain/Vocabularies/Lookups/GoalApprovalDecision.php` (new) — three constants for the approval-form decisions stored in `tt_workflow_tasks.response_json`: `APPROVE`, `AMEND`, `REJECT`. Backs the `goal_approval_decision` lookup seeded by migration 0111.

**PHP - literal -> constant replacements**

- `src/Infrastructure/REST/GoalsRestController.php` — replaces the five raw `'pending_approval'` / `'pending'` literals (default status on create, force-approve gate for player-self-create, status update authorization check) and the `'medium'` priority default with the new `GoalStatus::*` / `GoalPriority::*` constants. REST endpoint payload-side behaviour is unchanged; the stored values are byte-identical to the previous release.
- `src/Modules/Goals/Admin/GoalsPage.php` — replaces the `'pending'` and `'medium'` form-default literals (status / priority dropdown `selected()` calls + the `handle_save` `$_POST` fallback) with the new constants.
- `src/Modules/Development/Notifications/GoalSpawner.php` — the idea-promotion goal materialisation hands `'pending'` / `'medium'` to `wpdb::insert(tt_goals)`; switched to the constants.
- `src/Modules/Workflow/Forms/GoalApprovalForm.php` — `DECISION_APPROVE` / `DECISION_AMEND` / `DECISION_REJECT` class constants now alias `GoalApprovalDecision::APPROVE` / `::AMEND` / `::REJECT` rather than carrying duplicate raw strings. Backward compatible: every existing internal caller continues to compile and produce the same stored decision value. The aliases stay one release before the umbrella's PR-set 8 PHPStan rule lands.

**Out of scope for this PR-set**

- `TT\Modules\Workflow\TaskStatus` already follows the constants-shaped pattern from the original v3.x ship; it carries the canonical six values (`open`, `in_progress`, `completed`, `overdue`, `skipped`, `cancelled`) plus helpers `isActionable()` and `label()`. Consolidating it into `Vocabularies\Lookups\TaskStatus` is a mechanical lift but pulls in two more touch points (`TasksRepository`, `FrontendMyTasksView`, `FrontendTaskDetailView`); deferred to keep this PR-set focused on the *new* constants classes. The existing class continues to be the source of truth for the task-status vocabulary in the meantime.
- SQL string literals, `tt_lookups` seed values, .po / .pot files, test fixtures, and JavaScript stay as literals per the umbrella's locked plan.

## Why patch

PR-set 2 of 8 in a refactor umbrella. No new feature, no behaviour change, no schema migration. The constants are byte-equivalent to the literals they replace; the REST endpoints continue to accept BOTH the raw literal AND the new constant for one release (per #988's backward-compat allowlist) so external integrations do not break. The PHPStan rule (#988 PR-set 8) that will forbid raw literals is deferred until the allowlist drops in a subsequent minor.

## Test plan

- Coach creates a goal via the goals admin: defaults to `priority=medium`, `status=pending`. (Both stored as the lowercase form, unchanged from previous behaviour.)
- Player creates a goal via the player-self-create flow: stored with `status=pending_approval` regardless of payload override.
- Coach approves a pending-approval goal via the inline status dropdown: head-coach-only gate fires; status moves to `pending`.
- Coach uses the workflow goal-approval form: each `approve` / `amend` / `reject` decision serializes to the same byte value as before.
- Idea promoted to in-progress: spawns a `tt_goals` row with `status=pending`, `priority=medium`.

