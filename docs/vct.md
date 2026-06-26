<!-- audience: admin -->

# VCT — Voetbal Conditionele Training

VCT (Voetbal Conditionele Training) is the planner for **age-safe,
football-specific conditioning training sessions for U10–U14 youth
teams**. The module sits next to Activities — VCT plans the training,
Activities tracks the attendance and aftermath.

Module entry points:

| Surface | URL | Persona |
| --- | --- | --- |
| New VCT session wizard | `?tt_view=wizard&slug=new-vct-session` | Coach |
| Published session coach view | `?tt_view=vct-session&id=N` | Coach (sideline) |
| HoD exercise library | `?tt_view=vct-library` | Head of Development |
| HoD VCT configuration | `?tt_view=vct-config` (sub-tabs: blocks / age-profiles / schedules) | HoD |
| Configuration tiles | `?tt_view=configuration` → "VCT macro-blocks" / "VCT age-profiles" | HoD |
| Team detail VCT-defaults panel | `?tt_view=teams&id=N` (inline at bottom) | HoD / Coach |
| Player detail PHV panel | `?tt_view=players&id=N&tab=profile` | Coach |

## Capabilities

Three matrix-only caps, no role baselines:

- **`tt_vct_plan`** — plan / edit / publish VCT sessions on a scoped team.
- **`tt_vct_admin_library`** — edit the exercise library + age-profiles + macro-blocks.
- **`tt_vct_view_load`** — read workload aggregates.

Per `config/authorization_seed.php`, the four personas have:

| Persona | tt_vct_plan | tt_vct_admin_library | tt_vct_view_load |
| --- | --- | --- | --- |
| `head_coach` | team | — | team |
| `assistant_coach` | team | — | — |
| `head_of_development` | global | global | global |
| `admin` | global | global | global |

## What's shipped

The module shipped across Phase 1 (architecture-first) and Phase 2 (UI):

**Phase 1 — schema + engine + REST** (closed under #905 child issues):

- Schema migration 0122 — 10 new tables (`tt_vct_exercises`, `tt_vct_coaching_points`, `tt_vct_age_profiles`, `tt_vct_session_templates`, `tt_vct_sessions`, `tt_vct_session_blocks`, `tt_vct_microcycles`, `tt_vct_workload_snapshots`, `tt_vct_team_schedules`, `tt_vct_macro_blocks`).
- Schema migration 0123 — `tt_player_phv_flags` for the Peak Height Velocity flag.
- Schema migration 0140 — extends PHV flags with `reason_key` + `intensity_ceiling`.
- Seed migrations 0124 (lookups + translations across nl_NL/fr/de/es) + 0125 (age profiles + session templates + phase profiles).
- Rules engine + 8 passes + repositories under [src/Modules/Vct/](../src/Modules/Vct/).
- REST endpoints under `/wp-json/talenttrack/v1/vct/...`.
- Workflow task template `VctWorkloadAggregationTaskTemplate` for nightly aggregation.

**Phase 2 — UI**:

| Surface | Child issue | Slice |
| --- | --- | --- |
| VCT-9: new-vct-session wizard | #1084 | First slice — start-time field with team-defaults prefill |
| VCT-10: coach view + A4 print | #1085 | First slice — sideline PHV exclusion banner |
| VCT-11: HoD library editor | #1086 | Inline edit + search + intensity-band edge |
| VCT-12: Configuration tiles | #1087 | macro-blocks + age-profiles tiles on Configuration |
| VCT-13: Team panel | #1088 | Inline weekday-chips + start-time + duration on team detail |
| VCT-14: PHV flag UI | #1089 | Per-player Profile-tab panel + orange hero pill |

## What's not shipped (parked)

- **VCT-8 — Exercise catalogue seed (full 80)**. A starter *scaffold* has shipped (migration 0177): a representative draft of 12 exercises — two per category across warmup / technical / sided_game / conditioning / finishing / cool_down — each with three coaching points authored in all five shipped locales (canonical English + nl_NL / fr_FR / de_DE / es_ES). The migration is idempotent and forward-only: it existence-checks `(club_id, code)` before each insert, so re-running on an already-seeded club is a no-op, and a later catalogue correction can bump `seed_revision` without trampling operator edits. Still pending: the full 80-exercise catalogue, per-exercise diagrams, and the HoD / pilot-coach methodology review of the exercise picks, intensity bands, and age ranges. Tracked on #1129.
- **Wizard step 2 MD-context chip-bar visualization** — `PreviewStep` already surfaces the auto-resolved context as a header chip; the colour-band palette ships in a follow-up if pilot reports needing it.
- **Bottom-sheet exercise picker** on the wizard's block-builder step — coach override per slot is a substantial step 3 UI rebuild.
- **A4/A6 print mockup-fidelity polish** on the coach view — `FrontendVctSessionPrintView` exists and prints the session; mockup polish ships in a follow-up.
- **Current-block teal-border highlight** + live timer on the coach view — visual polish, follow-up.

## Coach-facing guidance and messages

The wizard and coach view are written to read as a finished Dutch-first
coach tool — the rules engine's internal codes never reach the screen:

- **Theme step** shows a one-line focus per tactical theme (e.g.
  *Possession → ball control, short passing, and keeping the ball as a
  team*) so a coach who is unsure which to pick gets a plain cue.
- **Duration step** warns clearly when the team has no age group set:
  it explains it is using a default minutes cap and points the coach to
  the team settings to set an age-tuned limit.
- **When step** explains that the age group and match-day (MD) context
  are detected automatically from the team and its season schedule on
  the next step — the coach does not enter them.
- **Preview step** renders the rules engine's warnings as readable
  sentences instead of raw codes. Blocking problems ("this training
  can't be built yet") each carry a short resolution hint telling the
  coach what to do next — for example, set the team's age group, pick a
  different date, or ask an admin to add a session blueprint. The
  mapping from engine code to sentence + hint lives in
  [`RuleMessages`](../src/Modules/Vct/Rules/RuleMessages.php), in the
  rules layer, so the REST API and the rendered wizard speak the same
  language.
- **Empty states** are self-serviceable: when no age profiles exist,
  the configuration view explains what age profiles do and that an
  academy admin sets them up, with no migration numbers. The session
  view explains *why* a training has no blocks (no suitable exercises
  for the chosen age, theme, and duration) and how to fix it.
- **Publish** wording front-loads the two-step confirmation: publishing
  links the training to a team activity, and if one already exists at
  the same date and time the coach is asked to reuse it or create a new
  one.

The `MD-4 … MD … MD+2` / `NONE` tokens in the exercise library's
MD-context picker are intentional technical periodisation tokens, not
untranslated English — a Dutch coach reads `MD-2` exactly as an English
one does, so they are deliberately exempt from translation.

## How the surfaces talk to each other

A coach plans a session via the wizard (#1084). The wizard reads:

- The team's VCT defaults panel (#1088) for the basis-step prefill (`VctTeamSchedulesRepository::findForTeamSeason`).
- The exercise library (#1086) for slot candidates (`VctExercisesRepository::findCandidates` filtered by age + MD + intensity).
- The HoD's macro-blocks (#1087) for the per-week intensity multiplier (`VctMacroBlocksRepository`).
- The HoD's age-profiles (#1087) for the session-minutes ceiling + intensity-band ceiling (`VctAgeProfilesRepository`).
- Per-player PHV flags (#1089) so flagged players get `growth_spurt_load_reduction_pct` applied via `WorkloadCapRule`.

The wizard publishes a `tt_vct_sessions` row. The coach view (#1085) reads that row + its blocks. The PHV banner on the coach view (#1085) reads the same `VctPhvFlagsRepository::activeForRoster()` the WorkloadCapRule uses, so the sideline display + the engine stay in sync.

The Configuration tiles (#1087) link into the HoD's VCT configuration sub-tabs (`?tt_view=vct-config&tab=blocks` / `&tab=age-profiles`) so the HoD has a one-tap entry from the Configuration grid.

## Privacy

PHV (Physical / Health / Vitality) panel + pill follows CLAUDE.md §1 — staff (HoD / coach / admin) see full reason + ceiling + notes; other parents see nothing; AC-also-parent sees own child via the parent persona only. The reason picker is an enum to discourage long medical text leaking via free-text.

## References

- Shipped spec: [`specs/shipped/0095-feat-vct-module.md`](../specs/shipped/0095-feat-vct-module.md)
- Architecture: [`docs/architecture.md`](architecture.md)
- Authorization matrix: [`docs/authorization-matrix.md`](authorization-matrix.md)
- Configuration tiles: [`docs/configuration-vct.md`](configuration-vct.md)
