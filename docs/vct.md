---
title: VCT — conditioning training
group: performance
summary: Age-safe conditioning planning for U10-U14, with workload tracking.
audience: [admin]
views: [vct-session, vct-library]
module: TT\Modules\Vct\VctModule
order: 105
---

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
| Player detail load-restriction panel | `?tt_view=players&id=N&tab=profile` | Coach |

## Capabilities

Three matrix-only caps, no role baselines:

- **`tt_vct_plan`** — plan / edit / publish VCT sessions on a scoped team.
- **`tt_vct_admin_library`** — edit the exercise library + age-profiles + macro-blocks.
- **`tt_vct_view_load`** — read workload aggregates.

Per `config/authorization_seed.php`, **every** persona, including the ones with no access at all — a dash means *considered and not granted*, which is a different statement from a persona being absent from the table (#3741 settled that for injuries):

| Persona | tt_vct_plan | tt_vct_admin_library | tt_vct_view_load |
| --- | --- | --- | --- |
| `head_coach` | team | — | team |
| `assistant_coach` | team | — | team |
| `head_of_development` | global | global | global |
| `admin` | global | global | global |
| `team_manager` | — | — | team (read-only) |
| `staff` carrying the `manager` functional role | — | — | team (read-only) |
| `staff` without it | — | — | — |
| `scout` | — | — | — |
| `player` | — | — | — |
| `parent` | — | — | — |
| `observer` | — | — | — |

The VCT capabilities are **matrix-only by design** (`RolesService::VCT_CAPS`): they are deliberately in no role's capability list, so `user_can()` always answers false for them and only the matrix can grant them. A persona with a dash above therefore gets a 403 from every VCT route, which is the intended answer rather than a gap.

**The team manager reads load and cannot plan it** (#3808). They are who parents ring when a boy is tired, and who decides who sits out, so they read the planned load for their own team beside the minutes they could already see — the two halves of that conversation used to sit in different rooms. `tt_vct_plan` and `tt_vct_admin_config` stay with the coach and the head of development: `vct/sessions`, `vct/team-cycles` and `vct/age-profiles` keep refusing a manager, and that refusal is deliberate. Deciding how hard children are worked is not a logistics seat's job.

A team manager on an install may be the `team_manager` persona **or** the `manager` functional role layered on `staff` — `config/functional_role_grants.php` says so itself — so both shapes carry the same two reads. The answer must not depend on which shape a club happens to use.

**The table above is what the pickers offer.** The training designer's first
step lists exactly the teams you may create a session for: your own squads as
a coach, every academy team as Head of Development or academy admin. It can
never offer a team the wizard would then refuse on submit, and when the list
is empty it says which access is missing instead of leaving you on a select
you cannot use.

Both coach personas read the load for their own teams only. The `vct_workload`
row behind `tt_vct_view_load` was missing from both of them until #3706, so
`GET vct/teams/{id}/workload` refused the two personas who plan against it
while the table above already said otherwise. The team view answers "is this
week too heavy for the session I am about to run"; the per-player view
(`GET vct/players/{id}/workload`) resolves the same team grant, so a coach
reaches it for players on their own teams and no further.

## What's shipped

The module shipped across Phase 1 (architecture-first) and Phase 2 (UI):

**Phase 1 — schema + engine + REST** (closed under #905 child issues):

- Schema migration 0122 — 10 new tables (`tt_vct_exercises`, `tt_vct_coaching_points`, `tt_vct_age_profiles`, `tt_vct_session_templates`, `tt_vct_sessions`, `tt_vct_session_blocks`, `tt_vct_microcycles`, `tt_vct_workload_snapshots`, `tt_vct_team_schedules`, `tt_vct_macro_blocks`). **`tt_vct_exercises` no longer holds the catalogue** — see [One exercise library](#one-exercise-library) below.
- Schema migration 0123 — `tt_player_phv_flags` for the per-player load restriction — named after the growth spurt (peak height velocity) it was first created for.
- Schema migration 0140 — extends load restrictions with `reason_key` + `intensity_ceiling`.
- Seed migrations 0124 (lookups + translations across nl_NL/fr/de/es) + 0125 (age profiles + session templates + phase profiles).
- Rules engine + 8 passes + repositories under [src/Modules/Vct/](../src/Modules/Vct/).
- REST endpoints under `/wp-json/talenttrack/v1/vct/...`.
- Workflow task template `VctWorkloadAggregationTaskTemplate` for nightly aggregation.

**Phase 2 — UI**:

| Surface | Child issue | Slice |
| --- | --- | --- |
| VCT-9: new-vct-session wizard | #1084 | First slice — start-time field with team-defaults prefill |
| VCT-10: coach view + A4 print | #1085 | First slice — sideline load-restriction banner |
| VCT-11: HoD library editor | #1086 | Inline edit + search + intensity-band edge |
| VCT-12: Configuration tiles | #1087 | macro-blocks + age-profiles tiles on Configuration |
| VCT-13: Team panel | #1088 | Inline weekday-chips + start-time + duration on team detail |
| VCT-14: load-restriction UI | #1089 | Per-player Profile-tab panel + orange hero pill |

**VCT-8 — Exercise catalogue seed (full 80)**. The full 80-exercise catalogue now ships. Migration 0177 seeded the starter scaffold (12 exercises, two per category) and migration 0181 adds the remaining 68 to reach the target spread: warmup 10, technical 20, sided_game 20, conditioning 10, finishing 10, cool_down 10. Every exercise carries three to four coaching points authored in canonical English **plus native Dutch (nl_NL)**. Both migrations are idempotent and forward-only: they existence-check `(club_id, code)` before each insert, so re-running on an already-seeded club is a no-op, and a later catalogue correction can bump `seed_revision` without trampling operator edits. Intensity bands respect the per-age workload ceilings (U10=3, U11=4, U12=5, U13/U14=7) so no exercise exceeds the envelope for the youngest age it's offered to.

## One exercise library

TalentTrack used to hold **two** exercise catalogues that could not see each
other: the general exercise library and VCT's own. Migration 0212
merged them. Every VCT exercise now lives in `tt_exercises` alongside the rest
of the library, keeping its code, category, tactical theme, intensity band,
duration and player ranges, age window and match-day suitability flags.

**Nothing changes for a coach in this release.** The VCT session wizard, the
rules engine, the coach view and the print all behave exactly as before — the
same inputs still produce the same session. The merge is groundwork for the
Training module, where coaches browse and build from a single library
instead of meeting two catalogues with different fields.

Points worth knowing if you administer an install:

- `tt_vct_exercises` is left in place and **empty**. It is no longer read or
 written. A later release drops it in its own migration.
- Moved rows are marked `source = 'vct'` and keep their original `uuid`, so
 the migration is safe to re-run.
- `tt_vct_coaching_points` keeps its name and its translations; only its
 exercise reference was repointed.
- Exercises that were already in the general library gain the new columns as
 empty. They stay **out** of VCT session generation until someone fills in an
 age window and an intensity band — an exercise with no age range cannot be
 judged age-safe, so the engine will not pick it.

## What's not shipped (parked)

- **VCT-8 follow-up — locales + diagrams + methodology review**. Still pending on #1129 (which stays open until they land): the fr_FR / de_DE / es_ES coaching-point translations for the full catalogue, per-exercise diagrams, and the HoD / pilot-coach methodology review of the exercise picks, intensity bands, and age ranges.
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

A coach plans a session via the wizard. The wizard reads:

- The team's VCT defaults panel for the basis-step prefill (`VctTeamSchedulesRepository::findForTeamSeason`).
- The exercise library for slot candidates (`VctExercisesRepository::findCandidates` filtered by age + MD + intensity).
- The HoD's macro-blocks for the per-week intensity multiplier (`VctMacroBlocksRepository`).
- The HoD's age-profiles for the session-minutes ceiling + intensity-band ceiling (`VctAgeProfilesRepository`).
- Per-player load restrictions so restricted players get `growth_spurt_load_reduction_pct` applied via `WorkloadCapRule`.

The wizard publishes a `tt_vct_sessions` row. The coach view reads that row + its blocks. The load-restriction banner on the coach view reads the same `VctPhvFlagsRepository` table the WorkloadCapRule uses, so the sideline display + the engine stay in sync.

The Configuration tiles link into the HoD's VCT configuration sub-tabs (`?tt_view=vct-config&tab=blocks` / `&tab=age-profiles`) so the HoD has a one-tap entry from the Configuration grid.

## Speelwijze theme per week

Each macro-block already carries a per-week conditioning cycle — a phase (introduction, build, peak, deload, …) and an intensity multiplier. On top of that, each week can carry an optional **speelwijze theme** (`tactical_theme`): the tactical focus for that week (build-up, defending, possession, …), drawn from the same `vct_tactical_theme` vocabulary the exercise library uses. The theme is optional — a week with no theme is simply untagged, and blocks authored before this feature are unaffected.

Set the theme on the VCT configuration tile (**Configuration → VCT → Macro-blocks**): open a block's **Advanced** section and pick a theme for each week under *Speelwijze-thema per week*. The weeks come from the block's phase profile, so add the weekly phases first.

The combined cycle — theme + conditioning phase + intensity, week by week — is surfaced read-only on the methodology library's **Periodisation** tab for the club-default calendar of the current season.

A JO13-1 5-week speelwijze reference template ships as a starting point (build-up → defending → possession → defending → a neutral week). The per-week theme is descriptive: it does not feed VCT exercise selection.

## Load restriction

A **load restriction** records that one player must be given less load than the plan asks for. It lives on the player's profile (**Profile** tab, *Load restriction*), shows as a pill next to the player's name, and reappears on the coach view's sideline banner and in the wizard's workload check.

A restriction carries a **reason** and, optionally, an **intensity ceiling** — the highest intensity band the player may train in. The reasons are a fixed list, so no medical prose reaches a screen that was not meant for it:

| Reason | What it means |
| --- | --- |
| Growth spurt (PHV) | The player is going through peak height velocity — a growth spurt — and the configured load reduction applies. |
| Injury — knee / Injury — ankle | Recovering from that injury. |
| Asthma | A respiratory condition limiting sustained high intensity. |
| Cardiac condition | A heart condition. Record it with the reason, not the detail. |
| Other medical reason | Anything else clinical. Use the notes for what the coach needs to know. |
| Temporary fatigue | Short-term: exam week, a heavy fixture run, illness on the mend. |

**The growth spurt is one reason, not the name of the flag.** The screen used to be headed *PHV*, so a player recovering from a sprained ankle was labelled as growing. A restriction now says what it is, and the reason says why.

A restriction is not automatic. Recording an injury on the **Injuries** tab does not create one — a member of staff decides whether the plan needs to change, and sets the restriction. Clearing a restriction keeps the last reason and ceiling, so re-applying one after a relapse takes a single tick.

**Who may set one.** The same answer on every route: `tt_vct_plan` plus VCT *change* scope on the player's team (`LoadRestrictionAccess`). A coach sets restrictions for their own squad; the Head of Development and the academy admin for any team. Other staff who can view the player see the restriction read-only. A player with no team cannot carry one, because there is no plan to restrict.

Where it takes effect: the engine's `WorkloadCapRule` applies the age profile's **restricted-player load reduction %** (`growth_spurt_load_reduction_pct`) to a restricted player's share of the session load, and the intensity ceiling keeps them out of blocks above their band.

## Privacy

The load-restriction panel + pill follow CLAUDE.md §1 — staff (HoD / coach / admin) see full reason + ceiling + notes; families see nothing at all, on the profile or in the payload. The reason picker is an enum to discourage long medical text leaking via free-text.

The panel, hero pill, and form POST handler on the player profile are VCT functionality, so they only appear when the VCT module is switched on. With VCT off the player profile shows no load-restriction surface at all.

## References

- Shipped spec: [`specs/shipped/0095-feat-vct-module.md`](../specs/shipped/0095-feat-vct-module.md)
- Architecture: [`docs/architecture.md`](architecture.md)
- Authorization matrix: [`docs/authorization-matrix.md`](authorization-matrix.md)
- Configuration tiles: [`docs/configuration-vct.md`](configuration-vct.md)
