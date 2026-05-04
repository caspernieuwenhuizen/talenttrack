<!-- audience: admin -->

# Teams & players

## Teams

A **team** is a squad at a specific age group (e.g. "U13 Blue", "U15 Red"). Each team has:

- A name and optional age group label
- A head coach (from your **People** roster)
- Assigned players

Create teams in the **Teams** admin page. The age group field matters because [category weights](?page=tt-docs&topic=eval-categories-weights) are defined per age group.

### The team page

Clicking a team — from the Teams list, a player profile, or anywhere a team name is linked — opens the team's own page. It shows:

- **Header**: team name, age-group pill, head coach.
- **Notes**, if any are set.
- **Roster**: read-only player list (jersey, foot). Each player links to their own page.
- **Staff**: the people assigned to the team via Functional Roles.
- **Edit team** button (top-right) — only visible if you have the team-edit capability. Click it to open the management form below.

### The team edit page at a glance

The Edit form is reached by the **Edit team** button on the team page (or the "Edit" row action in the Teams list for users with the cap). It shows three blocks:

1. **Team details** — name, age group, head coach, notes, custom fields.
2. **Staff Assignments** — the people working with this team (coaches, assistants, physio, etc.). Add/remove assignments here.
3. **Players on this team** — the current roster in a sortable table with jersey, positions, foot, date of birth. Each row links to the player's own page. A "Add player to this team" button is at the top.

## Players

A **player** is an individual footballer. Each player has:

- First and last name
- Position(s), preferred foot, jersey number
- Height, weight, date of birth
- Optional link to a WordPress user account (so they can log in)
- Custom fields your academy has configured

Create players in the **Players** admin page. Use the **+ Add new** button.

## Linking player to WordPress user

When a player has a `wp_user_id` set, logging in as that user routes to their own dashboard view on the frontend shortcode. Without the link, the player exists only as a record you can evaluate.

## Archiving vs deleting

Archived players stay in the database but disappear from active lists (old evaluations still reference them). Permanent delete only works when no evaluations, goals, or sessions reference the player. Use **archive** in most cases — see [Bulk actions](?page=tt-docs&topic=bulk-actions).

## Player case page (v3.79.0)

Player detail is a six-tab case page: Profile / Goals / Evaluations / Activities / PDP / Trials. Each tab shows up to 50 records (25 for activities, 10 for PDP/Trials), every record links through to its detail surface, and breadcrumbs replace the standalone back link.

## Player file UX (v3.92.6 — #0082)

The player file got a hero-card redesign and per-tab empty-state CTAs.

- **Hero card.** Photo (or initials placeholder when no photo is uploaded) sits next to a structured info block: team and age group, status pill, age-tier badge, days-in-academy + joined-on date, and up to three "latest record" chips that link straight to the most recent activity, evaluation, and goal. The chips are dropped when the corresponding record doesn't exist; the whole latest-row hides when there's nothing to show. Stacks at 360px width, side-by-side at 480px and up.
- **Empty-state CTAs.** When a tab has no records, instead of an italic "No goals recorded yet" line you now get a centred card: icon, headline, one-sentence explainer, primary action button. The button pre-fills the player and routes to the wizard variant where one exists (flat form otherwise). Read-only viewers (scout / parent / a player on their own file) see the headline + explainer but no button — the create action is suppressed because they don't have the cap. The Activities empty state explains that activities are recorded at the team level; the CTA is suppressed when the player has no team assigned and replaced with "Assign this player to a team first".
- **Tab count badges.** Each non-Profile tab shows a small badge with its record count (Goals 12, Evaluations 4, Activities 38, etc.). Tabs with zero records render in a muted colour so an operator can scan the row and pick the populated tabs without clicking through every empty one.
- **Profile tab two-column layout.** At ≥ 768px the Profile tab now splits into Identity (DOB, position, foot, jersey, status) on the left and Academy (team, age tier, date joined) on the right. Single column on mobile.

## Team detail — trial roster (v3.79.0)

The team detail page now shows current trial players under their own **Trial players** subsection. They were previously hidden behind the active-status filter on the team roster.

## Edit cap path (v3.79.0)

The team-detail edit button and the Teams REST endpoints (list / get / create / delete) now consult `AuthorizationService::userCanOrMatrix` rather than `current_user_can`. This means a Head of Development granted `tt_edit_teams` via the matrix scope-row layer (functional role bridge) passes the gate too, matching the pattern already used by Tile gating and the Activities REST endpoints.
