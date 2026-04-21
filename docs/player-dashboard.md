# Player dashboard (frontend)

Players logged into the frontend shortcode see a dashboard scoped entirely to themselves.

## Getting there

The `[talenttrack_dashboard]` shortcode. When a player (linked to a WordPress user via their `wp_user_id`) logs in and visits that page, they land on the tile grid with the **Me** section visible.

## Tiles

- **My card** — FIFA-style card with overall rating, most recent evaluation, headline numbers
- **My team** — teammates, team podium, team info
- **My evaluations** — history of ratings from their coaches, with categories and notes
- **My sessions** — training sessions attended (attendance status per session)
- **My goals** — development goals set by their coaches, with status
- **My profile** — edit personal details (name, contact info, profile photo)

## Privacy

Players see **only their own data**. They cannot see other players' evaluations or personal info. Team roster lists teammates' names but not their individual ratings.

## Mobile

The tile grid collapses to 1 column on phones, 2 on tablets. All sub-views are responsive — designed for a player viewing on a phone during or after training.

## What they can't do

Players have `read` only — no `tt_manage_*` or `tt_evaluate_*` capabilities. They can edit their own profile (name, contact) but cannot create evaluations, sessions, or change anything about the team. Even reading other players' pages is blocked at the controller level.
