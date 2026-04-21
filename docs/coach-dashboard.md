# Coach dashboard (frontend)

Coaches and administrators logged into the frontend shortcode see a tile-based dashboard with the Coaching section visible. (v3.0.0 — full rebuild from the v2.x tab-based UI.)

## Getting there

The `[talenttrack_dashboard]` shortcode. When a coach (someone with `tt_edit_evaluations`) or an admin (someone with `tt_edit_settings`) visits that page, they land on the tile grid with the **Coaching** section visible. Coaches who are also linked to a player record additionally see the **Me** section.

## The Coaching tiles

### Teams
Each team the coach has access to, shown with its top-3 podium and full roster of FIFA-style mini-cards. Tap any card to drill into the player detail.

### Players
Flat list of every player across the coach's teams (or all players for admins). Grouped by team. Tap any card to drill into the player detail view — FIFA card, player facts, custom-field values, and the recent-history radar. Has its own "← Back to players" link that returns to the list, separate from the tile-landing back button.

### Evaluations
Evaluation-submission form with match-details section that shows only when an evaluation type requires it. All rating categories from your club configuration with min/max/step from your rating scale. AJAX-submitted — success message appears inline, no page reload.

### Sessions
Training session recording. Title, date, team, location, notes. Attendance matrix lists every player on the coach's teams with a status dropdown (Present / Absent / Late / Excused) and a notes field. AJAX-submitted.

### Goals
Dual form: add a new goal (player picker, title, description, priority, due date) on top, current goals table below with inline status dropdowns and delete buttons.

### Podium
Aggregated podium view — top-3 of every team the coach has access to. Visual focus, no forms.

## For admins

Admins see every team, every player, every evaluation. The forms also show an "all players" picker instead of being restricted to the coach's teams.

## For the Read-Only Observer

Observers see admin-side pages but none of the Coaching tiles on the frontend (they have no `tt_edit_*` caps). Analytics-group tiles (Rate cards, Player comparison) are their frontend entry point — that's slice 5.

## Mobile

The tile grid collapses responsively. All forms and tables use `frontend-mobile.css` for reasonable scaling on phones. Longer lists (players, evaluations) scroll horizontally when needed rather than cramming into narrow columns.

## Back navigation

Every tile destination shows a "← Back to dashboard" link at the top that returns to the tile landing page. The player-detail view inside Players has its own "← Back to players" link instead, so drilling card-to-card in the roster doesn't bounce you all the way out.
