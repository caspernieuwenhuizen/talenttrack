<!-- type: epic -->

# 0096 — Training module: session planning, exercise library, and player training exposure

> **Draft 0.2 · 2026-08-17** — end-to-end design, shaping complete. Eleven
> product decisions are locked (see `## Decisions` and the decisions log at
> the bottom); no open design questions remain. Mockups for every surface
> live in `.local-mockups/training/`.

## Problem

TalentTrack measures players constantly — attendance, minutes, evaluations,
goals, PDP cycles, measurements — but holds **no record of what a player was
actually taught**. The input side of development is missing entirely.

Coaches plan their training on paper, in Word, or in a competitor's app. The
plan is thrown away after the session. Consequences:

- No coach can answer "which principles has this player actually been trained
  on this season?" — so evaluations and PDP reviews rest on memory.
- A head of development cannot see whether the academy's methodology is being
  taught or merely published. The Methodology library is beautifully authored
  and completely disconnected from what happens on the pitch on Tuesday.
- Planning knowledge does not accumulate. A coach who leaves takes the drill
  bank with them.
- Coaches already run a second app for session planning, which is the single
  most common reason a club keeps a competitor product alongside TalentTrack.

The plumbing for the answer is already in the repo and idle (see
`## Feasibility baseline`).

## Proposal

A `Training` module that makes the **training plan** a first-class,
reusable record, composed by a generator from the academy's own methodology,
run against an activity, and reduced afterwards into **per-player training
exposure**.

Four layers, each useful on its own:

1. **One exercise library** — the merged catalogue, principle-tagged, with
   animated tactical diagrams.
2. **Training plans** — standalone reusable records of ordered blocks,
   pinned to specific exercise versions.
3. **The generator** — team plus date plus theme produces a proposed plan,
   which the coach edits. Never a blank page.
4. **The player connection** — a plan run against an activity plus its
   attendance yields per-player principle exposure, feeding evaluations,
   goals and PDP.

Layer 4 is the reason this belongs in TalentTrack. Layers 1 to 3 alone build
a drill app that a dozen vendors already sell.

## Feasibility baseline — what already exists

This is a UI epic plus one consolidation, not a module from zero.

**Epic #0016 (photo-to-session capture) shipped its entire data and API layer
and stopped before any screen was built.** `grep -i exercise src/Modules/Activities`
returns nothing.

| Shipped | Where |
| --- | --- |
| `tt_exercises` — versioned via `superseded_by_id`, club/team/private visibility, `diagram_url`, `duration_minutes` | `database/migrations/0088_exercises_foundation.php` |
| `tt_exercise_categories` (8 seeded), `tt_exercise_principles` (M2M to methodology), `tt_exercise_team_overrides` | same |
| `tt_activity_exercises` — ordered drill rows per activity, `actual_duration_minutes`, `is_draft` | `0089_activity_exercises.php` |
| 18 seeded drills | `0090_seed_exercise_library.php` |
| `ExercisesRestController`, `ActivityExercisesRestController` — full CRUD plus bulk-replace | `src/Infrastructure/REST/` |
| `POST /vision/extract` — real `ClaudeSonnetProvider` (Bedrock eu-central-1), Gemini/OpenAI fallback chain, `ExerciseFuzzyMatcher` | `VisionExtractRestController.php`, `src/Modules/Exercises/Vision/` |
| GDPR Art. 35 DPIA template | `docs/photo-capture-dpia.md` |

**Epic #0095 (VCT) shipped a complete, working session-building experience**,
scoped to conditioning: `NewVctSessionWizard` (when → theme → duration →
preview → review), `VctTrainingComposer` plus an eight-pass `RulesEngine`, a
sideline coach view, an A4 print view, and an HoD library editor. It carries
its **own second exercise catalogue** (`tt_vct_exercises`) that shares nothing
with `tt_exercises`.

**Methodology (#0027, #2316) supplies the "why"**: coded principles and
sub-principles per game phase and per line, football actions, and a
Periodisation tab that already resolves *this week's tactical theme* per team
from the macro-block calendar.

Six of the RulesEngine's eight passes carry over to general training
composition unchanged (see `## Wave 4`).

## Decisions

| # | Decision | Choice | Consequence |
| --- | --- | --- | --- |
| D1 | Exercise library | **One library.** Fold the VCT attribute columns into `tt_exercises`, backfill `tt_vct_exercises`, repoint the VCT engine. | One migration, one authoring surface, one place every later feature looks. VCT regression suite is the gate. |
| D2 | Diagram authoring | **Full animated editor.** Author-able tactical scenes reusing the Speelwijze SVG renderer. | Largest single build in the epic. Sequenced last so it never gates the planner; wave 2 ships image-upload diagrams as the interim. |
| D3 | Plan entity | **Standalone reusable plan**, attachable to zero or many activities. | Templates and cross-team reuse work naturally. Requires an explicit run entity so player data still has somewhere to live — see `## Player-centricity`. |
| D4 | Primary entry point | **Generator-first.** Team + date + theme → proposal → edit. | Kills the blank page. Requires enough library content to compose from, which wave 1's merge plus the shipped methodology packs provide. |
| D5 | Plan history | **Snapshot-only.** A plan is mutable and carries no version chain; the run's `blocks_snapshot_json` is the entire history. | No `version` / `superseded_by_id` on plans, no latest-version filter in every list query. Accepted cost: "what did this template look like in October" is unanswerable except through the runs. |
| D6 | Scenes and §3 | **A scene is a field of the exercise, not a sub-entity.** Same category as its diagram image or coaching points. | No wizard, no exemption, no new exemption class in CLAUDE.md §3. The canvas is an editor for an exercise attribute, reached from the exercise's own edit surface. |
| D7 | Observation instrument | **The install's configured evaluation scale** (5–9 step 1 on the pilot install). | Training observations aggregate with evaluations on one scale. Forces a segmented full-width control on the sideline rather than a per-row triple — see `## Sideline observation control`. |
| D8 | Guest exposure | **Counted, on the guest's own player file.** | They were taught those principles; it is real exposure. Matches how guest attendance already behaves in Activities. |
| D9 | Exercise authoring | **Coaches author team-scoped; the head of development promotes to club-wide.** | New exercises default to `visibility='team'`. The library gains an HoD promotion queue and a `POST /exercises/{id}/promote` route. The generator draws from club-wide plus the team's own, which `ExercisesRepository::listForTeam()` already implements. |
| D10 | Dashboard placement | **One `Training` tile** in the coach group, opening the plans list. The exercise library is a header action from there and from the builder's picker. | One new `TileRegistry` entry, not two. §5b holds: the shell renders the nav, the view never emits module-level links. |

## Player-centricity (CLAUDE.md §1)

**Which player question does this answer?** *What has this player actually
been taught, and does their training match their development plan?*

A session planner is inherently team-centric, and D3 sharpens that risk: a
reusable template has no player in it at all. The resolution is a strict
split:

- The **plan** (`tt_training_plans`) is the template. Team-shaped, reusable,
  versioned. No player data.
- The **run** (`tt_training_plan_runs`) is one execution of a plan against one
  activity on one date. This is the player-bearing record. It snapshots the
  plan's blocks so later edits to the plan never rewrite history.

`tt_exercise_principles` is the join that makes the player connection work in
all three directions:

- **Before the session** — the plan builder shows *"4 of your players have an
  open goal or PDP action on AO-01; this session covers it"*, sourced from
  `tt_goals` and `tt_pdp_*` joined on `principle_id`. Planning becomes
  goal-driven, not drill-driven.
- **During the session** — the sideline view lets a coach tap a block and
  record a per-player observation against the block's principle. It lands on
  the player timeline (`Journey`), not in a notebook.
- **After the season** — run blocks × activity attendance yields **per-player
  principle exposure in minutes**. Surfaced on the player file, in the
  evaluation flow ("you are rating AO-01; this player has had 240 minutes on
  it across 11 sessions"), and as an HoD coverage report.

Wave 7 is therefore not polish. An epic that ships waves 1 to 6 and stops has
built a drill app.

## Naming

Two collisions to settle before any code is written:

- **`training` is already an activity type key.** An activity of type
  `training` is a calendar event; a training plan is a document. The UI keeps
  them apart by never calling a plan a "training": Dutch copy uses
  **trainingsplan** for the template, **training** only for the calendar
  event, and **uitvoering** for the run.
- **Parked spec `0087` used "training instance" to mean a sandbox WordPress
  install.** Unrelated concept, same word. Renamed to **sandbox instance**
  (`specs/parked/0087-feat-sandbox-instance.md`) as part of this shaping, so
  `docs/training-*.md` is unambiguous. `SEQUENCE.md`'s 0087 row was rewritten
  to match. One shipped string still uses the old sense —
  `FeatureToggleService`'s demo-install toggle description mentions "pilot /
  training / ephemeral demo installs". It is deliberately left alone: editing
  it would churn a translated msgid across five locales for no user-visible
  gain. Fix it opportunistically the next time that file is touched.

The entity name in code is `TrainingPlan`; tables are `tt_training_plans`,
`tt_training_plan_blocks`, `tt_training_plan_runs`. The `Session…Generator`
token stays retired (#0035); the composer is `TrainingPlanComposer`.

## Data model

All new tables carry `club_id INT UNSIGNED NOT NULL DEFAULT 1` and root
entities carry `uuid CHAR(36)` per CLAUDE.md §4.

### Wave 1 — `tt_exercises` extended (D1)

Columns added, all nullable so the 18 existing rows and every club-authored
row stay valid:

| Column | Type | Source |
| --- | --- | --- |
| `code` | `VARCHAR(64) NULL` | VCT |
| `tactical_theme` | `VARCHAR(64) NULL` | VCT — lookup-backed |
| `intensity_band` | `TINYINT UNSIGNED NULL` | VCT — 1..5 |
| `duration_minutes_min` / `_max` | `SMALLINT UNSIGNED NULL` | VCT (existing `duration_minutes` becomes the default) |
| `players_min` / `players_max` | `TINYINT UNSIGNED NULL` | VCT |
| `md_suit_mdminus4` … `md_suit_mdplus2` | 8 × `TINYINT UNSIGNED NULL` | VCT — kept denormalised for the hot-path index (see 0122's architecture note H5) |
| `pitch_preset` | `VARCHAR(24) NULL` | new — `full`/`half`/`third`/`box`, drives the scene editor |
| `source` | `VARCHAR(20) NOT NULL DEFAULT 'club'` | new — `club`/`shipped`/`vct`, so shipped content stays read-only |

`tt_vct_exercises` rows are backfilled into `tt_exercises` with
`source='vct'`, keeping their `uuid` so `tt_vct_session_blocks.exercise_id`
and `tt_vct_coaching_points.exercise_id` can be remapped in the same
migration. `VctExercisesRepository` is repointed at the merged table behind
its existing interface; the rules engine is untouched. The old table is left
in place, empty and unread, until the release after (drop is a separate,
reversible migration).

`category_key VARCHAR(64)` is carried denormalised alongside `category_id`
for the same reason the MD flags are: the candidate query seeks on
`(club_id, archived_at, category_key, intensity_band, age_min, age_max)`, and
joining `tt_exercise_categories` per candidate would defeat the covering
index. `category_id` remains the authoring-side reference.

**Cap retirement moves to wave 2.** `tt_vct_admin_library` is referenced in
eight source files including `RolesService` and `LegacyCapMapper`; folding it
into `tt_manage_exercises` is an authorization change that belongs with the
merged authoring surface, not inside a migration PR.

One new companion table (wave 8):

```
tt_exercise_scenes                       -- D2
  id, uuid, club_id, exercise_id, name, pitch_preset,
  duration_ms SMALLINT UNSIGNED, scene_json LONGTEXT,
  sort_order, is_primary, created_at, updated_at
```

**Coaching points are not renamed.** `tt_vct_coaching_points` stores its text
as a `code` resolved through `tt_translations` on
`(entity_type='vct_coaching_point', entity_id=cp.id, field='text')` — the
pattern #902 established to avoid JSON-trapped translations. Moving those rows
to a new table would change `entity_id` and orphan every translation, for a
cosmetic gain. Wave 1 therefore only repoints `tt_vct_coaching_points.exercise_id`
at the merged exercise ids; the table keeps its name and its translation keys.
Wave 2 adds the club-authoring path on top (a nullable free-text column
alongside `code`, so shipped points stay translatable and coach-authored points
are stored literally).

### Wave 3 — plans, blocks and runs (D3)

```
tt_training_plans
  id, uuid, club_id,
  title, notes,
  team_id NULL,                -- NULL = club-wide template
  age_group_key NULL, season_id NULL,
  theme_key NULL,              -- lookup: tactical theme
  total_duration_minutes,
  intensity_target TINYINT NULL,
  is_template TINYINT DEFAULT 0,
  visibility VARCHAR(20) DEFAULT 'club',     -- mirrors tt_exercises
  source VARCHAR(20) DEFAULT 'manual',       -- generated|manual|photo|duplicated
  author_user_id,
  archived_at NULL, created_at, updated_at
  -- D5: no version chain. A plan is mutable; the run's snapshot is the history.

tt_training_plan_blocks
  id, uuid, club_id, plan_id, order_index,
  block_type VARCHAR(24),      -- warmup|rondo|main|game|finishing|cooldown|talk
  exercise_id NULL,            -- pinned to a specific tt_exercises row (version-immutable)
  title_override NULL, organisation TEXT NULL, coaching_points TEXT NULL,
  duration_minutes, intensity_band NULL,
  players_min NULL, players_max NULL
  UNIQUE (club_id, plan_id, order_index)

tt_training_plan_principles      -- M2M; rows derived from the blocks' exercises,
  id, club_id, plan_id, principle_id, is_manual
                                 -- plus manual additions the coach pins

tt_training_plan_runs            -- the player-bearing record
  id, uuid, club_id, plan_id, activity_id, team_id,
  run_date, status VARCHAR(16),  -- planned|running|completed|abandoned
  blocks_snapshot_json LONGTEXT, -- immutable copy of the blocks at run start
  started_at NULL, completed_at NULL, created_at
  UNIQUE (club_id, activity_id)  -- one run per activity

tt_training_plan_run_blocks
  id, club_id, run_id, plan_block_id NULL, order_index,
  actual_duration_minutes NULL, was_skipped TINYINT DEFAULT 0, notes TEXT NULL

tt_training_observations         -- the player link, wave 7
  id, uuid, club_id, run_id, run_block_id NULL, player_id,
  principle_id NULL, football_action_id NULL,
  rating DECIMAL(3,1) NULL,     -- D7: the install's configured evaluation scale
  note TEXT NULL, author_user_id, created_at
```

`rating` is nullable throughout — a note with no score is a valid
observation, and on a wet Tuesday it is the common one. The column is
`DECIMAL(3,1)` rather than `TINYINT` because the scale is operator-configured
and some installs use half steps; the pilot install is 5–9 step 1.

### Sideline observation control (D7)

Five targets per player row does not fit beside a name and an avatar at
360px. The sideline sheet therefore renders each player as **two stacked
rows** — name and context above, a full-width segmented scale below — rather
than a single row with a cramped control. Each segment is a ≥48px target,
five across a 360px viewport gives roughly 64px each, and the scale reads
left-to-right in one glance. The segment set is generated from the install's
configured scale, so an academy on 1–10 gets ten segments that wrap to two
rows rather than a hard-coded five.

### Wave 7 — the exposure aggregate

Derived, not authored. One row per player × principle × season, rebuilt
nightly by a workflow task template (same pattern as
`VctWorkloadAggregationTaskTemplate`):

```
tt_player_principle_exposure
  id, club_id, player_id, principle_id, season_id,
  minutes_total, sessions_count, last_trained_on DATE,
  computed_at
  UNIQUE (club_id, player_id, principle_id, season_id)
```

Source query, in prose: for every completed run, for every run block carrying
an exercise tagged with principle P, for every player whose attendance on the
run's activity is a present-like status, add the block's
`actual_duration_minutes` (falling back to planned) to that player's P total.
Excused, absent and injured are excluded.

**Guests are included (D8)** and accrue on their own player file, not the host
team's. They were on the pitch being taught those principles, so it is real
exposure; a player who guests upward every week would otherwise show
artificially low training minutes. The HoD coverage matrix aggregates by the
*run's* team, so guest minutes appear under the team that ran the session —
the two views answer different questions and both are correct.

This is the table that answers *"which principles has this player actually
been trained on?"* — one indexed read, no runtime aggregation on the player
file.

## REST surface

Namespace `talenttrack/v1`. Every route declares a capability
`permission_callback`; none use `__return_true`.

| Route | Verbs | Cap |
| --- | --- | --- |
| `/exercises`, `/exercises/{id}` | GET, POST, PATCH, DELETE | existing — `tt_view_activities` / `tt_manage_exercises` |
| `/exercises/{id}/promote` | POST — team-scoped to club-wide (D9) | `tt_manage_exercises` at **global** scope |
| `/exercises/{id}/scenes`, `/scenes/{id}` | GET, POST, PATCH, DELETE | `tt_manage_exercises` |
| `/exercises/{id}/coaching-points` | GET, PUT | `tt_manage_exercises` |
| `/training/plans` | GET, POST | `tt_training_plan` |
| `/training/plans/{id}` | GET, PATCH, DELETE | `tt_training_plan` |
| `/training/plans/generate` | POST | `tt_training_plan` |
| `/training/plans/{id}/duplicate` | POST | `tt_training_plan` |
| `/training/plans/{id}/blocks` | GET, PUT (bulk replace) | `tt_training_plan` |
| `/training/runs` | POST (attach plan to activity) | `tt_training_plan` |
| `/training/runs/{id}` | GET, PATCH | `tt_training_plan` |
| `/training/runs/{id}/blocks/{bid}` | PATCH | `tt_training_plan` |
| `/training/runs/{id}/observations` | GET, POST | `tt_training_plan` |
| `/players/{id}/training-exposure` | GET | `tt_view_players` + `tt_training_view_exposure` |
| `/teams/{id}/training-exposure` | GET | `tt_training_view_exposure` |
| `/activities/{id}/training-plan` | GET | `tt_view_activities` |

The existing `/activities/{id}/exercises` routes stay, serving the ad-hoc
"just list some drills on this activity" path for coaches who never adopt
plans. A run, when present, is the authoritative source; the older linkage is
read-through.

`docs/rest-api.md` and `docs/openapi.yaml` are updated per wave.

## Capabilities

Three new matrix-only caps, no role baselines, seeded in
`config/authorization_seed.php`:

| Cap | head_coach | assistant_coach | head_of_development | admin |
| --- | --- | --- | --- | --- |
| `tt_training_plan` | team | team | global | global |
| `tt_training_view_exposure` | team | — | global | global |
| `tt_manage_exercises` (exists) | team | — | global | global |

`tt_vct_admin_library` is retired into `tt_manage_exercises` by wave **2**
because after the merge there is one library to administer.

## Surfaces

**One new `TileRegistry` entry (D10)**: a `Training` tile in the coach group,
opening the plans list. The exercise library, the coverage report and the
generator are all reached from inside that surface as header actions — never
as a view-emitted list of module links, which §5b forbids.

Every routable view emits exactly the two nav affordances of CLAUDE.md §5a —
`FrontendBreadcrumbs::fromDashboard()` plus the `tt_back` pill — and no
module-level navigation (§5b). Record-scoped tabs come from `RecordSpine`
(§5c). Mockup for each is in `.local-mockups/training/`.

| Slug | Surface | Persona | Mockup |
| --- | --- | --- | --- |
| `?tt_view=training-plans` | Plan list — Mine / Team / Templates, FilterBar chrome | Coach | `plans-list.html` |
| `?tt_view=wizard&slug=new-training-plan` | Generator wizard, 5 steps | Coach | `generator.html` |
| `?tt_view=training-plan&id=N` | Plan detail — RecordSpine tabs Plan / Runs / Coverage | Coach | `plan-detail.html` |
| `?tt_view=training-plan&id=N&mode=build` | Plan builder — reorder, swap, timeline | Coach | `plan-builder.html` |
| `?tt_view=training-run&id=N` | Sideline run view, offline-capable | Coach | `coach-view.html` |
| `?tt_view=training-plan&id=N&print=1` | A4 print | Coach | `print.html` |
| `?tt_view=exercises` | Exercise library | Coach / HoD | `library.html` |
| `?tt_view=exercise&id=N` | Exercise detail, scene player, usage history | Coach | `exercise-detail.html` |
| `?tt_view=exercise&id=N&mode=scene` | Animated diagram editor | HoD | `diagram-editor.html` |
| `?tt_view=players&id=N&tab=training` | Player training exposure section | Coach | `player-exposure.html` |
| `?tt_view=training-coverage` | Squad/methodology coverage report | HoD | `coverage-report.html` |

## Wizard plan (CLAUDE.md §3)

| Slug | Status | Notes |
| --- | --- | --- |
| `new-training-plan` | **new wizard** | Five steps: `when` (team + date, defaults to the next training activity on the calendar), `theme` (prefilled from the periodisation week, with the open-goal coverage hint), `shape` (duration slider from the age profile + expected squad size), `proposal` (generated blocks as swappable cards), `review` (intensity curve, principle coverage, warnings, save / save-as-template / attach-to-activity). Standard `WizardChrome`; §6 Save+Cancel exempt via (c). Flat-form fallback at `?tt_view=training-plan&action=new`, gated by `WizardEntryPoint::urlFor()`. |
| Exercise create/edit | **exemption (a)** | Each exercise is a tagged library row — vocabulary, not a narrative record. Inline create/edit form on the library surface with Save+Cancel, matching the shipped VCT library editor. |
| Scene create/edit | **§3 does not apply (D6)** | A scene is a *field of the exercise* — the same category as its diagram image or its coaching-point list — that happens to be edited on a canvas rather than in a text input. No record is created, so the wizard-first rule is not engaged and no exemption is claimed. The canvas is reached from the exercise's own edit surface; its three settings (name, pitch preset, duration) live in the canvas header alongside the drawing. |
| Attach plan to activity | **exemption (b)** | Operation on existing records — picks an existing plan and an existing activity. Single-screen picker with Save+Cancel. |

## Scope — nine waves

Each wave is its own child issue, shaped separately. Wave 1 blocks
everything; waves 8 and 9 are deliberately last.

### Wave 1 — Library consolidation (foundation)

Migration extending `tt_exercises` with the VCT attributes; backfill every
`tt_vct_exercises` row into it preserving `uuid`; remap
`tt_vct_session_blocks.exercise_id` and `tt_vct_coaching_points.exercise_id`
to the new ids; repoint `VctExercisesRepository` at the merged table behind
its existing interface. A generation smoke test on a seeded club — identical
block list before and after — is the acceptance gate. **Runs alone** — schema
change, per AGENTS.md.

### Wave 2 — Exercise library UI

List with FilterBar (search, category, principle, theme, intensity band,
player count), inline create/edit per exemption (a), exercise detail with
usage history ("used in 7 plans, last on 12 Aug"), image-upload diagrams as
the interim before wave 8.

**Plus the D9 authoring model**: a coach's new exercise defaults to
`visibility='team'` and is immediately usable in their own plans. The library
surface gains an HoD-only **promotion queue** — team-scoped exercises awaiting
a decision, each promotable to club-wide or left where it is — backed by
`POST /exercises/{id}/promote` and audit-logged. `listForTeam()`'s existing
visibility rules already give the generator club-wide plus own-team
candidates, so no query changes.

### Wave 3 — Plan entity, REST and list

`tt_training_plans` / `_blocks` / `_principles` / `_runs` / `_run_blocks`,
their repositories, the REST routes above, and the plan list plus a
read-only plan detail. No builder yet — a plan can be created via REST and
read in the UI.

### Wave 4 — The generator

`TrainingPlanComposer` plus the `new-training-plan` wizard. Generalises the
VCT `RulesEngine` beyond conditioning. Six of eight passes carry over
unchanged — `AgeAdmissibilityRule`, `MdContextRule`, `RecoveryRule`,
`ProgressionRule`, `WorkloadCapRule`, `FinalizationPass`. `TacticalThemeRule`
widens from the VCT theme vocabulary to the full methodology theme lookup.
`ExerciseSelectionPass` widens its candidate query to the merged table. Two
new passes:

- **`PrincipleCoveragePass`** — ranks candidates by how many of the squad's
  open goals and PDP actions touch the exercise's principles. This is what
  makes the generator player-aware rather than merely age-safe.
- **`VarietyPass`** — penalises exercises this team ran in the last N
  sessions, so the generator does not propose the same rondo every week.

Blocking warnings prevent persistence and return the structured `reasons[]`
envelope, exactly as `VctTrainingComposer` does today.

### Wave 5 — Plan builder UI

Edit a composed plan: reorder blocks (drag on desktop, up/down buttons on
touch — §2 requires a non-gesture fallback), swap a block's exercise from the
library picker, adjust durations against a live total, add a block, save as
template, duplicate last week's plan. Live intensity curve and principle
coverage strip update as the coach edits.

### Wave 6 — Run it: attach, sideline view, print

Attach a plan to a training activity, creating a run with its block snapshot.
Sideline view: large type, one block at a time, elapsed timer against planned
duration, mark-block-done, offline-capable via the existing service worker
plus an IndexedDB write queue. A4 print reusing `Print/` patterns from VCT
and Planning.

### Wave 7 — The player connection

Open-goals-on-this-principle panel in the builder and the wizard's review
step; per-block observation capture in the sideline view writing to
`tt_training_observations` and surfacing on the player Journey; the nightly
exposure aggregation task; `?tt_view=players&id=N&tab=training`; the HoD
coverage report; and the exposure hint inside the evaluation flow.

### Wave 8 — Animated diagram editor (D2)

`scene_json` contract:

```json
{
  "pitch": "half",
  "duration_ms": 6000,
  "actors": [
    { "id": "p6", "kind": "player", "label": "6", "side": "own",
      "keyframes": [ { "t": 0, "x": 22, "y": 50 }, { "t": 2200, "x": 44, "y": 34 } ] }
  ],
  "links": [ { "from": "p6", "to": "p10", "kind": "pass", "t": 2200 } ]
}
```

Coordinates are 0–100 in pitch space so a scene scales from a 360px phone to
an A4 print without re-authoring. `kind` covers `player`, `opponent`, `ball`,
`cone`, `goal`, `keeper`. `links` covers `pass`, `dribble`, `run`, `shot`,
`press`. Playback reuses the shipped Speelwijze scene renderer
(`assets/css/frontend-methodology-scene.css` and its JS) so authored scenes
and shipped tactical scenes render identically and both honour
`prefers-reduced-motion` — nothing autoplays.

Editor: SVG canvas with a drag-to-position actor palette, a timeline with
keyframe scrubbing, a link tool, and undo. Desktop-primary with a
read-and-reposition-only mobile fallback; authoring a scene on a 360px phone
is not a supported flow, and the surface says so rather than degrading badly.

### Wave 9 — Photo capture and review wizard

Activates the idle #0016 backend, unchanged: mobile capture UI with an
offline IndexedDB queue, and a confidence-coloured review grid that commits
through the existing bulk-replace route into a draft plan. **Gated on the
DPIA legal review**, which is outstanding calendar-time work, not engineering.

## Out of scope

- **Video.** Exercise video upload, clip libraries, and match-clip linking are
  a separate epic. `diagram_url` plus scenes cover the visual need for now.
- **Cross-club exercise marketplace.** Sharing drills between academies is a
  SaaS-tier feature, not a plugin feature.
- **Live GPS / wearable load.** The Strava module already handles the one
  external load source in the product.
- **Automatic attendance from the sideline view.** Attendance stays where it
  is; the run reads it.
- **Retrofitting existing `tt_activity_exercises` rows into plans.** The old
  linkage keeps working; no migration of ad-hoc drill lists into plans.
- **Scheduling a plan across a whole macro-block in one action.** Season-wide
  plan scheduling is a natural follow-up once waves 1–7 land, not part of this
  epic.

## Acceptance criteria

**Epic-level, checked at wave 7:**

- A coach can go from the dashboard to a saved, activity-attached plan in
  under three minutes on a 360px viewport, starting from the generator.
- A player's file shows, for the current season, how many minutes and how
  many sessions they have been trained on each principle, with the most
  recent date.
- An HoD can see, per team, which methodology principles have had zero
  training minutes this season.
- Editing an exercise never changes what a past run shows.
- Deleting a plan never deletes its runs' history.

**Per wave:**

- **W1** — every VCT test passes against the merged table; a generated VCT
  session before and after the migration produces an identical block list on
  a seeded club; `tt_vct_exercises` is empty and unread.
- **W2** — library list renders at 360px with no horizontal scroll; all
  targets ≥ 48px; create/edit via inline form with Save+Cancel; search and
  every filter survive in the URL; a coach's new exercise lands at
  `visibility='team'` and appears in the HoD promotion queue; promoting it
  makes it club-wide and writes an audit-log entry; a coach without global
  scope cannot reach the promote route.
- **W3** — every route in `## REST surface` returns correct data with a
  capability-gated `permission_callback`; REST endpoint tests exist per the
  `rest-test-coverage` gate; a plan can be created, read, edited and archived
  without touching a view file.
- **W4** — generating with the same inputs twice on the same day produces the
  same plan (deterministic); a blocking warning returns 400 with structured
  reasons and persists nothing; a plan proposed for a U13 team never contains
  an exercise above the age profile's intensity ceiling.
- **W5** — blocks reorder by drag on desktop and by button on touch;
  duration total and intensity curve update without a page load; save-as-
  template produces a `team_id IS NULL` copy.
- **W6** — attaching a plan to an activity creates exactly one run;
  `blocks_snapshot_json` is written at attach time and never rewritten; the
  sideline view functions with the network disabled and syncs on reconnect;
  the A4 print fits a 75-minute plan on one page.
- **W7** — the nightly aggregation is idempotent; exposure totals match a
  hand-computed figure on the demo academy; observations appear on the
  player's Journey timeline within the same request; the observation scale
  matches the install's configured evaluation scale and re-renders correctly
  when an operator changes it; a rating-free note saves; a guest player's
  minutes appear on their own player file and under the host team in the
  coverage matrix.
- **W8** — a scene authored in the editor renders identically in the exercise
  detail, the sideline view and the A4 print; `prefers-reduced-motion` shows
  the final frame statically with a Play control.
- **W9** — a photographed plan produces a draft plan whose blocks the coach
  can accept or correct; nothing is persisted before the coach confirms; the
  DPIA is signed off before the surface is enabled.

**Every wave** additionally satisfies the CLAUDE.md §9 checklist: nl_NL.po
updated, `docs/<slug>.md` plus `docs/nl_NL/<slug>.md`, a
`changelog.d/` snippet, breadcrumbs on every code path including
permission-denied returns, Save+Cancel via `FormSaveButton::render()`,
`club_id` and `uuid` on new tables, business logic outside view files.

## Documentation

New: `docs/training-plans.md`, `docs/exercise-library.md`,
`docs/training-exposure.md`, `docs/exercise-diagrams.md` — each with a
`docs/nl_NL/` counterpart. Updated: `docs/activities.md` (the plan panel on a
training activity), `docs/methodology.md` (principles now drive exercise
selection), `docs/vct.md` (the library merge), `docs/rest-api.md`,
`docs/openapi.yaml`, `docs/authorization-matrix.md`, `docs/player-journey.md`
(observations as timeline events), `SEQUENCE.md`.

## Effort

The repo's documented conventional-to-actual ratio is roughly 1 : 2.5.

| Wave | Conventional | Expected actual |
| --- | --- | --- |
| 1 — library consolidation | 10–14h | 4–6h |
| 2 — library UI | 12–16h | 5–6h |
| 3 — plan entity + REST | 10–12h | 4–5h |
| 4 — generator + wizard | 16–20h | 6–8h |
| 5 — plan builder UI | 14–18h | 6–7h |
| 6 — run + sideline + print | 12–14h | 5–6h |
| 7 — player connection | 14–16h | 6–7h |
| 8 — animated diagram editor | 30–40h | 12–16h |
| 9 — photo capture + review | 20–24h | 8–10h |
| **Total** | **138–174h** | **56–71h** |

Waves 1 to 7 alone — a complete, player-connected planner without the
animation editor or photo capture — are **44–45h expected actual**.

## Risks

| Risk | Mitigation |
| --- | --- |
| The library stays empty and the generator has nothing to compose from. | Wave 1's merge imports the VCT catalogue immediately. Wave 2 ships "save this block to the library" from the builder. The shipped Hedel methodology packs get a matching exercise pack. Measured: the generator refuses to produce a plan and says why when a slot has no candidate, rather than silently emitting a thin session. |
| Wave 8 swallows the epic. | It is sequenced last and gated behind waves 1–7 shipping. Wave 2 ships image-upload diagrams so nothing waits on it. |
| Coaches use it once and revert to paper. | The three-minute criterion is an acceptance gate, not an aspiration. The generator, "duplicate last week", and the periodisation theme prefill all exist to remove typing. |
| Plans become a club compliance chore producing garbage data. | Wave 7 returns value to the coach in the same week — open-goal coverage while planning, observations that write themselves to the player file. If wave 7 slips, the epic's value proposition slips with it. |
| The merged table's hot-path query regresses VCT generation. | The MD-suitability flags stay denormalised (0122 H5); wave 1's acceptance includes a before/after generation comparison on a seeded club. |
| DPIA blocks wave 9 indefinitely. | Wave 9 is independent of 1–8 and can be dropped from the epic without affecting anything else. |

## Open questions

None blocking. Every shaping question is resolved in the decisions log below.
Two items remain as calendar-time work rather than open design:

- **DPIA legal review** gates wave 9 only. Waves 1–8 are unaffected; wave 9
  can be dropped from the epic without touching anything else.
- **Sideline legibility in direct sunlight.** The dark-ground treatment in
  `.local-mockups/training/coach-view.html` is a judgement that needs a real
  device on a real pitch. If it fails, the fix is a high-contrast variant of
  one stylesheet, not a design change to the epic.

## Decisions log

| # | Question | Resolution | Date |
| --- | --- | --- | --- |
| 1 | One exercise library or two? | One — fold VCT's columns into `tt_exercises` (D1). | 2026-08-17 |
| 2 | How far does diagram authoring go? | Full animated editor (D2), sequenced last. | 2026-08-17 |
| 3 | Is a plan owned by an activity or standalone? | Standalone and reusable (D3), with an explicit run entity carrying the player data. | 2026-08-17 |
| 4 | What is the primary way into a new plan? | Generator-first (D4). | 2026-08-17 |
| 5 | Is a plan versioned, or mutable with the run as history? | Snapshot-only; the plan is mutable and carries no version chain (D5). | 2026-08-17 |
| 6 | Does the scene canvas need a §3 wizard or an exemption? | Neither — a scene is a field of the exercise, so §3 is not engaged (D6). | 2026-08-17 |
| 7 | Three-state observation or the install's evaluation scale? | The install's configured evaluation scale, rendered as a full-width segmented control (D7). | 2026-08-17 |
| 8 | Do guests accrue training exposure? | Yes, on their own player file; the coverage matrix aggregates by the run's team (D8). | 2026-08-17 |
| 9 | Who may add to the club exercise library? | Coaches author team-scoped; the head of development promotes to club-wide (D9). | 2026-08-17 |
| 10 | One tile or two on the dashboard? | One `Training` tile in the coach group; the library is reached from inside it (D10). | 2026-08-17 |
| 11 | Does parked spec 0087 keep the word "training"? | No — renamed to **sandbox instance**; file moved to `specs/parked/0087-feat-sandbox-instance.md`. | 2026-08-17 |
