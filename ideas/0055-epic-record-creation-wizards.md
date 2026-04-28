<!-- type: epic -->

# #0055 — Record creation wizards

## Problem

The "create new player" and "create new team" forms today are flat — every field on one page, no guidance, no branching. New academies (and even experienced ones during onboarding) get stuck because:

- A trial player and a rostered player share most fields but follow different downstream flows. One form for both buries the difference.
- A new team needs a head coach, assistant coach, manager, physio — but the form has only a single `head_coach` dropdown today; everything else is post-hoc on the People page.
- The forms don't *teach* the user what's important. A staff member onboarding a new player can't tell which fields matter without going to docs.

The user-stated need: each new-record entry point should be a wizard that asks the right questions in order, branches on key answers (trial vs roster), and helps the user create the record *first time right*.

## Proposal

A reusable wizard primitive plus per-entity wizards layered on top:

1. **Generic `RecordCreationWizard` framework** in `src/Shared/Wizards/`. Steps as PHP classes implementing `WizardStepInterface`. State persisted in a transient keyed by user_id + wizard_slug while in flight. Submits at the final step.

2. **Per-entity wizards**:
   - **New player wizard** — Step 1 asks "trial or roster?". Roster path → existing player fields. Trial path → minimal trial fields (name, age, team interested in, scout) and creates the trial case directly (when #0017 ships). Until #0017 ships, the trial path lands in the roster table with `status='trial'` and a TODO note.
   - **New team wizard** — Step 1 team basics (name, age group), Step 2 staff assignment (head coach, assistant, manager, physio — each skippable), Step 3 review.
   - **New evaluation wizard** (later) — picks player + type + matches the right form.
   - **New goal wizard** (later) — picks player + links a methodology entity.

3. **Config toggle** under `tt_config.tt_wizards_enabled` (default off until the wizards prove themselves on a couple of academies). Setting `'all'`, `'players,teams'` (CSV), or `'off'` lets clubs opt in to specific wizards. New-academy onboarding flow can flip this to `'all'` automatically.

## Scope (per phase)

### Phase 1 — Framework + new-player wizard (~12-15h estimated, ~3-4h actual at compression)

- `WizardStepInterface` with `render()`, `validate()`, `nextStep()` methods. Last step's `submit()` does the actual entity creation.
- `WizardController` REST endpoints: `POST /wizards/{slug}/start`, `POST /wizards/{slug}/{step}`, `POST /wizards/{slug}/finish`.
- Frontend: a generic `?tt_view=wizard&slug=<slug>` route that renders the current step.
- New-player wizard: trial-vs-roster branching, then the relevant subset of player fields.
- Config toggle scaffolding (default off; admin can enable per-entity).

### Phase 2 — Team + onboarding integration (~6-10h)

- New-team wizard: basics → staff → review.
- Hook the wizard into the existing setup wizard (#0024) so a fresh install creates the first team via this same flow.

### Phase 3 — Evaluation + goal wizards (~10-15h)

- New-evaluation wizard: player → type → form.
- New-goal wizard: player → link methodology → due date.

### Phase 4 — Polish + analytics (~4-6h)

- Per-academy "wizard completion rate" metric.
- Skip / dismiss tracking — which steps do people skip?
- "Help" sidebar per step showing the relevant doc.

## Out of scope

- Drag-drop wizard authoring (academies can't author wizards; they're code).
- Multi-tenant wizard customization (covered by #0052 SaaS readiness when it lands).
- Wizard for editing existing records (the value is at creation; editing stays as the flat form).

## Acceptance criteria (Phase 1)

- [ ] `WizardStepInterface` shipped with at least 2 implementations (trial path + roster path on the player wizard).
- [ ] `?tt_view=wizard&slug=new-player` renders the first step; submit advances; final step creates the player.
- [ ] Config toggle `tt_wizards_enabled` controls visibility of wizard entry points; "+ New player" buttons on the players list link to the wizard when enabled, fall back to the flat form when disabled.
- [ ] Mobile-first: each step renders at 360px with single-column field layout.
- [ ] PHP lint, msgfmt, docs-audience CI green; NL .po updated.

## Notes

### Player-centricity check (per CLAUDE.md)

The wizard exists to make creating a player record *correctly first time* — directly serves the player's data quality. Trial vs roster branching encodes a key transition (#0014's "trial → signing" is one of the modeled events).

### SaaS-readiness

Wizard state lives in WP transients today. Future SaaS migration: replace with a server-side store keyed on user/account; the `WizardStepInterface` shape is portable.

### Cross-references

- **#0024 — setup wizard** — the framework here generalises that one-off into reusable per-entity wizards. Phase 2 hooks the new-team wizard into the setup wizard.
- **#0017 — trial player module** — when it ships, the trial branch of the new-player wizard switches from "set status=trial" to "create a trial case via #0017's API."
- **#0014 — player profile + report generator** — wizards complement the rebuilt profile by ensuring the data feeding it is captured cleanly.
- **#0052 — SaaS readiness baseline** — wizard state storage will need a tenancy column when this idea graduates to spec.
