# Player dashboard (frontend)

Players logged into the frontend shortcode see a tile-based dashboard scoped entirely to themselves. (v3.0.0 — the tile grid now has real destinations; v2.21's tile landing is a legitimate landing page, not just decoration.)

## Getting there

The `[talenttrack_dashboard]` shortcode. When a player (linked to a WordPress user via `wp_user_id`) logs in and visits that page, they land on the tile grid with the **Me** section visible.

## The Me tiles

Each tile drills into a focused sub-view with a "← Back to dashboard" link at the top.

### My card
FIFA-style card with overall rating, main-category radar chart, key attributes, custom-field values, and a **Print report** button for a clean printable version.

### My team
Your own card centered, followed by the team's top-3 podium (strongest current performers) and a roster of teammates (names + photos, no ratings — to protect those not in the top 3).

### My evaluations
Table of every evaluation recorded for you, most-recent first. Shows date, type (Training / Match / etc.), coach, and the ratings given across categories. Match-type evaluations also show opponent and result.

### My sessions
Training session attendance log. Table with date, session title, attendance status (Present / Absent / Late / Excused — color-coded), and any notes.

### My goals
Development goals your coaches have set for you, grouped by status. Each goal card shows title, description, status badge, and due date when set.

### My profile
Your personal details (name, team, age group, positions, foot, jersey, height, weight, DOB) in read-only layout. Maintained by your coaches — contact them for corrections. Also a link to edit account settings (display name, email, password) via WordPress.

## Privacy

Players see **only their own data**. They cannot see other players' evaluations or personal info. Team roster lists teammates' names but not their individual ratings.

## Mobile

The tile grid collapses to 1 column on phones, 2 on tablets. All sub-views are responsive — designed for a player viewing on a phone during or after training.

## What players can't do

Players have `read` only — no `tt_manage_*` or `tt_evaluate_*` capabilities, and no `tt_edit_*` caps. They can edit their own WordPress account (display name, email, password) but cannot create evaluations, sessions, or change anything about the team. Even reading other players' pages is blocked at the controller level.
