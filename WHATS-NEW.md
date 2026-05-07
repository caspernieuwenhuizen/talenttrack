<!-- audience: user -->

# What's new in TalentTrack — May 2026 sprint

Quick walkthrough of everything that landed since v3.94.0. Use this as a "where do I click" guide; the per-version detail lives in `readme.txt` and `CHANGES.md`.

The version slot for the operator is **v3.108.0** (released 2026-05-07). Click *Tools → TalentTrack* → version stamp at the top to confirm.

## 1. Big things to look at

### Team Blueprint — match-day + squad plan (#0068, v3.98.0 / v3.100.0)

A persisted, drag-drop lineup tool sitting on top of the team-chemistry pitch.

- **Where**: `?tt_view=team-blueprints`. From the dashboard, look for the new "Team blueprints" tile in the *Performance* group, order 55. Cap-gated on the existing `tt_view_team_chemistry`; Pro-only via the `team_chemistry` license feature.
- **Two flavours**:
  - **Match-day** — single starting XI, single player per slot. Live FIFA-style chemistry score updates as you drag players onto the pitch.
  - **Squad plan** — each slot has primary / secondary / tertiary tiers. A *Trials* divider appears in the roster sidebar (yellow-bordered TRIAL badges). The *Show coverage heatmap* button flips the pitch into a depth-coverage view (red = uncovered → green = full depth).
- **Status flow** draft → shared → locked.
- **Walk through it**: (1) hit *New blueprint*, pick `Match-day` or `Squad plan` in the wizard, (2) drag players onto the pitch, (3) set status `shared` so other coaches can see it.

### Team planner — week calendar (#0006, v3.101.0)

The "what training are we running this week" surface.

- **Where**: `?tt_view=team-planner`. *Performance* group at order 25 (right next to Activities at 20). New caps `tt_view_plan` + `tt_manage_plan` (already granted to anyone who can see/edit activities).
- **Picks up**: a team picker, prev/today/next nav, 7-day grid with activity cards (state pill + top-3 principle chips). Empty days have a `+ Add` link that pre-fills the activities form with the chosen date. Mobile stacks to one column at <720px.
- **Bottom panel**: top-10 principles trained over the last 8 weeks (uses the existing `tt_principles` framework — no parallel store).
- **What's missing**: drag-drop reschedule, month view, inline modal create, nightly cron for `scheduled → in_progress`. All deferred per spec.

### Reporting — fact registry, KPI platform, dimension explorer, analytics tabs (#0083, v3.104.1 → v3.106.1)

Six children shipped in series. Closes #0083 entirely.

- **Per-entity Analytics tab**: open any player's profile (`?tt_view=players&id={n}`) and click the new *Analytics* tab. Shows every player-scoped KPI in one card grid, threshold-flagged red where the value is on the wrong side. Each card click-throughs to the dimension explorer scoped to that player.
- **Central analytics surface**: `?tt_view=analytics`. Cap-gated on `tt_view_analytics` — HoD + Academy Admin only by default. Surfaces every `ACADEMY`-context KPI; click any card to drill down.
- **Dimension explorer**: `?tt_view=explore&kpi={key}`. The drill-down surface — filter chips per `exploreDimension`, group-by selector, a two-column table when grouped. URL state round-trips so a shared link reproduces the view exactly. **Desktop-only** (per #0084 mobile classification — phones see the polite "Open on desktop" page).
- **Scheduled reports**: `?tt_view=scheduled-reports`. License-gated to Standard+. Pick a KPI, frequency (weekly Monday / monthly 1st / season-end 1 July), recipients (emails or role keys), the daily cron renders the CSV and emails it on schedule. *Pause* / *Resume* / *Archive* per row.
- **What's still on the roadmap**: bulk migration of the 26 legacy KPIs to fact-driven declarations, the remaining 49 of the spec's "top 15 per entity" set, time-series charts, drilldown to fact rows, XLSX + PDF formats.

### Mobile experience (#0084, v3.103.2 / v3.104.0)

Three children. Closes #0084.

- **Surface classification**: every `?tt_view=` slug now declares one of `native` / `viewable` / `desktop_only`. Phone-class user agents on `desktop_only` slugs see a polite "Open on desktop" page with *Email me the link* / *Go to dashboard* / *Show it anyway* (`?force_mobile=1` escape hatch).
- **Pattern library**: four CSS components — bottom-sheet, sticky CTA bar, segmented control, list-item — auto-loaded on `native` surfaces only. Conditional enqueue keeps the desktop bundle slim.
- **Sticky wizard CTA**: the *Submit* / *Next* buttons stay visible on phones (≤720px) inside long forms.
- **Operator toggle**: `?tt_view=mobile-settings` per-club switch (`force_mobile_for_user_agents`, default on).

### MFA — TalentTrack-native (#0086 Workstream B Child 1, v3.100.1 / v3.101.1 / v3.103.1)

Three sprints. Closes Child 1.

- **Enrollment**: from `?page=tt-account&tab=mfa` click *Start enrollment* — 4-step wizard scans your authenticator with a server-rendered QR, verifies a TOTP code, then shows 10 single-use backup codes (Copy / Print / "I have saved these").
- **Login enforcement**: per-club `mfa_required_personas` setting (default `[ academy_admin, head_of_development ]`). Required users without a 30-day "remember this device" cookie see `?tt_view=mfa-prompt` after login until they verify.
- **Lockout-recovery**: HoD or Admin reset MFA on a locked-out user from the operator-only Account-tab section. 5 failures = 15-min lockout (configurable).
- **Risk callouts in the spec**: REST is exempt from the gate; single-admin lockout requires manual DB recovery; cookie leak on shared browser bypasses MFA until expiry.

### Onboarding pipeline (#0081, v3.95.0 / v3.96.0 / v3.99.0)

Closes #0081 — five children, prospect-to-player chain.

- **Where**: `?tt_view=onboarding-pipeline` (XL widget on a single-column page). Or drop the *Onboarding pipeline* widget into any persona dashboard from the editor. Tile lives in the *Trials* group.
- **Six columns** — Prospects / Invited / Test training / Trial group / Team offer / Joined. Stale badges flag overdue tasks per stage.
- **The chain**:
  1. Scout logs a prospect → 2. HoD invites to a test training → 3. Parent confirms via no-login signed-token URL → 4. Coach records the test-training outcome (admit creates `tt_trial_cases` + a `tt_players` row with `status=trial`) → 5. 90-day quarterly trial-group review (offers a team, declines, or continues) → 6. Parent decision on team offer.
- **10 new KPIs** registered: `prospects_active_total`, `prospects_logged_this_month`, `prospects_stale_count`, `test_trainings_upcoming`, `trial_group_active_count`, `trial_decisions_pending`, `team_offers_pending_response`, `prospects_promoted_this_season`, `my_prospects_active`, `my_prospects_promoted`.

### Player notes (#0085, v3.97.1)

Staff-only running log on every player profile.

- **Where**: open a player's profile, click the new *Notes* tab. The same threading chrome as the goal-conversation feature (5-min edit window, soft-delete, mark-read).
- **Who can see it**: assistant_coach / head_coach / team_manager `r/c[team]` for their team's players, scout `r/c[global]`, HoD/Admin `r/c/d[global]`. **Players + parents explicitly excluded** — they never see this tab.
- **Player-archive cascade**: archiving a player soft-deletes their notes too (retained for compliance; hard-deleted via the future GDPR erasure pipeline).

### Persona dashboard editor canvas — collision + alignment + Shift-snap (#0088, v3.102.0)

Configuration → Dashboard layouts. Pure-JS extensions to the existing 1,052-LOC editor.

- Drag a tile onto another tile and the editor pushes it down (Notion-style auto-reflow). Pre-existing overlapping layouts auto-resolve on next load.
- Drag a tile near another tile's edge and 1px guide lines snap it to the alignment column. Tolerance 4px.
- Hold **Shift** on drop to switch behaviour from push-and-reflow to snap-to-nearest-free-cell (Figma-style "find a gap").

### Export module foundation + Team iCal (#0063, v3.105.0)

First ship of #0063 — 14 use cases follow.

- **Where**: `GET /wp-json/talenttrack/v1/exports` lists the exporters you can call. `GET /wp-json/talenttrack/v1/exports/team_ical?entity_id={team_id}&format=ics` downloads the team's TT-owned activities as an iCal feed (Spond-sourced rows filtered out so subscribed coaches don't see the same training twice).
- **Per-coach signed-token subscribe URLs** defer to a follow-up *Subscribe to this calendar* UI.

### Communication module foundation (#0066, v3.106.0)

Foundation only — no user-visible message yet. Ships the central authority for outbound messages with email channel adapter (`wp_mail` default, pluggable `tt_comms_email_send` filter for Mailgun / SES / Postmark), opt-out per message-type, quiet hours (21:00–07:00 default), 50-sends/sender/hour rate limit, audit table that hashes the body for GDPR. The 15 use cases (training cancelled, selection letter, PDP ready, …) register their own templates from owning modules in subsequent ships.

### Demo-data Excel: `Sessions` → `Activities` (#0080 Wave D, v3.108.0)

The latest ship — closes #0080.

- The demo-data Excel template's `Sessions` tab is now `Activities`. **Hard rename**: workbooks built against the v3.107.0-or-earlier template emit a clear blocker on upload. Re-download the template from *Tools → TalentTrack Demo* or rename the sheet manually.
- Schema array key stays `sessions` internally for code-path stability.
- `Session_Attendance` keeps its name.

## 2. Smaller things you should know about

- **Persisted-pitch chemistry pairs (v3.96.0)** — the chemistry pitch now draws lines between paired players showing the relationship score. Visible on the team-chemistry view.
- **Player-file Notes tab + counts badge (v3.97.1)** — new tab + count chip on the player file's tab bar.
- **Deferred polish wave 2 — A residual + B + C (#0080, v3.103.0)**: radar SVG visual refresh; demo wipe live preview; per-batch demo wipe scope; `UserComparisonPage` 5-user + per-cap drilldown; mobile-first card layout on the new-evaluation `RateActorsStep`; frontend lookup drag-reorder; matrix-admin per-tile gate popover; sub-cap refactor on three REST controllers.
- **Wave A license gates (v3.95.1)** — radar charts, undo-bulk, partial-restore now properly gated through `LicenseGate`.
- **Security + privacy documentation (#0086 Workstream A, v3.98.1)** — `docs/security-operator-guide.md` (EN+NL), `docs/privacy-operator-guide.md` (EN+NL), and three trust documents under `marketing/security/` (security page, privacy policy, draft DPA template). The DPA still needs legal review before execution; the security/privacy pages still need to be published on `talenttrack.app`.
- **Custom widget builder Phase 1 (#0078, v3.106.2)** — feature-flag-gated; **no user surface yet**. Phases 2-6 will ship the admin builder page, persona-dashboard rendering, and the new `tt_custom_widgets` table.
- **Playwright v1 starter (#0076, v3.107.0)** — dev infrastructure only. globalSetup + helpers + 2 specs (teams-crud, lookups-frontend). 6 follow-up specs queued.

## 3. Where to start clicking

If you have ~15 minutes:

1. **Open a player's profile** — you'll see the new *Analytics* tab + the *Notes* tab + the existing tabs.
2. **Open `?tt_view=team-blueprints`** — pick a team, hit *New blueprint*, build a match-day lineup, drag players, watch chemistry update.
3. **Open `?tt_view=team-planner`** — pick a team, navigate by week.
4. **Open `?tt_view=analytics`** — academy-wide KPI grid (HoD/Admin only).
5. **Open `?tt_view=onboarding-pipeline`** — six-column funnel.
6. **Configuration → Dashboard layouts** — drag tiles around, hold Shift on drop, watch the alignment guides.
7. **Account → MFA tab** — start enrollment if you want to feel the wizard.
8. **Tools → TalentTrack Demo** — download the new template; the Activities tab is the rename.

## 4. What's parked / on the roadmap

- **#0010 Multi-language (FR/DE/ES)** — pot regen + skeleton .po files queued.
- **#0016 Photo-to-session capture** — depends on a vision provider choice (OpenAI / Anthropic / Google).
- **#0063 Export module — 14 remaining use cases** + PDF / XLSX / ZIP renderers.
- **#0066 Communication module — 15 use cases + Push / SMS / WhatsApp / In-app adapters**.
- **#0078 Custom widget builder — Phases 2-6**.
- **#0083 Reporting framework — bulk migration of 26 legacy KPIs + the remaining 49 of the "top 15 per entity" set + time-series charts + drilldown + XLSX/PDF**.
- **#0086 Workstream B Children 2-4** — session management UI, login-fail tracking, optional admin IP allowlist.
- **#0086 Workstream C** — external audit (Securify / Computest, €5-15k).
- **#0068 Phases 3 + 4** — per-blueprint discussion thread (Threads adapter), mobile drag-drop polish, parent-facing share link.

Per-spec detail: see `SEQUENCE.md` for the live status board.
