# Training module — mockup notes (#0096)

Spec: [`specs/0096-epic-training-module.md`](../../specs/0096-epic-training-module.md).
Open [`index.html`](index.html) for the hub linking every surface.

These mockups are the design-of-record for the epic's nine waves. The
executor's job per child issue is "port this HTML/CSS to the rendered PHP
output" — the layout, density and copy decisions are made here, not in the
implementation PR.

## Shaping status

All eleven product decisions are closed — see the decisions log at the bottom
of the spec. The four that changed these mockups after the first pass:

- **D7** — observations use the install's evaluation scale, so the sideline
  sheet was rebuilt from a three-state triple to a stacked segmented control.
- **D9** — coaches author team-scoped, so the library gained an HoD promotion
  queue and the create form's visibility now defaults to the coach's team.
- **D10** — one `Training` tile, so every breadcrumb chains through
  `Dashboard › Training › …` rather than treating the library and the plans
  list as sibling top-level destinations.
- **D5** — plans are mutable with no version chain, so nothing in the builder
  shows a version number or a "new version created" affordance.

## Design decisions taken in the mockups

**The plan's spine is colour-coded by block type, everywhere.** Warm-up blue,
rondo purple, main green, game gold, finishing terracotta, cool-down grey,
talk light grey. The same six colours drive the timeline strip in the
generator, the builder, the plan list card, the sideline progress bar and the
A4 print header. A coach learns the shape of a session once.

**Intensity is a 1–5 band with the VCT palette, reused verbatim.** Band 5 is
the only warm colour, so "this is a hard block" reads at a glance without
reading a number. Carried over from `.local-mockups/vct-library` so the
merged library does not look like two libraries wearing one skin.

**Principle codes are always pills, always the same blue.** `AO-01` in the
generator, the builder, the library row, the exercise detail, the player
file and the coverage matrix is visually one object. This is the join that
makes the whole module cohere, so it gets a dedicated chip style
(`.pill--principle`) rather than the generic grey.

**Every surface states what it does for players, in players' names.** The
generator's review step names the six players whose goals the session covers
and the one with a PHV ceiling. The builder's side panel lists them with
avatars. The sideline observation sheet is a player list, not a form. This is
deliberate: the fastest way for a session planner to drift team-centric is for
no screen to ever show a player's name.

**Nothing animates until asked.** Both the exercise detail's scene player and
the editor start parked at t=0. `prefers-reduced-motion` shows the final frame
with the Play control still available. Matches the shipped Speelwijze scenes.

## Per-surface notes

### `generator.html` — wave 4
- Step 2's theme choices each carry a one-line rationale: which principles,
  how many open player goals it covers, and how long since it was last
  trained. That third fact is what makes a coach trust the ordering.
- Step 4 never shows a spinner-and-hope. Every block card is swappable
  in place; "stel opnieuw samen" is a secondary action, not the primary one.
- Step 5 is the only step that can produce a warning. Blocking warnings
  (age ceiling breach, no candidate for a required slot) return 400 and
  persist nothing, exactly as `VctTrainingComposer` behaves today.

### `plan-builder.html` — wave 5
- The drag handle only renders at ≥1024px. Touch reorders with ↑↓ buttons,
  which are the required non-gesture fallback (CLAUDE.md §2), not a
  degraded alternative — they are the primary control on phones.
- The library picker is a bottom sheet on mobile and a bottom-right panel on
  desktop. `overscroll-behavior: contain` on the sheet so the page behind
  does not scroll.
- Save + Cancel via `.tt-form-actions`: Cancel first in DOM, Save right on
  screen via flex `order` (CLAUDE.md §6).

### `coach-view.html` — waves 6 and 7
- Dark green ground. This is the only surface in the plugin that inverts,
  and it does so because it is read outdoors at arm's length. The timer is
  52px; the block name is 26px.
- Controls sit in the thumb zone with `env(safe-area-inset-bottom)` padding.
  Three targets, each ≥56px: previous, complete, next.
- The over-running state is a designed state, not an error. It states the
  consequence in plain language ("the game still fits, the cool-down does
  not") rather than nagging.
- The observation sheet uses **the install's configured evaluation scale**
  (D7 — 5–9 step 1 on the pilot install), not a bespoke three-state control.
  Five targets do not fit beside a name and an avatar at 360px, so each
  player renders as two stacked rows: identity above, a full-width segmented
  scale below. Five segments across 360px gives roughly 64px each, safely
  over the 48px minimum, and the row reads left-to-right in one glance.
- Tapping the selected number again clears it. `rating` is nullable and a
  note-only observation is the common case on a wet Tuesday, so clearing has
  to be one tap, not a hunt for a "geen score" option.
- The segment set is generated from the configured scale rather than
  hard-coded, so an academy on 1–10 gets ten segments wrapping to two rows.

### `library.html` — waves 1 and 2
- VCT-sourced rows carry a blue `uit VCT` chip and shipped rows a grey
  `meegeleverd` chip with a tinted background. After the merge a coach must
  still be able to tell club-authored content from shipped content, because
  only the former is editable.
- The inline create form is the §3 exemption (a) path — a vocabulary row, no
  wizard. It matches the shipped VCT library editor's shape deliberately, so
  the merge does not also change the authoring habit.
- The closing notice is doing real work: it pre-empts the "I have to enter
  200 drills first" reaction that kills adoption of every exercise library.
- **The promotion queue (D9)** is HoD-only chrome, hidden entirely for a coach
  without global `tt_manage_exercises` scope — not disabled, not greyed out,
  absent. Toggle it with the "HoD · promotiewachtrij" state button. A coach's
  new exercise defaults to their own team's visibility and is usable in their
  plans immediately; nothing waits on an approval. Promotion only decides
  whether the rest of the club and the generator's club-wide pool get it.

### `exercise-detail.html` — waves 2 and 8
- The scene is real CSS-keyframe animation over an SVG pitch in 0–100 pitch
  coordinates, so it is a genuine preview of what wave 8 produces, not a
  placeholder image.
- The "gebruikt in" panel is what makes the versioning model visible: editing
  makes a new version, and past plans keep showing the version they used.

### `diagram-editor.html` — wave 8
- The JS implements the `scene_json` contract for real: actors with
  `keyframes[{t,x,y}]`, linear interpolation on scrub, drag-writes-a-keyframe
  at the current time, and a live JSON panel showing exactly what persists.
  Drag a player and watch the keyframe diamonds appear on the timeline.
- Desktop-primary by design, and the surface says so at <1024px rather than
  offering a cramped canvas. Viewing and repositioning work on touch;
  keyframe editing does not.
- **No wizard and no §3 exemption (D6).** A scene is a *field* of the
  exercise — the same category as its diagram image or its coaching-point
  list — that happens to be edited on a canvas. No record is created, so the
  wizard-first rule is not engaged. The canvas is reached from the exercise's
  own edit surface, and its three settings live in the canvas header.

### `player-exposure.html` — wave 7
- The zero rows are the point of the screen. A principle with zero minutes
  renders as a red stub, not an absent row, because "never trained" is the
  finding.
- The warning connects exposure to evaluation directly: a low rating on a
  principle a player has never been trained on means something different from
  a low rating on one they have had 412 minutes of.

### `coverage-report.html` — wave 7
- Heat matrix of principle × team. Five buckets, only "never" in red — so the
  four all-red rows are unmissable without the rest of the grid shouting.
- The second table is the actionable half: named players whose own goal sits
  on a principle their team barely trains.

### `print.html` — wave 6
- 75 minutes on one A4 with mini-diagrams and a tick-list roster. Fits the
  actual habit: coaches print it, fold it, and put it in a pocket.
- The PHV warning repeats on the print, because the person holding the paper
  is the person who has to act on it.

### Wave 9 — no dedicated mockup
The photo-capture backend from #0016 is already shipped. The review wizard
reuses the generator's step-4 block cards with a confidence tint per row
(green ≥0.85, amber 0.6–0.85, red below, matching
`ExerciseFuzzyMatcher`'s 0.6 threshold). The capture screen is a camera
viewfinder with a single shutter. Neither warrants its own design pass until
the DPIA closes.

## Still to test on a real device

- The sideline view in direct sunlight — the dark ground is a guess and may
  need a high-contrast variant rather than an inversion.
- Drag-to-position on the scene editor with a stylus on an iPad. The pointer
  events are there; the hit targets (3.2 pitch units ≈ 11px at 360px wide)
  are almost certainly too small on touch and need an invisible larger hit
  circle.
- Whether the generator's three-minute target actually holds with a coach who
  has never seen the product. That is the single acceptance criterion the
  mockups cannot answer.
- Whether a five-segment scale is genuinely tappable mid-session. The targets
  clear 48px on paper; the question is whether a coach scoring twelve players
  in a two-minute water break finds it fast enough, or reverts to scoring
  nobody. If it fails, the fallback is capturing the note now and prompting
  for scores in the post-session summary — not shrinking the scale.

## String inventory

All copy in these mockups is Dutch. The implementation goes through `__()`
with English msgids; the Dutch here is the intended `msgstr`. Key vocabulary,
settled deliberately (see spec `## Naming`):

| Concept | Dutch | Not |
| --- | --- | --- |
| The reusable document | **trainingsplan** | training, sessie |
| The calendar event | **training** | activiteit (that is the parent type) |
| One execution of a plan | **uitvoering** | sessie |
| A row of the plan | **blok** | onderdeel, oefening |
| A library entry | **oefening** | drill |
| An animated diagram | **scène** | animatie, diagram |
| Recorded observation | **observatie** | notitie, beoordeling |
