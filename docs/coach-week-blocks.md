---
title: This week panel blocks
group: developer
summary: How the This week panel is built from switchable blocks, how a block is added, and the contract every block keeps.
audience: [dev]
order: 62
---

# This week panel blocks

The **This week** panel on the tile landing, and `GET /me/week` behind it, are built from **blocks**: a chip, a line, a button or a section that an academy switches on or off per persona and per device. All of it lives under `src/Modules/Activities/CoachWeek/`. `Modules\Activities\Services\CoachWeek::forUser()` asks the registry and never names a block itself.

## The pieces

| Piece | What it does |
| --- | --- |
| `WeekBlock` | The interface every block implements: `key()`, `group()`, `label()`, `help()`, `available()`, `defaultFor( $persona, $device )`, `optInFor( $persona )`, `rowDefaults()`, `panelDefaults()`, `rowFields( WeekRow, WeekContext )`, `panelFields( WeekContext )`. |
| `ActionBlock` | A `WeekBlock` that also offers one button: `actionFor( WeekRow, WeekContext ): ?string`. At most `ActionBlock::MAX_PER_ROW` (2) show per device. |
| `AbstractWeekBlock` | No-op defaults: no help, always available, off by default, no opt-in, owns no fields, contributes nothing. Extend it and override only what the block contributes. |
| `WeekBlockGroup` | The settings screen's sections, in order: `scope`, `row`, `training`, `match`, `actions`. |
| `WeekBlockRegistry` | The ordered list of block classes (`BLOCKS`). The order is the display order and, among action blocks, the button priority. |
| `WeekRow` | The facts about one activity: id, team, date, `when` (past / today / upcoming), type, whether it is a match, `done` (a completed match), whether it keeps a register. `isDone()` and `isOpenMatch()` are the two questions most blocks ask. |
| `WeekContext` | The viewer, the window, the teams and the activities; the rights checks `canSeeTeam()`, `canEditTeam()` and `canSeePlayer()`; and the sources several blocks share, each read once per week. |
| `WeekSettings` | Reading, defaults and normalising of `coach_week.config`, and the fallback to `coach_week.personas`. |

## Adding a block

1. Create one class under `CoachWeek/Blocks/`, extending `AbstractWeekBlock` (or implementing `ActionBlock` for a button).
2. Give it a stable `key()`. The key is stored in academies' settings and returned by the API, so it never changes once it ships.
3. Pick its `group()`, a translated `label()` and, when it helps, a one-line `help()`.
4. Decide `defaultFor( $persona, $device )`. A new block is off unless it says otherwise. That keeps an install that never opens the settings looking the way it did. If a persona may only get the block when an academy chooses that, return true from `optInFor( $persona )`: no default and no fallback turns it on.
5. If the block depends on a module or a plan, return that from `available()`. An unavailable block has no switch and is never asked.
6. Declare the fields it owns in `rowDefaults()` / `panelDefaults()`, each with its empty value, and return its data from `rowFields()` / `panelFields()`. Return `[]` when it has nothing for that row. Keys it does not own are dropped.
7. Add the class to `WeekBlockRegistry::BLOCKS` at the place it should show.
8. Render it in `FrontendTileGrid::renderWeekRow()` / `renderWeekPanel()` with the device class from `weekDeviceClass( $week, '<key>' )`, and add its Dutch to the PR's `languages/pending/` fragment.

The settings screen, the stored settings and `GET /me/week` pick the block up from the registry. Nothing else changes.

## The contract

- **Computed only when on.** `rowFields()`, `panelFields()` and `actionFor()` are called only when the block is available and on for at least one device of the viewer's persona. A block that is off costs no query.
- **Rights through the context, never the persona.** A block decides what the viewer may see or do with `WeekContext::canSeeTeam()`, `canEditTeam()` (per row's team, not per week) and `canSeePlayer()`. It never gates on `$week->persona`, which is a key for defaults only. Player names go only to players `canSeePlayer()` admits; everyone else gets a count. Medical detail never shows.
- **A block writes only its own fields.** The service merges only the keys in a block's `rowDefaults()` / `panelDefaults()`, so a block cannot overwrite `activity_id`, `actions` or another block's field. `CoachWeekBlocksTest` checks that no two blocks claim the same field.
- **One read per source per week.** A block reads its own source inside its class, through `WeekContext::once( $key, $read )`. Only a fact several blocks share becomes a helper on the context (`recorded()`, `planned()`, `roster()`, `openMatches()`, `unavailableCount()`, `prepProgress()`, `hasTrainingRun()`). Never query per row: the number of queries must not grow with the number of activities, and `CoachWeekTest` measures it.
- **Stable shape.** Every registered block's fields are on every row, at their empty value when the block is off or unavailable. They are listed in `docs/rest-api.md` under `GET /me/week`.
- **CSS only per device.** The view renders a block's element when it is on for either device and marks it `tt-week-off-phone` or `tt-week-off-desktop`. `frontend-coach-week.css` hides it on the other side of 768px. No JavaScript decides what a device shows.
- **Display-only blocks.** Some blocks (`team_name`, `type`, `time`, `location`, `opponent`) only govern what is shown. Their data is part of every row and their `rowFields()` returns `[]`.

## Settings storage

`coach_week.config` in `tt_config`, per club, is JSON keyed by persona. Each persona has `on`, `phone_on`, `window` (`week` / `rolling`), `teams` (`coached` / `all`), `past` (`show` / `fold` / `hide`), `desktop` and `phone` (lists of block keys). `ConfigRestController` saves it on the `feature_toggles` area through `CoachWeek::normaliseSettingsStored()`, which keeps every staff persona and only registered keys. An unreadable value (not a JSON object) is refused with `400 invalid_field` rather than reset. `teams: all` is honoured at read time, and only for a user with `ActivityAccess::hasGlobalRead()`. Until the key is saved, the older `coach_week.personas` list decides `on`. A persona it switches on gets `WeekSettings::fullDesktopFor()` on desktop (what it saw before), less its opt-in blocks, and its own defaults on phone. There is no schema migration.
