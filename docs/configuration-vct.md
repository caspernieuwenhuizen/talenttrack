<!-- audience: admin -->

# VCT configuration

The Configuration grid surfaces a single **VCT configuration** tile for
the Variabel Coachen-template (VCT) module, gated on the
`tt_vct_admin_library` capability so only the Head of Development (and
admins) see it. The tile opens the VCT configuration view
(`?tt_view=vct-config`); its own tab bar handles sub-navigation between
the three sections.

The tile carries a green **NEW** pill + accent border
(`.tt-cfg-tile--vct`) and a count line summarising how much is set up
("%d block templates · %d age bands").

## The three tabs

| Tab | What it does |
| --- | --- |
| **Macro-blocks** | The season periodization calendar — a list of dated blocks (build-up, in-season, taper, …) per season, optionally overridden per team. |
| **Age profiles** | Per age band (JO8 → JO19) the workload-cap, max intensity per MD-day, max training length. Drives the wizard's workload check and the engine's enforcement everywhere a VCT training is composed or saved. |
| **Team schedules** | Per-team weekly VCT training days for a season. Drives the wizard's date-default to the next configured weekday. |

Before #1546 there were two tiles (one each for macro-blocks and age
profiles) and the Team schedules tab had no tile at all. The single tile
makes all three reachable from one entry point.

## Picking a season and team

Season and team are now **dropdowns** — no raw ID typing.

- **Season** defaults to the academy's active season and **auto-loads on
  change**: pick a different season and the view reloads for it. There is
  no separate "Load" button (a no-script fallback button is shown only
  when JavaScript is off). Applies to the Macro-blocks and Team schedules
  tabs.
- **Team** (Macro-blocks tab) is a dropdown with a **Club default (all
  teams)** option at the top. The club default applies to every team;
  pick a specific team to edit an override just for them.

## Editing macro-blocks

The block set is edited with a structured editor — one row per block,
each with a **name**, a **start date** and an **end date** (native date
pickers). Rows can be added, removed and reordered (move up / down). The
editor flags overlaps, missing names and reversed dates inline as you
type.

Each block can carry an optional **weekly phase profile**. The common
case (name + dates) needs nothing more; for the advanced case an
expandable "Advanced: weekly phase profile (JSON)" section per block
accepts an array of `{ week, phase, multiplier }` objects.

Saving sends the whole block set to `PUT /vct/macro-blocks?season_id=N&team_id=M`,
which re-validates server-side: 1–12 blocks, contiguous sequence numbers
(1..N), valid `YYYY-MM-DD` dates, end on/after start, no overlapping
ranges. The shared `VctMacroBlockValidator` is the single source of
truth, used by both the REST endpoint and any other writer, so the
WordPress render and a future SaaS front end get identical answers.

The **Reference phase profiles** read-only table above the editor lists
the seeded template profiles for reference.

## Editing age profiles and team schedules

Both tabs use polished `<details>` accordions — one per age band / team.
Each summary shows the key numbers (minutes + intensity band for an age
profile; the training days for a team). Forms inside use the shared
`tt-field` grid and stack to a single column at 360px. Each form saves
on its own (settings sub-forms; Save-only per CLAUDE.md §6 (a)).

## Per-team complement: VCT defaults panel (#1088)

The central Team schedules tab edits every team in one season at once.
The **team-detail VCT panel** at the bottom of `?tt_view=teams&id=N`
edits one team in isolation (weekday chips, default start time +
duration). Both surfaces save via the same
`VctTeamSchedulesRepository::upsert()` and are read by the new-VCT
wizard's basis step. Same cap gate, same design tokens.
