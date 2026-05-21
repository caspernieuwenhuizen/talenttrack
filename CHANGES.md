# TalentTrack v4.0.6 — Person detail page renders profile + teams as tables (closes #876)

## Pilot report

> on person page: person detail is not in a table but in bulleted list

## Root cause

`FrontendPersonDetailView` was the one detail page that hadn't been swept to the established `<table class="tt-profile-table">` pattern. Two blocks needed converting:

1. **Profile fields** (Role / Email / Phone / Status) — rendered as `<dl class="tt-profile-dl">`. Stacked label-above-value, no separators, reads as a bulleted list.
2. **Teams section** — `<ul class="tt-stack">` with explicit `<li>` bullets. The functional role hung off the team name via a `&middot;` separator.

Compare:

- **Player detail** uses `renderProfileTable()` rendering `.tt-profile-table` since v3.110.95+.
- **Team detail** uses inline `<table class="tt-profile-table">` since the same release.

So this was a consistency gap, not a fresh design call.

## Fix

1. **Profile block**: `<dl>` → `<table class="tt-profile-table">` with a `<tbody>` of `<tr>` rows. Each conditional `<dt>/<dd>` pair became `<tr><th scope="row">…</th><td>…</td></tr>`.
2. **Teams block**: `<ul>` → `<table class="tt-profile-table">` with a `<thead>` (Team / Functional role) and a `<tbody>` row per team. The `&middot;` separator is gone — the second column does its job. Empty Teams section still hides correctly.
3. **Stylesheet** — `frontend-player-detail.css` now enqueued from the Person view so `.tt-profile-table` styles apply. Only stylesheet in the repo defining the class.

## Files touched

- `src/Shared/Frontend/FrontendPersonDetailView.php` — render rewrite + new `wp_enqueue_style` call.
- `talenttrack.php` + `readme.txt` + `CHANGES.md` — version bump.

No CSS file changes. No data-shape change. `PeopleRepository::getPersonTeams()` returns the same row shape.

## How to test

1. Open `?tt_view=people&id=N` for a person who has a role, email, phone, status, and at least one team. Profile fields render in a table; Teams in a 2-column table with functional-role per row.
2. Open the same view for a person with no teams. Teams section is hidden (no empty table).
3. Resize to 360px — table fits without horizontal scroll (same behaviour Player/Team pages already had).
4. Visually compare with the Team detail page — identical typography.

## Why patch (not minor)

Visual consistency fix, no new behaviour, no schema change. Per the v4.0.0 SemVer rule: patch.
