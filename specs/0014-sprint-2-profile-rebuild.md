<!-- type: feat -->

# #0014 Sprint 2 — Part A: Profile rebuild

## Problem

The player-facing "My profile" view (`src/Shared/Frontend/FrontendMyProfileView.php`) is functional-but-spartan. After the fatal bug from #0015 is fixed, what's left is: a circular avatar, a "Playing details" definition list, an "Account" block with an "edit WP profile" button. All data rendered inline-styled. No visual hierarchy beyond card containers. No stats, no rating, no team context beyond a name, no goals, no upcoming sessions.

A player opens it once, sees nothing worth coming back for, and doesn't come back. The whole surface underperforms its potential.

Who feels it: every player who visits their dashboard. Parents who glance over their kid's shoulder. A player who gets invited to open the app by a coach — their first impression of the plugin's player-facing side.

## Proposal

A rebuild of the view with five purpose-built sections, each with data that already exists:

1. **Hero strip**: photo + name + team + age group. Below/beside: the FIFA-style player card (embedded, not linked) — it's the most engaging element and players expect it.
2. **Playing details card**: exactly what's there today (position, jersey, height/weight, etc.) — cold-hard-facts block, minus the bug from #0015.
3. **Recent performance card**: rolling average rating + sparkline of last N evaluations + trend arrow. All computed by the existing `PlayerStatsService`.
4. **Active goals card**: top 3 active goals, each with a small progress indicator and due-date.
5. **Upcoming card**: next 2–3 sessions with date, time, location.
6. **Account card**: display name + email + "edit on WordPress" button. Unchanged from today.

Plus: pull inline styles out into `assets/css/frontend-profile.css`, following the pattern already established by `player-card.css`.

## Scope

### View rebuild

Surface: `src/Shared/Frontend/FrontendMyProfileView.php`.

Keep the class name and render-method signature stable (other things call this). Swap the internals.

Structure (in visual top-to-bottom order):

**Hero strip** (`<section class="tt-profile-hero">`):
- Player photo (graceful placeholder when absent — reuse existing placeholder pattern).
- Name, team name, age group, jersey number.
- Embedded FIFA card via existing `PlayerCardView` (call it directly; no new rendering code).
- Tier badge (gold/silver/bronze) — already computed by `PlayerCardView`, surface prominently.

**Playing details card**:
- Exactly as today: position, preferred foot, height, weight, date joined, etc.
- Data via existing query paths.

**Recent performance card**:
- Rolling average rating (last 5 evaluations — match the rate card's rolling window).
- Sparkline: small inline SVG of the last 10 evaluations' overall ratings. Click to enlarge → scroll to My evaluations.
- Trend arrow: up/down/flat based on rolling-5 vs prior-rolling-5 difference.
- Evaluation count: "Based on 14 evaluations across 6 months."
- All via `PlayerStatsService`.

**Active goals card**:
- Top 3 active goals (status = active, ordered by deadline ascending).
- For each: title, deadline, small progress indicator if the goal has a completion percentage.
- "See all goals" link if the player has more than 3.

**Upcoming card**:
- Next 2–3 sessions for the player's team.
- Date, time, location.
- "See team schedule" link to the full sessions view.

**Account card**:
- Unchanged from today: display name, email, "Edit on WordPress" button.

### CSS extraction

New file: `assets/css/frontend-profile.css`.

- All profile-specific styles live here.
- Uses the CSS variables and tokens from #0019 Sprint 1's frontend-admin.css scaffold.
- Follows the same pattern as `assets/css/player-card.css` (which is already doing this cleanly).
- No inline styles remaining in `FrontendMyProfileView.php`.

### RatingPillComponent reuse

The "Active goals" progress indicator and the "Recent performance" trend display both use visual rating-pill treatment consistent with `RatingPillComponent` (introduced by #0003). If #0003 has shipped before this sprint starts, reuse directly. If not, this sprint introduces the component and #0003 adopts it later.

### Responsive layout

- Desktop (≥960px): hero strip is horizontal (photo+info left, FIFA card right). Other cards are in a 2-column grid below.
- Tablet (640–960px): hero strip stacks vertically. Cards are 2-column.
- Mobile (<640px): everything stacks. Cards are full-width. FIFA card scales to fit.

## Out of scope

- **Coach-view or HoD-view of a player's profile.** Those are different templates.
- **Player self-editing of non-WP fields** (position, height, weight, etc.). Those stay coach-controlled.
- **Photo upload by player.** Players don't upload their own photos today; that stays a coach action via Sprint 3 of #0019.
- **Evaluation detail drill-downs.** Clicking a recent-performance data point goes to the evaluations list view (managed by #0003), not a detail page here.
- **Goal creation or editing by the player.** Read-only on the profile; goal management is a coach action.
- **Notifications** (e.g. "new evaluation added"). Separate concern; not in this sprint.

## Acceptance criteria

### Visual and functional

- [ ] My profile renders all six sections (hero, playing details, recent performance, active goals, upcoming, account).
- [ ] Hero strip includes the embedded FIFA card.
- [ ] Tier badge visible in hero.
- [ ] Recent performance sparkline renders with correct data from `PlayerStatsService`.
- [ ] Active goals section shows up to 3 goals with a "see all" link when more exist.
- [ ] Upcoming section shows up to 3 next sessions.
- [ ] Account section unchanged from today.

### Responsive

- [ ] Mobile viewport (375px): everything stacks, no horizontal scroll.
- [ ] Tablet (640–960px): hero stacks, cards 2-column.
- [ ] Desktop (≥960px): hero horizontal, cards 2-column grid.

### CSS hygiene

- [ ] All profile styles live in `assets/css/frontend-profile.css`.
- [ ] No inline `style="..."` attributes in the rewritten view.
- [ ] Uses CSS variables from #0019 Sprint 1's scaffold.

### No regression

- [ ] The player's stats displayed match what `PlayerStatsService` computes (no parallel calculation).
- [ ] Goals shown are correctly filtered to active + assigned to this player.
- [ ] Upcoming sessions correctly filtered to the player's team, future-dated.
- [ ] No PHP warnings or notices.
- [ ] FIFA card renders identically to how it does on the rate card page.

## Notes

### Sizing

~10–12 hours of driver time. Breakdown:

- View rewrite with all 6 sections: ~5 hours
- CSS extraction + responsive layout: ~3 hours
- FIFA card embed + styling alignment: ~1 hour
- Sparkline implementation (inline SVG): ~1 hour
- Testing across viewports: ~2 hours

### Depends on

- **#0015** (the fatal fix) — must be done first. Without it, the rebuild fights a broken foundation.
- **#0019 Sprint 1** — this sprint relies on the CSS scaffold and the RatingPillComponent conventions. Do not start before Sprint 1 ships.

### Blocks

Nothing directly. Part B sprints (Sprints 3–5 of this epic) can proceed independently of Part A.

### Touches

- `src/Shared/Frontend/FrontendMyProfileView.php` (rewrite internals, keep signature)
- `assets/css/frontend-profile.css` (new)
- `src/Shared/Frontend/Components/RatingPillComponent.php` — consume (or introduce if #0003 hasn't shipped)
- Minimal additions to existing services — reuse `PlayerStatsService`, existing goal queries, existing session queries.

### Design considerations

- **Graceful empty states**: a newly-rostered player with no evaluations yet sees "No evaluations yet — your first review will appear here once your coach completes one." Same for empty goals, empty upcoming.
- **Photo absent**: reuse the existing placeholder pattern (initials in a colored circle, consistent with `PlayerCardView`'s approach).
- **Privacy**: the profile is only visible to the player themselves + coaches/HoD who have access to that player. No change from today.
