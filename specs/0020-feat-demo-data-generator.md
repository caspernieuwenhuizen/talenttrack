<!-- type: feat -->

# Demo data generator and demo-mode toggle

## Problem

There is no way to populate a TalentTrack install with realistic, coherent demo content in under a minute, and no way to demonstrate the product to prospective academies without either manually creating data (hours, tedious, fragile across rehearsals) or showing an empty shell (unconvincing). Equally, there is no way to run demos safely on an install that also contains real club data — there's nothing preventing demo activity from polluting a club's records.

For the 4 May 2026 demo and every demo after it, we need a tool that:

- Seeds a coherent academy dataset (teams, players, evaluations, sessions, goals) fast and reproducibly
- Creates a role-spanning set of demo user accounts once, reusable across rehearsals
- Isolates demo content from any real data sharing the database, via a global toggle that scopes every query
- Cleans up cleanly when the demo is over, without risk to real data

Who feels it: the demo-giver (head of development, club admin, or us) every time they need to show the product. Today this is infeasible. After shipping, it's a single-click flow.

## Proposal

A new wp-admin-only module at `src/Modules/DemoData/` providing a four-step wizard for generation and a site-level "demo mode" toggle that scopes every read query to either show-only-demo or hide-demo-entirely.

Three structural decisions anchor the implementation:

1. **A dedicated `tt_demo_tags` table** maps `(entity_type, entity_id)` pairs to demo batches. No schema changes to existing tables. Deletion walks this table in dependency order.
2. **A single `QueryHelpers::apply_demo_scope()` function** is wired into every read path across the plugin. When demo mode is ON, queries filter to demo-tagged records. When OFF (the default for any real install), queries exclude them.
3. **Persistent demo users, reused across batches.** The Rich set of 36 accounts is created once against a catch-all email domain the demo-giver controls. Subsequent generate/wipe cycles reuse them — users survive data wipes.

This turns "demo data" from a risky one-off operation into a first-class, reversible, safe mode of the product.

## Scope

### The wizard (under Tools → TalentTrack Demo)

Four steps:

1. **Scope preset.** Radio buttons for Tiny (1 team / 12 players / 4 weeks), Small (3 teams / ~36 players / 8 weeks — default), Medium (6 teams / ~72 players / 16 weeks), Large (12 teams / ~150 players / 36 weeks), plus Custom. Seed value field with fixed default `20260504` and a Reroll button.
2. **Demo accounts.** Detects whether demo users already exist. If yes: shows the list, advances to Step 3. If no (first run): domain input, preview of 36 accounts to create, password input with generated default, required checkbox "I confirm `<anything>@<domain>` routes to an inbox I control."
3. **Confirm.** Summary of what will be generated. Warning about demo-mode implications.
4. **Generate.** Async progress polling via a status endpoint. Success screen shows batch ID, row counts, and (on first run only) the list of created accounts with credentials.

### Generators (`src/Modules/DemoData/Generators/`)

- **UserGenerator** — creates the Rich set of 36 persistent WP users on first run. Fixed accounts: `admin@`, `hjo@`, `hjo2@`, `scout@`, `staff@`, `observer@`, `parent@`. Per-team slots: `coach1@`–`coach12@`, `assistant1@`–`assistant12@` (created up to Large's count so preset changes never need new users). Player slots: `player1@`–`player5@`, linked via `wp_user_id` to generated players at data-generation time (re-bound on each generate).
- **TeamGenerator** — Dutch JO8–JO19 age-group teams, head coach assigned from `coach<N>@` pool, assistant coach via Functional Roles module.
- **PlayerGenerator** — Dutch names from seed files (100×100 combinations), age-appropriate to team, plausible heights/weights, jersey numbers, preferred foot distributed realistically.
- **EvaluationGenerator** — 1–3 evaluations per player per month across the activity window, ratings follow one of six narrative archetypes (Rising star 15%, In-a-slump 10%, Steady-solid 30%, Late bloomer 15%, Inconsistent 15%, New arrival 15%). Archetype assignment is deterministic per seed, stored in `tt_demo_tags.extra_json`.
- **SessionGenerator** — 1–2 sessions per team per week with attendance patterns (85% present / 10% absent / 5% late, with per-player tendencies).
- **GoalGenerator** — 1–2 goals per player across status states (active, achieved, missed).

### Demo-mode toggle and scope filter

- Site option `tt_demo_mode` with values `on | off` (and an internal `neutral` value for the demo admin page itself).
- A new method `QueryHelpers::apply_demo_scope( string $table_alias, string $entity_type ): string` returns a SQL fragment appending `AND id IN (...)` or `AND id NOT IN (...)` based on mode.
- Every read path across the plugin is audited and routed through this helper. Specifically: all `QueryHelpers::*` entity methods, all REST controllers under `includes/REST/`, all direct `$wpdb->get_*()` calls inside `src/Modules/*/` and `src/Shared/Frontend/`.
- A prominent admin-bar indicator ("🎭 DEMO MODE") and a frontend shortcode-output banner show when mode is ON. Hard to miss.

### Wipe actions (on the demo admin page)

- **"Wipe demo data"** — typed confirmation "WIPE". Deletes everything tagged demo *except* users (those are marked `persistent: true` in `extra_json`). Dependency order: ratings → evaluations → attendance → sessions → goals → players → teams. Re-binds `player1@`–`player5@` users' `wp_user_id` to null.
- **"Wipe demo users too"** — typed confirmation "WIPE USERS". Removes the persistent user set. Rare, typically only when changing demo domain or uninstalling. Three safety rails before each user delete: email domain matches configured demo domain, user is not the current logged-in user, user is not the last administrator.

### Seed data files

All in `src/Modules/DemoData/seeds/`:

- `first_names_nl.txt` — 100 Dutch first names (boys' names for this demo, given the football-academy context)
- `last_names_nl.txt` — 100 Dutch last names
- `team_age_groups.txt` — JO8 through JO19 (KNVB convention)
- `opponents.txt` — 30+ Dutch club names, mix of professional (Ajax, Feyenoord, PSV, AZ, FC Utrecht, Vitesse, RKC, Sparta, NEC…) and amateur/regional
- `match_results.txt` — result notation seeds (W 3-1, V 0-2, G 1-1 etc., Dutch W/V/G for winst/verlies/gelijk)

### Schema

One new migration adding `tt_demo_tags`:

```sql
CREATE TABLE tt_demo_tags (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id VARCHAR(64) NOT NULL,
  entity_type VARCHAR(32) NOT NULL,  -- 'player'|'team'|'evaluation'|'session'|'attendance'|'goal'|'eval_rating'|'wp_user'
  entity_id BIGINT UNSIGNED NOT NULL,
  extra_json TEXT DEFAULT NULL,  -- archetype on players, persistent:true on users, etc.
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_batch (batch_id),
  KEY idx_lookup (entity_type, entity_id)
);
```

## Out of scope

- **No photos.** `photo_url` stays blank on generated players. FIFA card placeholder looks fine.
- **No audit log entries for generated records.** Generators bypass the audit trigger path, inserting directly via `$wpdb->insert`. Saves time and avoids "demo generated 500 rows" pollution.
- **No custom field values on generated records.** Custom fields stay NULL. Filling them realistically is complex and unnecessary for the demo story.
- **No trial cases, formation assignments, or development-track entries.** Those belong to #0016, #0017, #0018 — when those ship, the generator gets extended.
- **No English or other-language seed sets.** Dutch-only, KNVB-style. Seed naming supports future locale variants but not in v1.
- **No frontend migration of the demo admin page.** Stays wp-admin only, permanently. Even after #0019 migrates everything else to frontend.
- **No "refresh evaluations only" button.** Deferred; full regenerate is acceptable for v1.

## Acceptance criteria

### Generation

- [ ] On a fresh install (empty TalentTrack tables), Small preset generates in ≤30 seconds: 3 teams, ~36 players, ~580 evaluations, ~48 sessions, ~65 goals. Counts are approximate but generation always completes without errors.
- [ ] First-run generation also creates the Rich set of 36 demo WP users under the configured domain. All users receive WordPress's standard "you've been added" email (landing in the catch-all inbox).
- [ ] Second-run generation (against the same install, after a data wipe) does **not** create new users — it reuses the existing ones. No email burst.
- [ ] The fixed default seed `20260504` produces byte-identical output across runs (same player names, same archetypes assigned to same players, same evaluation values).
- [ ] Rerolling the seed produces a different roster; re-entering the old seed reproduces the original roster.
- [ ] Each archetype's trajectory is visually distinguishable on the player's evaluation history view: Rising star climbs, In-a-slump dips then flattens, Steady-solid stays flat, Late bloomer flat-then-climbs, Inconsistent is noisy, New arrival has only 4–6 weeks of data.

### Demo mode

- [ ] When `tt_demo_mode = off` (default), no demo-tagged records appear in any list, dashboard, stat computation, REST response, or frontend view anywhere in the plugin.
- [ ] When `tt_demo_mode = on`, ONLY demo-tagged records appear. Non-demo records are invisible (but not deleted — remain safe in the database).
- [ ] Toggling demo mode on from off requires a single click. Toggling from on to off requires the typed confirmation "EXIT DEMO".
- [ ] The "🎭 DEMO MODE" indicator is visible in the wp-admin admin bar whenever demo mode is ON.
- [ ] Frontend shortcode output includes a banner when demo mode is ON.
- [ ] The demo admin page itself shows correct data regardless of the toggle state (it uses neutral scope internally).

### Deletion

- [ ] "Wipe demo data" removes all generated content (teams, players, evaluations, sessions, attendance, goals, ratings) in the correct dependency order, without foreign-key violations.
- [ ] After "Wipe demo data", demo WP users still exist and can log in. Their `wp_user_id` linkage to generated players is null until the next generate.
- [ ] "Wipe demo users too" removes the 36 demo users.
- [ ] All three user-safety rails fire correctly when triggered: wrong-domain user refused, current user refused, last-admin refused.
- [ ] Under no circumstances does any wipe operation delete a non-demo record. This is verified explicitly by a manual integration test on a seeded install with both real and demo data.

### Wizard UX

- [ ] Step 2 correctly detects existing demo users and skips user creation when they're present.
- [ ] Step 2 refuses to proceed to Step 3 for new user creation if the domain-confirmation checkbox is unchecked.
- [ ] Step 4 shows real progress (not a fake progress bar), polled from a status endpoint.
- [ ] On first generation, the success screen displays all 36 account credentials in a copy-friendly format.

### Safety and gating

- [ ] The `tt_demo_tags` migration runs cleanly on a fresh install and as an update from any existing plugin version.
- [ ] The demo admin page is accessible only to users with `manage_options` capability.
- [ ] All wipe actions require typed confirmation matching the specified exact strings.

## Notes

### Architectural decisions made during shaping

- **Tagging via a separate table, not columns.** Keeps existing tables untouched. Cost is query-time JOIN overhead, which is negligible for demo-scale data.
- **Rich user set, persistent.** Users are a one-time setup, not a per-batch concern. The 36 accounts survive wipes; only data is regenerated.
- **Full scope-filtering, not partial.** Rejected Option B (generator-only filtering) and Option C (toggle-but-no-filtering-yet) in favor of Option A: every read path audited and routed through `apply_demo_scope()`. More work, but correct forever.
- **Dutch-only seeds.** KNVB conventions (JO8–JO19, W/V/G match notation). Architecture supports locale variants later but not v1.
- **Six narrative archetypes.** Uniform-random ratings feel noisy and flat. Archetypes give the demo multiple coach-conversation stories simultaneously. Distribution is deterministic per seed.

### Execution checkpoints

Concrete gates for deciding whether to ship on May 4 or slip to May 11:

- **End of Apr 28** (5 days in, ~10 hrs): should have schema + admin page skeleton + user generator + team/player generator done. If short, slip is likely.
- **End of May 1** (8 days in, ~16 hrs): should have all generators + scope filter + basic wipe logic. If short, slip is near-certain.
- **May 3** (day before demo): full dry-run rehearsal. Any failure = slip, no heroics.

### Cut priority list (least-loss first)

Only used if May 4 stays hard and slipping isn't an option:

1. "Send test email" stretch button — ~0.5h
2. "Custom scope" mode in Step 1 — ~0.5h
3. 6 archetypes → 4 archetypes (drop Inconsistent + New arrival) — ~1h
4. Rich set → Standard set (drop `hjo2`, `observer`, `parent`) — ~0.5h
5. Defer usage-stats/audit-log demo filtering — ~0.5h

Do **not** cut: demo-mode toggle infrastructure, user persistence logic, Functional Roles assignment. These are foundational.

### Stretch goal: "Send test email" button

Verifies the catch-all domain is actually routing before creating 36 users. Sends a timestamped message to `test-<timestamp>@<demo-domain>` via WP mail, returns whether SMTP send succeeded (not delivery — WP can't know). Included in the budget at 0.5h. First thing cut if time runs out.

### Touches

- New module: `src/Modules/DemoData/` (full module structure — Admin page, Generators, seeds, DemoBatchRegistry, DemoDataCleaner)
- Extension: `src/Infrastructure/Query/QueryHelpers.php` — new `apply_demo_scope()` method, new `get_team()` helper if not added already by #0015
- Audit pass: every `$wpdb->get_*()` call in `src/Modules/*/`, `src/Shared/Frontend/`, and `includes/REST/` must route through the scope helper
- New migration: next number in `database/migrations/` (likely `0012_*`)
- New menu: Tools → TalentTrack Demo, gated by `manage_options`
- No frontend surfaces. Permanently wp-admin.

### Sequence and companion work

- Blocks: the 4 May 2026 demo.
- Depends on: nothing. Can start immediately.
- Sister item: **#0015** (fatal My profile bug) must ship alongside — a crash during the demo on a named demo player is catastrophic. See `specs/0015-bug-frontend-my-profile-undefined-method.md`.
- Deferred: **#0007** (drag-reorder bug) and **#0008** (Node 20 deprecation) are Phase 0b, post-demo.

### Estimated effort

Full scope: ~24.5 hours of driver time with Claude Code. Plus #0015: ~2 hours. Total ~26.5 hours against a 22-hour window through May 4 (2 hrs/day × 11 days). We're ~4 hours over on paper but proceeding per the "try May 4, slip to May 11 if needed, no preemptive cuts" decision.
