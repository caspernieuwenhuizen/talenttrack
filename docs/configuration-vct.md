<!-- audience: admin -->

# VCT configuration

The Configuration grid surfaces two dedicated tiles for the Variable
Coaching Templates (VCT) module — **VCT macro-blocks** and **VCT
age-profiles** — gated on the `tt_vct_admin_library` capability so
only the Head of Development (and admins) see them. Both tiles open
the existing VCT configuration view (`?tt_view=vct-config`) at the
matching sub-tab.

## Tiles at a glance

| Tile | Opens | Count line | Why it's there |
| --- | --- | --- | --- |
| **VCT macro-blocks** | `?tt_view=vct-config&tab=blocks` | "%d active" — counts the operator-editable reference templates (`VctMacroBlocksRepository::listReferenceTemplates()`) | Block templates the VCT session wizard uses (warming-up, hoofddeel, cooldown, theme-blocks). Editing the templates changes what coaches start from on Step 3 of the new-VCT-session wizard. |
| **VCT age-profiles** | `?tt_view=vct-config&tab=age-profiles` | "%d age bands" — counts the seeded per-age envelopes (`VctAgeProfilesRepository::listAll()`) | Per age band (JO8 → JO19) the workload-cap, max intensity per MD-day, max VCT session length. Drives the wizard's workload check + the engine's `WorkloadCapRule` enforcement everywhere a VCT session is composed or saved. |

The tiles carry a green **NEW** pill + accent border (`.tt-cfg-tile--vct`)
so HoDs landing on Configuration after the upgrade notice them on first
visit. The styling mirrors the design-of-record in
`.local-mockups/vct-config-tiles/`.

## What ships in this slice

- Two new tiles rendered inline in `FrontendConfigurationView::renderTileGrid()`.
- No schema or REST changes — both destination sub-tabs shipped with
  VCT-12 (#952). This issue closes the discoverability gap.
- Capability gate: `tt_vct_admin_library` is checked once in
  `renderVctTiles()`. Users without the cap see the rest of the
  Configuration grid unchanged.

## What doesn't ship here (parked for follow-up)

- Archived-template count on the macro-blocks tile (e.g. "5 active · 2
  archived"). The mockup left the archived UX open; the live tile shows
  only the active count for now.
- A warning state on the age-profiles tile when a band has no workload-cap
  set. Open question raised in `.local-mockups/vct-config-tiles/notes.md`.

If pilot feedback flags either, file a follow-up referencing this doc.

## Per-team complement: VCT defaults panel (#1088)

The central schedules tab edits every team in one season at once. The
**team-detail VCT panel** at the bottom of `?tt_view=teams&id=N`
edits one team in isolation:

- Weekday chip row (Ma → Zo, multi-select)
- Default start time + default duration
- Saves via the same `VctTeamSchedulesRepository::upsert()` the central
  surface uses; both surfaces are read by the new-VCT-session wizard's
  basis step.

Same cap gate (`tt_vct_admin_library`), same design tokens — pilot can
pick whichever surface fits the workflow. Coaches with no edit cap don't
see the panel at all.

Deferred from the mockup: optional `Trainingslocatie` free-text field
(requires a `default_location` schema column) and the live "next
session preview" summary line (requires weekday + date math). Follow
up on either if pilot requests.
