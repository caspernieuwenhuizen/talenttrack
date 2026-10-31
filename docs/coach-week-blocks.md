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
| `WeekBlock` | The interface every block implements: `key()`, `group()`, `label()`, `help()`, `defaultFor( $persona, $device )`, `rowFields( WeekRow, WeekContext )`, `panelFields( WeekContext )`. |
| `ActionBlock` | A `WeekBlock` that also offers one button: `actionFor( WeekRow, WeekContext ): ?string`. At most `ActionBlock::MAX_PER_ROW` (2) show per device. |
| `AbstractWeekBlock` | No-op defaults: no help, off by default, nothing on a row, nothing on the panel. Extend it and override only what the block contributes. |
| `WeekBlockGroup` | The settings screen's sections, in order: `scope`, `row`, `training`, `match`, `actions`. |
| `WeekBlockRegistry` | The ordered list of block classes (`BLOCKS`). The order is the display order and, among action blocks, the button priority. |
| `WeekRow` | The facts about one activity: id, team, date, `when` (past / today / upcoming), type, whether it is a match, `done` (a completed match), whether it keeps a register. `isDone()` and `isOpenMatch()` are the two questions most blocks ask. |
| `WeekContext` | The viewer, the window, the teams and the activities, plus every source a block reads, each read once per week. |
| `WeekSettings` | Reading, defaults and normalising of `coach_week.config`, and the fallback to `coach_week.personas`. |

## Adding a block

1. Create one class under `CoachWeek/Blocks/`, extending `AbstractWeekBlock` (or implementing `ActionBlock` for a button).
2. Give it a stable `key()`. The key is stored in academies' settings and returned by the API, so it never changes once it ships.
3. Pick its `group()`, a translated `label()` and, when it helps, a one-line `help()`.
4. Decide `defaultFor( $persona, $device )`. A new block is off unless it says otherwise. That keeps an install that never opens the settings looking the way it did.
5. Return its data from `rowFields()` (fields merged into each row) or `panelFields()` (fields merged into the top level of the week). Return `[]` when it has nothing for that row.
6. Add the class to `WeekBlockRegistry::BLOCKS` at the place it should show.
7. Render it in `FrontendTileGrid::renderWeekRow()` / `renderWeekPanel()` with the device class from `weekDeviceClass( $week, '<key>' )`, and add its Dutch to the PR's `languages/pending/` fragment.

The settings screen, the stored settings and `GET /me/week` pick the block up from the registry. Nothing else changes.

## The contract

- **Computed only when on.** `rowFields()`, `panelFields()` and `actionFor()` are called only when the block is on for at least one device of the viewer's persona. A block that is off costs no query.
- **One read per source per week.** Read through `WeekContext::once( $key, $read )`, or one of its helpers (`recorded()`, `planned()`, `roster()`, `openMatches()`, `unavailableCount()`, `prepProgress()`, `hasTrainingRun()`). Never query per row: the number of queries must not grow with the number of activities, and `CoachWeekTest` measures it.
- **Only what the viewer may already see.** A block shows nothing a user could not see elsewhere. Player names go only to users who may open that player, counts go to everyone else, and medical detail never shows.
- **Stable shape.** Fields a block fills are listed in `docs/rest-api.md` under `GET /me/week`. A field that is not computed stays `null` rather than disappearing.
- **CSS only per device.** The view renders a block's element when it is on for either device and marks it `tt-week-off-phone` or `tt-week-off-desktop`. `frontend-coach-week.css` hides it on the other side of 768px. No JavaScript decides what a device shows.
- **Display-only blocks.** Some blocks (`team_name`, `type`, `time`, `location`, `opponent`) only govern what is shown. Their data is part of every row and their `rowFields()` returns `[]`.

## Settings storage

`coach_week.config` in `tt_config`, per club, is JSON keyed by persona. Each persona has `on`, `phone_on`, `window` (`week` / `rolling`), `teams` (`coached` / `all`), `past` (`show` / `fold` / `hide`), `desktop` and `phone` (lists of block keys). `ConfigRestController` saves it on the `feature_toggles` area through `CoachWeek::normaliseSettingsStored()`, which keeps every staff persona and only registered keys. `teams: all` is honoured at read time, and only for a user with `ActivityAccess::hasGlobalRead()`. Until the key is saved, the older `coach_week.personas` list decides `on`, and everything else takes its default. There is no schema migration.
