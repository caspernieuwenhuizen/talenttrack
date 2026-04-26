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
