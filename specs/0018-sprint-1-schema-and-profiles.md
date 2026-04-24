<!-- type: feat -->

# #0018 Sprint 1 — Schema + seed role profiles

## Problem

Foundation sprint. The compatibility engine (Sprint 2) and formation board (Sprint 3) both need the underlying data model to exist: formations, role profiles, playing styles, and historical team-membership data. This sprint creates the tables, seeds defaults, registers capabilities — no user-visible features yet.

## Proposal

Four new tables via one migration, seeded with a neutral default formation (4-3-3) plus three named style profiles (possession, counter, press-heavy). Three new capabilities for managing team-development surfaces.

## Scope

### Schema

**`tt_team_formations`** — a team's current formation assignment:
```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
team_id BIGINT UNSIGNED NOT NULL,
formation_template_id BIGINT UNSIGNED NOT NULL,
assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
assigned_by BIGINT UNSIGNED NOT NULL,
UNIQUE KEY uk_team (team_id)
```

**`tt_team_playing_styles`** — per-team style blend (0-100 each dimension, summing to 100):
```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
team_id BIGINT UNSIGNED NOT NULL,
possession_weight INT UNSIGNED NOT NULL DEFAULT 33,
counter_weight INT UNSIGNED NOT NULL DEFAULT 33,
press_weight INT UNSIGNED NOT NULL DEFAULT 34,
updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
updated_by BIGINT UNSIGNED NOT NULL,
UNIQUE KEY uk_team (team_id)
```

**`tt_formation_templates`** — shipped + custom formations:
```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(64) NOT NULL,          -- '4-3-3 Neutral', '4-3-3 Possession'
formation_shape VARCHAR(16) NOT NULL, -- '4-3-3', '4-4-2', '3-5-2'
slots_json TEXT NOT NULL,            -- JSON array: [{slot_label:'CDM', required_eval_categories:{...weights...}}, ...]
is_seeded BOOLEAN DEFAULT FALSE,
created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
archived_at DATETIME DEFAULT NULL
```

**`tt_player_team_history`** — archival record for "players on team together" bonus in chemistry:
```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
player_id BIGINT UNSIGNED NOT NULL,
team_id BIGINT UNSIGNED NOT NULL,
joined_at DATE NOT NULL,
left_at DATE DEFAULT NULL,
KEY idx_player (player_id),
KEY idx_team (team_id)
```

**Optional extension to `tt_players`**: new nullable columns for side-preferences per position (e.g. `position_left_preference BOOLEAN`, `position_right_preference BOOLEAN`, `position_center_preference BOOLEAN`). Deferred to Sprint 2 if clean implementation emerges; not schema-blocking.

### Seed data

Four formation templates inserted on activation:

1. **Neutral 4-3-3** — balanced weights across evaluation categories. Each of the 11 slots has a role profile that weighs a reasonable mix of physical/technical/tactical/mental categories.
2. **Possession 4-3-3** — ball-playing CBs (Technical weight ↑), technical wingers (Dribbling + Passing ↑), playmaking CDM (Passing + Decision-making ↑).
3. **Counter 4-3-3** — physical CBs (Physical ↑), pacy wingers (Physical + Finishing ↑), defensive-midfielder CDM (Defending + Physical ↑).
4. **Press-heavy 4-3-3** — high-work-rate attackers (Physical + Mental/workrate ↑), defensively-active midfield (Defending + Stamina ↑), line-high-defending CBs.

Each template's `slots_json` is a structured role profile — ~30-40 lines of JSON per template. These are clear-cut defaults; clubs can edit in Sprint 4 or add their own.

Details of each profile go in the seed data implementation — not this spec's job to exhaustively document weights. Principle: weights within a slot sum to 1.0; across-slot weights are independent.

### Capabilities

Three new capabilities registered in `Activator.php`:

- `tt_view_team_chemistry` — view formation boards and chemistry scores. Granted to `tt_coach`, `tt_head_dev`, `administrator`.
- `tt_manage_team_chemistry` — edit team formations, style blends, pairing overrides. Granted to `tt_head_dev`, `administrator`.
- `tt_manage_formation_templates` — create/edit formation templates (admin surface in Sprint 4). Granted to `tt_head_dev`, `administrator`.

### Team history backfill

On migration, backfill `tt_player_team_history`:
- For every current `tt_players` row with a `team_id`, create a history row with `joined_at = player.created_at`, `left_at = NULL`.
- Flag: backfill isn't perfect — it doesn't reconstruct historical team moves before the plugin tracked them. Acceptable for v1; the chemistry bonus degrades gracefully for short history.

### API

REST endpoint stubs (implementation in subsequent sprints):
- `GET /talenttrack/v1/teams/{id}/formation` — team's current formation
- `PUT /talenttrack/v1/teams/{id}/formation` — assign a formation template
- `GET /talenttrack/v1/teams/{id}/style` — style blend
- `PUT /talenttrack/v1/teams/{id}/style` — update style blend

Stubs enforce capabilities but return placeholder data. Sprint 2 implements the logic.

## Out of scope

- **Compatibility engine logic.** Sprint 2.
- **Formation board UI.** Sprint 3.
- **Chemistry aggregation.** Sprint 4.
- **Player-side integration.** Sprint 5.
- **Formation template editor UI.** Sprint 4.
- **Side preferences on tt_players.** Maybe Sprint 2 if clean; defer otherwise.

## Acceptance criteria

- [ ] Migration creates all four tables on fresh install and upgrade.
- [ ] Four formation templates seeded with correct `slots_json` structures.
- [ ] Capabilities registered and defaults granted correctly.
- [ ] Backfill populates `tt_player_team_history` for existing players.
- [ ] REST stubs exist at the specified paths.
- [ ] No regression to existing player/team/evaluation functionality.

## Notes

### Sizing

~8–10 hours. Breakdown:
- Migration (4 tables + seed + backfill): ~3 hours
- Formation template JSON authoring (4 templates, ~40 lines each, careful weight selection): ~3 hours
- Capability registration + defaults: ~1 hour
- REST stubs: ~1 hour
- Testing: ~1.5 hours

### Depends on

Nothing hard. First sprint in the epic.

### Blocks

All other sprints in this epic.

### Touches

- New migration: `NNNN_create_team_development.php`
- `includes/Activator.php` — capability registration, seed execution, backfill
- `src/Modules/TeamDevelopment/TeamDevelopmentModule.php` (new module bootstrap)
- `includes/REST/TeamDevelopment_Controller.php` (new stubs)
