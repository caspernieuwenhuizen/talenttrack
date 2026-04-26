<!-- audience: user, admin -->

# Player rate cards

A rate card is a one-glance view of a player's current standing and recent trajectory: FIFA-style card, headline numbers (most recent, rolling average, all-time), radar chart across main categories, and a trend line over time.

## Where to find it

**Admin**: `TalentTrack → Player Rate Cards`. Player picker at the top; pick a player to see their card with optional date-range and evaluation-type filters.

**Frontend (v3.0.0+)**: the **Rate cards** tile on the tile landing page. Tap it to get the same functionality in a mobile-first layout. Anyone with `tt_view_reports` can access this — Observer, Coach, Scout, Club Admin, Head of Development.

## What it shows

- **FIFA-style card** — positional color, overall rating, main attribute values, name, team, photo
- **Headline numbers** — Most recent rating (single latest eval), Rolling (average of last 5 evals), All-time average, evaluation count
- **Radar chart** — main categories laid out as a spider web, showing the player's profile shape
- **Trend line** — rolling averages plotted over time; longer bars of flat progress vs dips and climbs tell development stories at a glance

## Filters

On both admin and frontend:

- **Date from / to** — restrict to evaluations within a time window
- **Evaluation type** — e.g. only Matches, or only Training, or both

Filters apply across all four panels consistently.

## Frontend vs admin

The frontend version uses the same rendering class internally — no feature gap. The difference is chrome: the admin has the standard WP admin frame with tabs and breadcrumbs, frontend has the tile-landing header with a Back button. Both show identical content below the picker.

## Observer role

The primary reason this tile exists on the frontend. Read-Only Observers (board members, assistant coaches in training, external reviewers) can now browse every player's rate card without needing admin access. Their role grants view access across the entire plugin; this tile is their everyday entry point.
