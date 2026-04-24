<!-- type: feat -->

# Demo data generator — wizard for seeding and wiping demo content

Raw idea:

Dummy data generation and deletion for application demo purposes. Make it a separate admin page to play around with a demo setup. Wizard-based for scope.

## Constraint that drives this whole spec

Demo on 4 May 2026. Today is 23 April.

**Date posture — locked during shaping**: target May 4, slip to May 11 if needed, but **no preemptive cuts**. The full scope as specified is ~24 hours of work against a 22-hour window (2 hrs/day × 11 days), plus ~2 hours for the #0015 bug fix. That's ~4 hours over budget on paper.

We're proceeding anyway because:

- Preemptive cuts lock in a smaller demo based on guesses. Mid-build we'll discover which pieces are actually hard and which are easier than estimated. Better to make cut decisions with real information than with estimates.
- Some work may compress under Claude Code driving (especially the mechanical sweep of routing queries through the scope filter).
- A one-week slip to May 11 is acceptable if the demo requires it.

**What to watch during execution** (explicit checkpoints for when to decide to slip):

- **By end of Apr 28 (5 days in, ~10 hrs burned)**: should have schema + admin page skeleton + user generator + team/player generator done. If we're short of that, slip is likely.
- **By end of May 1 (8 days in, ~16 hrs burned)**: should have all generators working + scope filter in place + basic wipe logic. Remaining is polish, async progress, testing. If we're short, slip is near-certain.
- **May 3 (day before demo)**: full dry-run rehearsal. Any failures here = slip, no heroics.

**Cut priority list** (only used if May 4 stays hard and a slip isn't an option — make the least-damaging cut first):

1. **"Send test email" stretch goal** — saves ~0.5h. Skip cleanly.
2. **"Custom scope" mode in Step 1** — saves ~0.5h. Four presets cover the demo.
3. **6 archetypes → 4 archetypes** (drop Inconsistent + New arrival) — saves ~1h.
4. **Rich set → Standard set** (drop `hjo2`, `observer`, `parent` — 33 accounts instead of 36) — saves ~0.5h.
5. **Defer usage-stats/audit-log demo filtering** — saves ~0.5h. Ugly but not demo-blocking.

**Do NOT cut**: demo-mode toggle infrastructure, user persistence logic, Functional Roles assignment. Those are foundational and expensive to retrofit.

This is a `feat`, not an `epic`, and it's scoped to what's achievable. If the demo is a hard deadline, this idea slots into phase 0 of the sequence, ahead of everything else in `SEQUENCE.md` except the fatal bug `#0015` (which also affects demo credibility — a broken My profile is a bad look on stage).

## What this is for

A dedicated admin page where the demo-giver can, in 30 seconds, populate an empty or partially-empty TalentTrack with enough realistic-looking content to tell a story: a few teams, a squad of players per team, a season of evaluations, some sessions with attendance, a handful of goals in various states. Then afterward, wipe it with one click so nothing demo-y leaks into real club data.

The goal is **tell the product story credibly in 10 minutes without pre-meeting preparation.** Not a load tester, not a realistic-volume fixture, not a QA harness. Demo only.

## Scope

### What the wizard generates

Four configurable volume presets, plus advanced custom mode:

- **Tiny** — 1 team, 12 players, 4 weeks of activity. Good for a tight "walk-through a single team" demo.
- **Small** (default) — 3 teams, ~36 players, 8 weeks of activity. Good for "show the coach + head of development view."
- **Medium** — 6 teams, ~72 players, 16 weeks of activity. Starts to feel like a real academy.
- **Large** — 12 teams, ~150 players, a full season (36 weeks). Stress-tests the UI but takes ~30 seconds to generate.

Each preset generates a coherent set:

- **Teams** — named by age group (U12, U13, U14…) with one head coach each, drawn from demo user pool.
- **Players** — realistic mix of ages-by-team, Dutch-sounding first + last names from a seed list (300+ names per component, recombined), plausible heights/weights for age group, preferred positions and feet distributed realistically (~20% left-footed, etc.), jersey numbers unique per team, `date_joined` spread across the last 3 years.
- **Evaluations** — 1–3 per player per month across the activity window, spread across the existing eval categories (reuses whatever's configured — no new categories invented). Ratings trend slightly upward over time (players "develop"), with natural variance. A few players trend down (demo-worthy story). Opponents and match results generated from a seed list.
- **Sessions + attendance** — 1–2 sessions per team per week in the activity window. Attendance ~85% present, ~10% absent, ~5% late, with patterns (one player who misses a lot, one who's always there).
- **Goals** — 1–2 goals per player, mix of statuses (active, achieved, missed) across the lifetime.
- **Lookups** — uses whatever already exists in the install; does not modify.

### What it deliberately does NOT generate

- **Demo WP users — persistent across demo batches, generated once and reused.** The demo domain (`mediamaniacs.nl` in this case, configurable per install) is controlled by the demo-giver with a catch-all forwarding to a real inbox. Demo users are a **long-lived resource**, not per-batch — created once, reused across many generate/wipe cycles of the underlying data. This is the key refinement from an earlier draft: users survive data wipes by default.

  **Two user-management modes in the wizard:**

  - **"Use existing demo users"** (default when demo users already exist). Wizard detects demo-tagged users in `wp_users` and re-uses them. No new accounts created. Catch-all inbox stays clean.
  - **"Create new demo user set"** (first-run, or after explicit user wipe). Creates the Rich set of accounts. WordPress's normal "you've been added to this site" emails go out to the catch-all — acceptable because it's a one-time event, not a per-rehearsal event.

  **The Rich set (created once, reused):**

  Fixed accounts (always created, independent of preset):
  - `admin@<demo-domain>` — `administrator` role
  - `hjo@<demo-domain>` — `tt_head_dev` (Hoofd Jeugd Opleiding) — primary
  - `hjo2@<demo-domain>` — `tt_head_dev` — second HoD for showing multi-HoD workflows
  - `scout@<demo-domain>` — `tt_readonly_observer` (upgrades to `tt_scout` when #0014/#0017 introduces that role)
  - `staff@<demo-domain>` — `tt_staff` (e.g. physio / team manager)
  - `observer@<demo-domain>` — `tt_readonly_observer` — shows the view-only role distinct from scout
  - `parent@<demo-domain>` — `tt_player` role for v1 (there's no dedicated parent role in the plugin today); linked to a player via `wp_user_id` dynamically per data batch. Flag for #0014 whether a dedicated parent role should be added.

  Per-team accounts:
  - `coach<N>@<demo-domain>` — `tt_coach`, one per potential team slot. Created up to Large's team count (12) on first run so subsequent preset changes never need new user creation.
  - `assistant<N>@<demo-domain>` — `tt_coach`, one per potential team slot (12). Assigned as assistant coach via Functional Roles when a data batch creates a team that uses them.

  Player accounts:
  - `player1@<demo-domain>` through `player5@<demo-domain>` — `tt_player` role. 5 slots. Linked via `wp_user_id` to specific generated players **at data-generation time** (not at user-creation time) — so a data wipe + regenerate re-binds these accounts to new player records, but the login itself is stable. Chosen to cover narrative archetypes (Rising star, In-a-slump, New arrival, plus one Steady-solid and one Inconsistent for variety).

  **Total accounts created on first run**: 7 fixed + 12 coaches + 12 assistants + 5 players = 36 accounts, one-time. After that, all generator runs reuse these.

  **Catch-all email volume**: ~36 WP notification emails when users are first created (one-time, one burst — watch for rate-limiting on cheap hosts but in practice fine for mediamaniacs.nl). Zero emails on any subsequent data generation because users are reused.

  **Password**: set once during user creation, shown once on the creation success screen. Stored only in WP's native hashed form. If lost, the demo-giver uses WP's normal password-reset flow, which hits the catch-all inbox — and that works because the domain is under their control.

  **Domain confirmation**: before creating users (new-user mode only), the demo-giver confirms the domain is correct and a catch-all is active ("Confirm that `<anything>@mediamaniacs.nl` routes to an inbox you control"). Required checkbox — without it, user creation is disabled. For "use existing" mode, no check needed because no emails will be sent.
- **No photos.** `photo_url` left blank. Tempting to use Unsplash or generated faces, but (a) copyright/licensing risk, (b) ~100 HTTP downloads on demo-generate is a failure mode we don't need, (c) the FIFA card's photoless-placeholder looks fine.
- **No audit log entries.** Generator inserts directly via `$wpdb->insert`, bypassing the audit-log trigger path. Saves time, avoids polluting the audit log with "demo generated 500 things." (Flag: this is a demo-time shortcut; if #0013 backup testing ever wants audit coverage, that's a separate concern.)
- **No custom field values.** If the site has custom fields defined, they stay NULL on generated records. Filling them realistically is complex and usually not needed for the demo story.
- **No trial cases, formation assignments, development track entries.** Those are features from later ideas (#0017, #0018) — when those ship, the generator gets extended. Not v1.

### What the deletion side does

Two distinct actions, not one:

- **"Wipe demo data"** (the common one). Deletes all non-user demo records: teams, players, evaluations, sessions, attendance, goals, ratings. **Leaves demo users in place** so they can be reused for the next data generation. Confirmed via typed confirmation ("Type WIPE"). This is the button you press between rehearsals.
- **"Wipe demo users too"** (rare, separate button). Also removes the demo WP users. Use only when resetting the whole demo setup from scratch — e.g. changing the demo domain, or cleaning up before uninstalling. Typed confirmation ("Type WIPE USERS") distinct from the data-wipe confirmation, so muscle memory can't trigger it accidentally.

The underlying logic uses `tt_demo_tags.extra_json` to mark user rows as `persistent: true`. Data-wipe deletes everything tagged `persistent: false` (or null). User-wipe additionally removes rows where `persistent: true`.

**Crucially, deletion must never touch non-demo data.** This is the part that gets tested more than anything else before demo day.

Safety rails specific to user deletion:
- Refuse to delete any user whose email domain doesn't match the configured demo domain (even if they're somehow tagged as demo).
- Refuse to delete the currently-logged-in user.
- Refuse to delete the last remaining administrator.
- All three checks run *before* the delete, with a clear error if any fails.

Safety rails specific to data wipe (the common action):
- Re-binds the `player1@...` through `player5@...` accounts' `wp_user_id` to NULL (since the players they pointed at are being deleted). Next data generation re-binds them to new archetype-matched players. Logins and passwords stay valid.

## The tagging mechanism (the important decision)

How do we know a record is demo vs real, safely?

Option A — **Demo ID prefix.** Every generated record's string-key field starts with `[DEMO]`. Easy, visible. Downside: shows up in the UI (players named `[DEMO] Pieter van Rijn`), which undermines the demo.

Option B — **Separate `is_demo` column** on every data table. Clean, queryable. Downside: schema change across 10+ tables, which is not a 22-hour task.

Option C — **Dedicated `tt_demo_tags` table** mapping `(entity_type, entity_id)` pairs to a demo-batch ID. One new table, no changes to existing tables, full control. Deletion is "find every entity tagged in `tt_demo_tags`, delete them, then delete the tag rows." This also lets us support **multiple demo batches** (generate small, then add a team to test, then wipe just the add-on) cleanly.

**Going with Option C.** One new table, one migration, reversible, doesn't leak into the UI.

```sql
CREATE TABLE tt_demo_tags (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id VARCHAR(64) NOT NULL,
  entity_type VARCHAR(32) NOT NULL,   -- 'player', 'team', 'evaluation', 'session', 'attendance', 'goal', 'eval_rating', 'wp_user'
  entity_id BIGINT UNSIGNED NOT NULL,
  extra_json TEXT DEFAULT NULL,       -- per-entity metadata, e.g. {"archetype":"rising_star"} on players
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_batch (batch_id),
  KEY idx_lookup (entity_type, entity_id)
);
```

Deletion walks this table in dependency order (ratings → evaluations → attendance → sessions → goals → players → teams → wp_users), deletes each, then deletes the tag rows.

## The wizard — scope

Four steps, single page, no router changes needed.

**Step 1 — Scope.** Radio buttons for Tiny / Small / Medium / Large / Custom. Estimated generation time and row count shown per option. Custom mode exposes: number of teams, players per team, weeks of activity, evaluation frequency (sparse / normal / heavy). Small is preselected.

**Step 2 — Demo accounts.** Wizard first checks: do demo users already exist on this install?

- **If yes**: shows "36 demo users found. These will be reused for this batch. [List]" with a small "Create a new set instead (wipes existing demo users)" link for the rare case of starting over. Advances to Step 3.
- **If no** (first run, or after explicit wipe): text input for the demo email domain (defaults to whatever was last used, stored in site options). Preview of the 36 users that will be created. Single password input (defaults to a randomly-generated strong one, shown to the demo-giver, overridable). Checkbox: "I confirm `<anything>@<domain>` routes to an inbox I control." Required before proceeding. Advances to Step 3.

Either way, Step 2 resolves to "these users will be used for the data in this batch" and Steps 3–4 proceed identically. The demo-giver sees a unified summary in Step 3 regardless of whether users were created or reused.

**Step 3 — Confirm.** Summary of what will be created ("3 teams, ~36 players, ~580 evaluations, ~48 sessions, ~65 goals, 7 WP users under mediamaniacs.nl — about 20 seconds to generate"). A single warning: "this creates real database records tagged as demo. Clean up via the Wipe button before going to production."

**Step 4 — Generate.** Progress bar via polling a status endpoint (not blocking the request — generation takes ~20–30 seconds for Medium and we don't want a hung browser). Shows "creating WP users… generating teams… generating players… generating evaluations…" stepwise. Success screen lists batch ID, row count, **and the login details for each demo account** (email + password, one-time display — no secret storage in the DB beyond the hashed password WP stores natively).

Separate **"Wipe demo data"** section on the same admin page. Two modes:

- **Wipe all demo data** — deletes everything across all batches.
- **Wipe specific batch** — if multiple batches exist, choose which to remove. Useful when incrementally testing ("I generated small, then added a team manually to test something, now just remove my add-on and regenerate small").

### Demo mode — global toggle with full query-level scoping

The generator is paired with a **site-level "demo mode" toggle** that controls what data is visible across the entire plugin. This is the key refinement that makes the generator safe to run on any install, including one with real club data.

**How it works:**

- Single site option: `tt_demo_mode` with values `on` / `off` (and a neutral internal value for admin-only views).
- When `demo_mode = on`: every query across TalentTrack filters to demo-tagged records only. The demo-giver sees only demo teams, demo players, demo evaluations, etc.
- When `demo_mode = off` (the normal state): every query excludes demo-tagged records. The real academy never sees demo content, even though it's co-existing in the same database tables.
- The toggle is exposed on the demo admin page (Tools → TalentTrack Demo). Flipping it is a single click. Current state is shown prominently at the top of every wp-admin TalentTrack page so you never forget which mode you're in ("⚠️ Demo mode ON").

**Why this shape (and not the alternatives):**

- "Only run on empty installs" would prevent anyone from trialling the generator on a live site. Unacceptable for post-May-4 uses.
- "Filter demo data out of coach views only" still leaks demo records into admin queries.
- A global toggle at the query layer is the only correct answer — every read path respects the same scope, and nothing can leak in either direction.

**Implementation — scope filter at `QueryHelpers`:**

The existing `src/Infrastructure/Query/QueryHelpers.php` is the central home for entity reads. A new helper `apply_demo_scope($query_fragment, $entity_type)` gets wired into every method that returns data:

```php
// Central scope function:
public static function apply_demo_scope(string $table_alias, string $entity_type): string {
    $mode = get_option('tt_demo_mode', 'off');
    $tag_table = $wpdb->prefix . 'tt_demo_tags';

    if ($mode === 'on') {
        return "AND {$table_alias}.id IN (
            SELECT entity_id FROM {$tag_table}
            WHERE entity_type = '{$entity_type}'
        )";
    }
    if ($mode === 'off') {
        return "AND {$table_alias}.id NOT IN (
            SELECT entity_id FROM {$tag_table}
            WHERE entity_type = '{$entity_type}'
        )";
    }
    return ''; // neutral — only used by the demo admin page itself
}
```

Every `get_players()`, `get_teams()`, `get_evaluations()`, `get_sessions()`, `get_goals()`, stats computation, and REST endpoint query must route through this. A one-time audit pass identifies every direct `$wpdb->get_*()` call in the plugin and routes them through the scoped helper. This is tedious but mechanical — Claude Code can handle it in a systematic sweep.

**What the toggle does NOT affect:**

- The demo admin page itself (obviously) — uses neutral scope.
- WordPress core queries against `wp_users` and `wp_options` — untouched.
- Migrations — run regardless of demo mode.
- Usage stats and audit log — these are separate internal surfaces, not part of the coach-facing view, and counting demo activity in usage stats would be confusing. They filter demo out always, like regular mode.

**Demo-mode indicator in the UI:**

When demo mode is ON, the admin bar (wp-admin) shows a prominent badge: "🎭 DEMO MODE". Frontend shortcode output also includes a small banner at the top. Hard to miss. Nobody accidentally thinks they're looking at real data.

**Safety rails on the toggle:**

- Flipping from OFF to ON: single click, no confirmation. Low-risk action.
- Flipping from ON to OFF: typed confirmation ("Type EXIT DEMO") because leaving demo mode while the catch-all is still receiving test activity could confuse a parent who's been invited to the demo.
- Only `manage_options` users can flip the toggle. Same gate as the generator.

## What stays admin-only (and why this one doesn't need to migrate to frontend)

This feature is deliberately wp-admin-only, and *should stay that way* even after #0019 migrates everything else. Reasons:

- The demo generator is not something coaches or players ever use. Only the person giving the demo.
- The person giving the demo is either the club admin, or you. Both already have wp-admin access.
- Restricting it to wp-admin is a useful safety rail. If you somehow surface it on the frontend by mistake, a coach could accidentally wipe real data.
- Capability gate: `manage_options` (core WP admin cap), not a new TalentTrack-specific cap. Keeps the surface small.

Menu placement: under Tools → TalentTrack Demo (not under the TalentTrack main menu). Keeps it out of the normal product workflow entirely.

## Realistic time budget

Breaking down what actually needs to happen, for someone driving Claude Code 2 hours a day:

| Work | Hours |
| --- | --- |
| Schema migration for `tt_demo_tags` table (with `extra_json`) | 0.5 |
| Admin page skeleton + wizard scaffolding (4 steps) | 1.5 |
| Demo domain input + catch-all confirmation UI | 0.5 |
| WP user generator — Rich set, one-time creation (36 accounts) | 1.5 |
| "Use existing vs create new" detection + mode selection UI | 1.0 |
| Functional Roles assignment for assistant coaches per team | 1.0 |
| Name / position / opponent seed data files (Dutch, KNVB-style) | 1.0 |
| Team generator + player generator (with dynamic wp_user_id re-binding) | 2.0 |
| Session + attendance generator | 1.5 |
| Evaluation + rating generator — 6 narrative archetypes | 3.0 |
| Goals generator | 1.0 |
| Async generation + progress polling | 1.5 |
| Data-wipe deletion logic (dependency order) | 1.5 |
| User-wipe logic with safety rails (separate action) | 1.0 |
| Typed-confirmation UI + batch selection | 1.0 |
| "Send test email" stretch goal | 0.5 |
| **Demo mode toggle + scope filter infrastructure** | **1.5** |
| **Audit and wire QueryHelpers + REST + frontend queries through scope filter** | **1.0** |
| Testing against a real demo dry-run (2–3 passes) | 2.5 |
| **Total** | **~24.5 hours** |

**Budget: 24 hours of work against a 22-hour window (2 hrs/day × 11 days), plus ~2 hours needed for the #0015 bug fix.**

**We're now ~4 hours over the budget for a May 4 ship.** This is not a matter of Claude Code being faster or of clever optimization — the spec as locked would take a capable developer with Claude Code ~24 hours of genuinely focused driving time. At 2 hours a day, that's 12 days; the demo is in 11.

**Honest picture for date decision:**

- **May 4** requires cuts. See the cut list below — each line is a decision you can make now or make later under pressure.
- **May 11** fits the full locked scope comfortably, with 2–4 hours of slack for the unexpected.
- **May 18** fits the full locked scope *plus* the Phase 0 bugs (#0007, #0008) that have been deprioritized.

If May 4 is hard, the cut priority (least-loss first):

1. **"Custom scope" mode in Step 1** — saves ~30 min. Four presets cover the demo.
2. **"Send test email" stretch goal** — never added to the budget, but if accidentally gold-plated, saves ~30 min.
3. **6 archetypes → 4 archetypes** (drop Inconsistent + New arrival) — saves ~1 hour. Demo narrative is thinner.
4. **Rich set → Standard set** (drop `hjo2`, `observer`, `parent` — keep only 1 HoD and 33 accounts instead of 36) — saves ~30 min. Demo still runs with a representative role set.
5. **Defer usage-stats/audit-log demo filtering** — saves ~30 min. Those surfaces would show demo events mixed with real, which is ugly but not a demo-day blocker.

Do *not* cut the demo-mode toggle infrastructure, the user persistence logic, or the Functional Roles assignment — those are foundational decisions that are expensive to retrofit.

Anything from the Phase 0 bug list other than #0015 (i.e. #0007, #0008) can slip past the demo date regardless.

## What this sacrifices vs the original sequence

Moving this to the front pushes everything else back by ~16 hours. Against the already-aggressive SEQUENCE.md estimate that the full backlog is 4–6 months at current pace, losing a week's worth of progress doesn't change the strategic picture. The trade is: **a working demo generator in 11 days, at the cost of delaying Phase 1 start by a week.** For a hard demo deadline, that's the right trade.

## Decisions locked during interactive shaping

- **Demo language and context — Dutch, KNVB-style.** Single seed set. Consequences:
  - First + last names: Dutch only (100×100 seed combinations; ~10,000 unique pairs, plenty for any preset).
  - Team age labels: Dutch KNVB conventions — `JO8` through `JO19` for jeugd (youth), or `U8`/`U19` as alternates. Generator defaults to JO-labels since they're the KNVB standard.
  - Opponents: Dutch club names seed list — real enough to feel familiar (e.g. Ajax, Feyenoord, PSV, AZ, FC Utrecht, Vitesse, RKC, Sparta, NEC, plus amateur/regional names). Use 30+ so no opponent repeats feel artificial across a season.
  - Positions, preferred-foot labels, etc. use whatever the plugin's lookups already have (Dutch in the current install — the Dutch `.po` file drives the UI, seeds don't need to duplicate that).
  - Match results: Dutch notation — `W 3-1`, `V 0-2`, `G 1-1` (win/verlies/gelijk) — matches how Dutch coaches actually write it.
  - No English fallback in v1. If a future demo needs English, it's a separate seed-set that can drop in alongside — the generator architecture supports locale-specific seeds trivially (`seeds/first_names_nl.txt` naming already implies it). Not in scope now.
- **Reproducibility — fixed default seed, with a Reroll button.** Deterministic generation by default (same output every run), so demo rehearsal matches demo-day exactly. A "Reroll" button in the Custom section of Step 1 swaps the seed to a new random value if a generated roster happens to produce an unlucky combo (e.g. a player named identically to someone in the audience, or three players with the same first name on one team). The seed used for the latest successful generation is displayed on the success screen so you can write it down and re-enter it if you ever want to reproduce that exact roster again. Implementation: `mt_srand($seed)` at the top of the generation run, then all `mt_rand()` calls produce deterministic output. The fixed default seed is a round number you can remember (e.g. 20260504 — the demo date) so "default seed" is never mysterious.
- **Narrative archetypes — 6 patterns distributed across the player pool.** Uniform random ratings feel noisy and flat. Six archetypes with hand-tuned trajectories make the demo tell multiple coach-conversation stories simultaneously. Distribution is roughly: 15% Rising star, 10% In-a-slump, 30% Steady-solid (the majority — because most players are neither stars nor problems), 15% Late bloomer, 15% Inconsistent, 15% New arrival. Distribution is deterministic per-seed — rerun with the same seed → same player ends up as the same archetype.

  | Archetype | Trajectory | Notes |
  | --- | --- | --- |
  | Rising star | Start ~3.0, climb steeply to ~4.5 over the activity window | Small variance. Clear upward line on the sparkline. |
  | In-a-slump | Start ~4.0, drop to ~2.8 in the middle weeks, flatten | The "something changed" story. Variance increases mid-slump. |
  | Steady-solid | Flat ~4.0 ±0.3 throughout | The reliable regulars. Makes up the majority — a squad of only stories is unrealistic. |
  | Late bloomer | Flat ~3.0 for first 60% of window, then climbs to ~4.2 | The "give him time" story. |
  | Inconsistent | Noisy around a 3.5 mean, variance ~0.8 | High/low swings that make the coach doubt. |
  | New arrival | No data before the last 4–6 weeks of the window; then a short burst of ratings around 3.2–3.8 | Demonstrates partial-history players and how the UI handles them. |

  Archetype is assigned at player-generation time and stored in `tt_demo_tags.extra_json` (a small addition to the schema) so that:
  - Rating generation can look up the archetype and apply the right curve.
  - The demo-giver can (optionally) see in the admin page which players have which archetype — useful for rehearsal when you want to know "who's my Rising star for this demo."
  - Re-generating with the same seed produces the same archetype assignment.

  This is the single biggest lever for making the demo feel like a real academy, not synthetic data. Worth getting right.
- **Password display — once only, or stored somewhere?** Shown once on the user-creation success screen, never stored in the plugin beyond WP's native hashed form. If the demo-giver loses it, they can regenerate any account via WP's normal password-reset flow (which hits the catch-all inbox — and that's exactly why the catch-all pattern works).
- **"Send test email" button — included as stretch, first to cut.** A button on the demo admin page that sends a timestamped message to `test-<timestamp>@<demo-domain>` via the WP mail system. Returns a UI state showing whether the SMTP send succeeded (not whether it was delivered — WordPress can't know delivery). Useful before the user-creation step to sanity-check the catch-all is still routing correctly, especially months after the domain was first configured. Added to the time budget at ~0.5h. First thing cut if May 4 gets hairy. Skip for later if not done in v1 — domain confirmation checkbox is adequate safety in its absence.

## Flags for future work (not v1)

- **"Refresh evaluations only" button.** Sometimes during demo prep you want new evaluation data without regenerating the whole roster. Useful, but not essential for v1. Defer.
- **Generator support for backup testing (#0013).** Having a reliable way to regenerate a known-state dataset makes backup/restore testing dramatically easier. Flag for #0013's implementation — this generator can double as a fixture factory for backup tests.
- **Development data for #0016, #0017, #0018.** When photo-to-session, trial player, and team chemistry features ship, the generator should generate realistic demo data for them too (e.g. sample exercises, trial cases, formation assignments). Not v1; extended as those epics ship.
- **Dedicated parent role.** For v1 the `parent@` account uses `tt_player` role and is linked to a player via `wp_user_id`. When #0014 (profile + reports) ships, it should consider introducing a proper `tt_parent` role with different capabilities. Flag for #0014.

## Touches

New module (or sub-module under Configuration): `src/Modules/DemoData/`
- `DemoDataModule.php`
- `Admin/DemoDataPage.php` — the wizard
- `Generators/UserGenerator.php` — the demo WP users
- `Generators/TeamGenerator.php`
- `Generators/PlayerGenerator.php`
- `Generators/EvaluationGenerator.php`
- `Generators/SessionGenerator.php`
- `Generators/GoalGenerator.php`
- `DemoBatchRegistry.php` — owns the `tt_demo_tags` table
- `DemoDataCleaner.php` — the wipe logic with dependency-order deletion + user safety rails
- `seeds/first_names_nl.txt`, `seeds/last_names_nl.txt`, `seeds/team_names.txt`, `seeds/opponents.txt`, `seeds/match_results.txt`

Schema:
- New migration (next number): `CREATE TABLE tt_demo_tags`

Capabilities: gated by `manage_options` (core WP), no new TalentTrack capabilities.

Menu: under Tools → TalentTrack Demo, *not* under the main TalentTrack menu.

Integration: independent. Does not touch any other module, does not modify existing tables, does not conflict with anything else in the backlog.

## Sequence impact

Updates to `SEQUENCE.md` needed:

- **Phase 0 gains one item**: the demo generator, slotted ahead of the other bugs due to the May 4 deadline.
- **#0015** (fatal My profile bug) stays in Phase 0 — it's tiny (~10 lines) and a broken-during-demo scenario is exactly the kind of thing that demo prep must prevent.
- **#0007 and #0008** slip past May 4. #0008 has a September hard deadline — plenty of time post-demo.
- Phase 1 (#0019) starts after May 4 (or May 11, if we slip), not before.
