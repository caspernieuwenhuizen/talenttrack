# Standard reports — shared notes (#1063)

12 report mockups split into 6 CURATED (full responsive views with
chrome) and 6 PRESET (single-screen explorer-config snapshots).

## Directory map

CURATED (one mockup per directory, each with `index.html` + `notes.md`):

- `player/minutes-played/` — maps to #1034
- `team/minutes-distribution/` — squad load balance
- `team/squad-evaluation-summary/` — per-player × per-category matrix
- `season/season-summary/` — HoD annual-review multi-zone
- `season/trial-funnel/` — funnel viz per scout / period
- `scout/scout-report-card/` — per-scout dashboard

PRESETS (single HTML each, snapshot of `?tt_view=explore` with
specific filter/grouping state pre-set):

- `player/evaluations-received.html`
- `player/goal-progress.html`
- `team/activity-volume.html`
- `activity/evaluation-coverage.html`
- `activity/attendance-vs-squad.html`
- `season/prospect-logging.html`

## Cross-surface tokens

Inline CSS variables on each mockup:

```
--tt-ink:        #1a1d21
--tt-ink-soft:   #5b6e75
--tt-paper:      #ffffff
--tt-bg:         #f5f7f6
--tt-line:       #d6dadd
--tt-mute:       #f0f3f2
--tt-accent:     #1d7874
--tt-warn:       #c75c1f
--tt-success:    #2e7d4f
--tap-min:       48px
```

## Filter bar

Same shape across every curated view: pill-row of `Team` /
`Season` / `Date range` / `Player` (where applicable). Mobile
stacks; tablet+ lays them horizontally.

```html
<div class="tt-rep-filters">
    <label class="tt-rep-filter">
        <span class="tt-rep-filter__label">Team</span>
        <select>…</select>
    </label>
    …
</div>
```

## Table convention

`<table class="tt-rep-table">` with sticky `<thead>` on tablet+ for
long lists. First column anchored (player name, date, etc.); right-
align all numerics; tabular-numbers font-variant for alignment.

## Empty state

Same pattern as activity-list: centred placeholder with title
("Geen gegevens voor deze selectie") + sub-line + a CTA back to
the filter bar ("Pas een filter aan").

## Drill / export / schedule CTAs

Top-right of every curated view:

- **Explore →** opens `?tt_view=explore` with the report's facts
  prefilled (this is the "preset" path baked into curated views).
- **Exporteer (CSV)** — downloads.
- **Plan (e-mail / dashboard tile)** — schedule the report.

PRESETS render only the explorer chrome — no separate header /
filter bar (the explorer's own filter bar is the chrome).

## Date-bucket grouping

Timeline-shaped reports (minutes per match, evaluations per month)
use the same date-bucket pattern as `.local-mockups/activity-list/`:
`<div class="bucket-head">` rows separating chronological groups.

## Empty / loading states

- Loading: skeleton-row strip (light grey blocks) for 800ms before
  the table renders. Production via JS, mockup stubs as static.
- Empty: see "Empty state" above.
- Error: tinted warn-orange banner with retry button.

## Mobile-first

Every curated mockup renders cleanly at 360px. Table layout
collapses to per-row cards on narrow viewports (≤480px). Charts
fall back to a numeric table on phones if the SVG would be too
small to read.
