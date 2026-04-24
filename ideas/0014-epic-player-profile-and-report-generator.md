<!-- type: epic -->

# Player profile — fix frontend bug, rebuild the view, add scoped report generator

Raw idea:

Review and improve the player profile page. It doesn't look right and gives an error from a player frontend perspective. Also, as scout or perhaps as head of development, I want to be able to generate a player profile/report based on a wizard that allows for scope and target choices. For example a monthly printable report to hand out, or a more detailed report in case a scout or a more professional club asks for player details. Finally, also a player themselves should be able to have a non-standard version of their report/profile in case they want to show it to friends or something.

## Why this is an epic

Three distinct streams — an urgent bug fix on the existing frontend view, a visual/UX rebuild of that view, and a new multi-audience report generator with wizard-driven scoping. Each is sprint-sized; together they're an epic because they share underlying concepts (what goes on a profile, how sensitive each field is, who gets to see what) that are worth designing together rather than drifting apart.

## Part 0 — Urgent bug (ship separately as `bug` type)

Before anything else in this epic, there's a real bug in `FrontendMyProfileView::render()`:

- Line 28 calls `QueryHelpers::get_team( $player->team_id )`. There is no `get_team` method on `QueryHelpers` — that class only defines `player_display_name()`. So for any player who actually belongs to a team (the common case), this view throws a fatal "Call to undefined method TT\Infrastructure\Query\QueryHelpers::get_team()". Players with no team see the page fine. **This explains "gives an error from a player frontend perspective."**
- Line 51 does `! empty( $team->age_group ?? '' )` where `$team` may be null — not a fatal in PHP 8 thanks to `??`, but triggers a deprecation/warning on some configurations and can render into the page if `WP_DEBUG_DISPLAY` is on. Easy fix: move the age-group block inside the existing `if ( $team )` on line 46.

**This fix should be logged as its own `bug`-type idea and shipped independently**, not bundled into this epic. Broken-for-every-rostered-player is a "fix today" priority. The rebuild below is "fix properly next sprint."

Fix shape: replace `QueryHelpers::get_team(...)` with a direct query (or add the method — there are almost certainly other callers waiting for it), and move the age-group conditional inside the team-null check. Line-for-line ~10 lines changed.

## Part A — Rebuild the "My profile" frontend view

The current view (`FrontendMyProfileView`) is functional-but-spartan: a circular avatar, a "Playing details" dl, an "Account" dl with a button to edit WP profile. Inline styles everywhere (harder to theme / brand later). No visual hierarchy beyond card containers. No stats, no rating, no team context beyond a name.

### What the player probably wants to see when they open "My profile"

- Who they are (photo, name, team, age group, position).
- A quick summary of how they're doing (rolling rating, trend arrow, recent evaluation count). The plugin already computes all of this via `PlayerStatsService` for the rate card — it just isn't on the profile page.
- Current goals in one glance (2–3 active goals with status).
- Upcoming sessions (next 2–3).
- A way to edit the things they *can* edit (display name, email — via WP profile) clearly separated from the things a coach controls.

The existing view covers (a) and the edit link. Everything else is missing. Adding it turns the profile into something players might actually open more than once.

### Visual direction

Keep it a read-focused personal homepage, not a dashboard. Don't bolt on twenty tiles. Propose:

- **Hero strip** — photo + name + team + age group + current tier (gold/silver/bronze from `PlayerCardView`'s logic). Photo optional, graceful placeholder when absent.
- **"Playing details" card** — exactly what the current view has, minus the bug. This is the canonical cold-hard-facts block.
- **"Recent performance" card** — rolling average + sparkline of last N evaluations + trend arrow. All data already available via `PlayerStatsService`.
- **"Active goals" card** — top 3 active goals, each with a small progress indicator.
- **"Upcoming" card** — next 2–3 sessions, showing date/time/location.
- **"Account" card** — display name, email, edit-on-WP button. Unchanged from today.

Pull styles out of inline attributes into `assets/css/frontend-profile.css` so the branding module can theme them. Pattern that matches `assets/css/player-card.css` which is already doing this for the FIFA card.

### Scope of Part A

New or changed files:
- `src/Shared/Frontend/FrontendMyProfileView.php` — rewrite (keep the class name and signature, swap the internals).
- `assets/css/frontend-profile.css` — new.
- No schema changes. All data already flows through existing services.

## Part B — The report generator (wizard-driven, multi-audience)

This is the substantive new feature. The raw idea calls out three distinct audience modes, and they really are different:

1. **Monthly printable summary** (hand out to parents, put on a clipboard). Short. One page. No confidential data. Warm tone.
2. **Scout / external-club detail report** (another club is asking for this player). Comprehensive. Multi-page. Includes all evaluation history, goals, attendance, maybe even contact details. Formal tone. Potentially sensitive — see privacy below.
3. **Player's own "show my friends" version** (the player wants a nice-looking takeaway). Fun. Not clinical. Can omit anything the player doesn't want shown (e.g., areas for improvement, raw weak ratings).

Each audience has different field sets, different tones, and crucially different *permission models*. The wizard's job is to walk the generator through those choices.

### The existing `PlayerReportView`

There's already a `PlayerReportView` that produces an A4 printable combining the rate card + FIFA card via `?print=1`. **Don't replace it — extend it.** The new wizard is a layer on top that produces a `ReportConfig` object, and `PlayerReportView` (renamed to something like `PlayerReportRenderer` and generalized) consumes that config to decide what to include. This way:

- The simple `?print=1` flow still works (defaults to the Standard template).
- The new wizard becomes the way to produce anything non-default.
- One rendering engine, multiple configurations — no duplicated layout code.

### The wizard — four questions

| Step | Question | Options (example) |
| --- | --- | --- |
| 1. Audience | Who is this report for? | Parent (monthly) / External scout / Player personal / Internal coaches |
| 2. Scope | What time window? | Last month / Last season / Year-to-date / All time / Custom range |
| 3. Content | Which sections? | Profile, Ratings, Goals, Sessions, Attendance, Coach notes (pre-selected based on audience, user can override) |
| 4. Privacy | Any fields to omit? | Contact details / Date of birth / Evaluations with ratings below threshold / Free-text coach comments |

Each audience choice sets sensible defaults for steps 2–4, which the user can override. A scout report defaults to "all time" + everything on; a monthly parent report defaults to "last month" + everything except raw evaluations; a player's personal version defaults to showing only strengths, no explicit weak-area callouts.

### Permissions model — who can generate what

| Role | Parent / monthly | Scout / detailed | Player personal | Internal |
| --- | --- | --- | --- | --- |
| Head of Development (`tt_head_dev`) | ✓ | ✓ | ✓ | ✓ |
| Coach (`tt_coach`) | ✓ own team | – | ✓ own team | ✓ own team |
| Scout (new role — see below) | – | ✓ | – | – |
| Player (`tt_player`) | – | – | ✓ self only | – |
| Staff (`tt_staff`) | – | – | – | – |

**The plugin does not currently have a scout role.** The raw idea asks for one; adding it is a sub-task here. Matches the existing role pattern in `Activator.php`. Capability: `tt_generate_scout_report`. Restricted to read-only access scoped to specific players a head-of-development has explicitly released for scouting (see below).

### Scout access — the not-obvious-but-important bit

"As a scout I want player details" opens a can of worms about who can see what. If every scout with a login sees every player, that's a data-protection problem (GDPR, minor athletes, the whole thing). The safer model:

- A scout role exists but has **no access to the player list by default**.
- Head of Development explicitly "releases" a player's profile to a specific scout — either via an emailed one-time link (non-authenticated, single report, expiring), or via assigning a scout user to the player.
- The emailed-link flow is the practical one: clicking the link retrieves a pre-generated PDF/HTML report for that specific player, from that specific date, expires after N days. No account needed on the scout side.
- The wizard has a "Generate & send by email" option in the scout-audience path.

This is a meaningful design choice. Flagging as an open question — could go simpler (internal-only feature, no external scout flow yet) or richer (full scout accounts with scoped access).

### Output formats

All report variants render to both:
- On-screen HTML (for review before sending / printing).
- Printable version via the browser's print dialog (leveraging what `PlayerReportView` already does).

True PDF generation (server-side, without the print dialog) would be nicer but adds a dependency (Dompdf, mPDF, or a headless Chrome service). The existing approach of "render HTML, let the browser print-to-PDF" works and is what `PlayerReportView` uses today. Keep it.

### Tone differentiation

Audience sets the tone, not just the content. Examples:

- Parent version: "Max's strong areas this month were passing and positioning. He's working on finishing."
- Scout version: "Strengths (last 12 months, rolling-5 avg): Passing 4.2 (↑), Positioning 4.1 (→). Development areas: Finishing 2.8 (↑ from 2.3). 14 evaluations across 6 evaluators."
- Player-friendly version: "Top attributes this season" with big visual ratings, no weak-spot section.

Achievable with a small set of templates per audience, not a full templating engine. Each audience gets its own `renderHeader()`, `renderSummary()`, `renderFooter()` methods on the renderer.

## Part C — Privacy / GDPR considerations

Both the frontend view and the report generator handle personal data about people who are often minors. Worth calling out explicitly:

- **Contact details** (phone, email, address) — never included in parent/player variants. Scout variant includes only if head-of-development explicitly opts in per report.
- **Date of birth** — included as age for parent/scout variants, as full DOB only if explicitly checked in step 4 of the wizard.
- **Photos** — omittable. Some parents don't want their kid's photo on an external report.
- **Coach free-text comments** — often the most sensitive field. Never in scout or parent variants by default. Player's personal version: shown only if positive sentiment, which is a judgement call — easier to just omit.

This intersects with idea #0011's privacy policy work and idea #0013's backup-contains-PII warning. Same theme, different surfaces.

## Decomposition / rough sprint plan

1. **Sprint 1 (bug-level urgency) — the 10-line fix.** Logged as a separate `bug` idea. Ship ASAP. Unblocks the frontend for every player on a team.
2. **Sprint 2 — Part A (profile rebuild).** New hero strip, recent performance, goals, upcoming sessions. Pull styles to a CSS file. All data sources already exist.
3. **Sprint 3 — Part B.1 (generalize `PlayerReportView` into a configurable renderer).** Introduce `ReportConfig`. Prove it by regenerating the existing "Standard" report via the new config. No new output yet — this is plumbing.
4. **Sprint 4 — Part B.2 (wizard + audience templates).** Four-step wizard, three initial templates (parent monthly, internal detailed, player personal). Role-gated.
5. **Sprint 5 — Part B.3 (scout flow).** New `tt_scout` role + capability. "Release to scout" action on a player. Email-link external report with expiry. This sprint is only half about code — half is about writing the privacy copy and deciding who can release.

## Open questions

- **Scout flow depth.** Option A: internal scout accounts with explicit player assignment. Option B: emailed one-time links, no scout account. Option C: both. Simpler to start with B only — zero new user accounts, fewer permissions to manage, the scout's experience is just "click a link, see a nicely-formatted report." A and C are later.
- **Is "Monthly" a fixed cadence or just the default scope for the parent audience?** Probably the latter — the wizard lets the user pick any range. "Monthly" is marketing shorthand for "shortish, recurring-cadence" reports.
- **Does the rebuild include the player's FIFA card prominently on "My profile", or is the card a thing the player sees elsewhere (overview, team view) and the profile is intentionally the un-gamified version?** Arguable either way. My instinct: tier badge in the hero strip, no full card on the profile. The profile is for personal details; the card lives on the overview where it belongs.
- **Does the existing `tt_readonly_observer` role (mentioned in the readme v2.21.0 but not actually in `Activator.php`) overlap with the proposed `tt_scout` role?** Worth checking before adding yet another role. If observer is the right semantic fit, extend it.
- **PDF vs HTML-print.** Keep HTML-print for now (matches `PlayerReportView`). Revisit if scouts start complaining that emailed links render inconsistently across browsers. Dompdf is the obvious add if so.
- **Photo handling in scout reports.** The plugin uses `photo_url` — a URL reference, not a stored file. If a scout receives an emailed link and the site's photo hosting is behind auth, images break in the report. Need to either inline photos as base64 in the generated HTML, or ensure `photo_url` points at a publicly-accessible location. Inlining is safer.
- **Report persistence.** Does the generated report get saved anywhere (so a head-of-dev can see "I generated 3 scout reports for this player last year")? Strong hint: yes. Small table `tt_player_reports` with report ID, player ID, audience, generated by, generated at, expiry (for scout links), revoked-at. Useful for audit and for re-sending an expired link.

## Touches

### Part 0 (urgent bug)
- `src/Shared/Frontend/FrontendMyProfileView.php` (the fix, ~10 lines)
- Possibly `src/Infrastructure/Query/QueryHelpers.php` if adding `get_team()` rather than inlining the query

### Part A (profile rebuild)
- `src/Shared/Frontend/FrontendMyProfileView.php` (rewrite)
- `assets/css/frontend-profile.css` (new)

### Part B (report generator)
- Rename/generalize: `src/Modules/Stats/Admin/PlayerReportView.php` → `PlayerReportRenderer.php` with `ReportConfig` input
- New: `src/Modules/Reports/Admin/ReportWizardPage.php`
- New: `src/Modules/Reports/ReportConfig.php`
- New: `src/Modules/Reports/Audiences/` — `ParentAudience.php`, `ScoutAudience.php`, `PlayerPersonalAudience.php`, `InternalAudience.php`
- New role and capability: `tt_scout` + `tt_generate_scout_report` in `includes/Activator.php`
- New schema (optional, tied to report persistence open question): `tt_player_reports` table
- New endpoint for external scout links (signed, expiring): `REST/ScoutReportController.php`
- `assets/css/report-print.css` — shared report print styles (probably already partially exists via `PlayerReportView`)
