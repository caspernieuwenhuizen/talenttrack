# TalentTrack v3.110.201 — 8 missing lookup_types added to the frontend admin grid (closes #831)

## Why

The frontend admin's lookup grid at `?tt_view=configuration&config_sub=lookups` shipped 10 tiles. The database has eighteen distinct `lookup_type` rows that the renderer reads through `LookupTranslator`. The 8 unregistered types were operator-visible labels that an academy operator could not extend or translate without writing SQL — the very gap #798 closed for the registered set.

This release adds the missing 8 as tiles. No migration, no schema, no REST contract change. The rows already exist; only the registry entry + index card were missing.

## The 8 added categories

| Tile slug | `lookup_type` | Where it surfaces |
|---|---|---|
| `activity_statuses` | `activity_status` | Activity list status pills (Draft / Scheduled / Conducted) |
| `cert_types` | `cert_type` | Staff certifications (UEFA-A/B/C, First aid, GDPR, Child safeguarding…) |
| `tournament_formations` | `tournament_formation` | Tournament configuration form |
| `tournament_opponent_levels` | `tournament_opponent_level` | Tournament configuration form |
| `behaviour_ratings` | `behaviour_rating_label` | Player behaviour card + evaluation review step |
| `potential_bands` | `potential_band` | Player potential card + evaluation review step |
| `journey_event_types` | `journey_event_type` | Player journey timeline (trial / signing / release / graduation / …) |
| `competition_types` | `competition_type` | Match / competition pickers |

## Change

`src/Shared/Frontend/FrontendConfigurationView.php` —

- `renderLookupsIndex()` gains 8 new `$cards[]` rows. Each card opens the dedicated frontend editor at `?config_sub=lookups&category=<slug>` (the existing routing — no new code path).
- `lookupCategoryMeta()` gains 8 matching registry entries. `show_desc=true` on the four where the description column is operator-meaningful (cert_type, behaviour_rating_label, potential_band, journey_event_type, tournament_opponent_level); `show_color=true` on `activity_status` only (its pills are colour-coded list-side).
- Icons chosen from the existing set: `workflow`, `rate-card`, `kanban`, `podium`, `profile`, `categories`, `track`, `methodology`. No new SVG assets needed.

## Auth + REST

Cap check is unchanged — `LookupsRestController` is `lookup_type`-agnostic and gated on `tt_edit_settings`, which the frontend cap matrix already grants to `academy_admin` / `head_of_development`. So the editing surface lights up for the same roles that already maintain the original 10.

## Translations

Per-locale name and description fields render exactly as for the existing categories. `behaviour_rating_label` and `potential_band` already carry Dutch descriptions seeded by `0060_seed_lookup_translations_nl.php`; those surface automatically. The fr/de/es backfill (separate work, tracked in #829) covers all 18 types — the 8 newly-exposed categories will pick up their translations on the same path.

## What this is not

- This is not the master-detail layout rewrite (#830) — the editor still renders single-column linear. Adding the tiles unblocks the editing surface; the layout polish is its own ticket.
- This is not "make the stored values translatable" — they already are. The bug was the missing maintenance surface.

## How to test

1. Log in as academy_admin → Configuration → Lookups.
2. Confirm 18 tiles now appear (10 existing + 8 new + Rating scale). New tiles: Activity statuses, Certification types, Tournament formations, Opponent levels, Behaviour ratings, Potential bands, Journey event types, Competition types.
3. Open *Behaviour ratings* — table shows the five seeded rows (Concerning … Exemplary) with their descriptions; Add / Edit / Delete + per-locale translation block work the same as for any existing category.
4. Open *Activity statuses* — pills render colour swatches in the table because `show_color=true`.
5. Switch site locale to Dutch → tiles + table labels render through `LookupTranslator::name()`. Behaviour-rating descriptions appear in Dutch because 0060 seeded them.
