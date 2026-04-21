# Coach dashboard (frontend)

Coaches (users with the Coach, Head of Development, or similar evaluative roles) use the frontend shortcode as their daily workspace.

## Getting there

Same `[talenttrack_dashboard]` shortcode as players. The system recognizes the coach role and shows the **Coaching** + **Analytics** tile groups (plus **Me** if they're also linked as a player, plus **Administration** if they also have admin caps).

## Tiles

**Coaching:**
- **My teams** — teams they coach: roster, podium, evaluation summaries
- **Players** — all players they can evaluate
- **Evaluations** — list view + add new
- **Sessions** — log sessions and attendance
- **Goals** — set and update development goals
- **Podium** — team rankings and top performers

**Analytics:**
- **Rate cards** — per-player deep dive (read-only from the frontend)
- **Player comparison** — 4-player side-by-side

## Why the tile pattern matters here

Coaches are often using TalentTrack on a phone during or right after training. The tile grid has tap targets sized for fingers (vs the mouse-sized links of WP admin). Critical actions like "add evaluation" are one-tap-deep from the landing.

## Back navigation

Every tile sub-view has a "← Back to dashboard" link at the top (v2.21.0 frontend back button). Tap it to return to the tile grid. Admin-style breadcrumb trails don't appear in the frontend — the frontend has a flat one-level hierarchy by design.

## What coaches can't do

No access to WordPress admin settings, users, or the TalentTrack Configuration pages. Those require `tt_manage_settings` which coaches don't have. Use the **Go to admin** tile from the Administration group (admin-only) to switch over when needed.
