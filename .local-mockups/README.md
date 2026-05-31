# Local mockups

Plain HTML + CSS prototypes for surfaces where a visual design pass before filing the implementation issue pays off. No PHP, no build step, no framework — open the `index.html` directly in a browser.

## Workflow

1. **Pick a surface** to design (typically the high info-density ones — see #947 / #945 conversation history for the shortlist).
2. **Iterate the HTML** in the surface's directory until the layout, density, and interaction reads right at the target viewport.
3. **Test on real device** — for mobile-first surfaces (most of them), open in your phone's browser via dev server or a tunneled URL.
4. **When the design settles, file the implementation issue** with the mockup directory referenced as the spec. The executor's job becomes "port this HTML/CSS to the rendered PHP/template output" — small risk, no design questions to guess at.
5. **Once shipped, the mockup stays** as the design-of-record for that surface so future refinements have a baseline to diff against.

## Why this lives in the repo (not in a Figma / external tool)

- Versioned alongside the code that implements it — diff history matches the implementation history.
- Plain HTML/CSS = anyone can iterate, no tool licenses, no export/import friction.
- The same CSS conventions the production codebase uses (`.tt-*` prefixes, CSS variables, mobile-first) so the port is mechanical.
- Untracked in `.gitignore` is **not** desired — these ARE part of the repo's design-of-record. Commit them.

## Directory convention

```
.local-mockups/
  README.md           ← this file
  <surface-slug>/
    index.html        ← main mockup; multiple states toggled via a state picker
    notes.md          ← optional — open design questions, things still to test on real device
    screenshots/      ← optional — captured states for stakeholders who can't open the HTML
```

## Surfaces currently mocked

- `match-execution/` — live-match sideline view (mobile-first, 360×640 budget). State picker at top toggles between not_started / first_half / half_time / second_half / pending_review / finalized (legacy `finished` still selectable for diffing).
- `match-executions-list/` — dedicated listing surface at `?tt_view=match-executions` PLUS the `MatchesNeedingReviewWidget` hero-widget preview (toggle via the picker). Surfaces the orange "pending review" pills and grey "finalized" pills that the list view + execution view share.
- `wizard-chrome/` — three visual alternatives for the wizard step indicator + action button row, alongside the v3.110.102 baseline for diffing. Toggle via the picker; "Compare all 3" lays the variants side-by-side at ≥1100px. Brief in `notes.md` covers tradeoffs + a recommendation.
- `player-goal-intake/` — **print** mockup (A4 portrait) for the season-start goal-setting 1:1. Two surfaces in one file via the picker: (a) per-player intake form, **3 pages** (snapshot → goals 1+2 → goal 3+afronding), printed before each conversation; (b) methodology reference sheet, **up to 3 pages** with per-section selection (Spelprincipes / Voetbalhandelingen / Leerdoelen), printed once and brought to every meeting. Fully Dutch on print; EN source → NL .po mapping table in `notes.md` for the executor. Vocabulary lifted verbatim from `database/migrations/0018_methodology_full_content.php`.
- `vct-phase2/` + six sibling dirs (`vct-session-wizard/`, `vct-session-coach-view/`, `vct-library/`, `vct-config-tiles/`, `vct-team-panel/`, `vct-phv-flag/`) — **VCT Phase 2 mockup batch** gating implementation children VCT-9 through VCT-14 (#1062). Shared design tokens + intensity bands + exercise categories documented in `vct-phase2/notes.md`. Each surface mockup is responsive (mobile-first 360px), uses the same teal accent + intensity palette, and references the others where cross-surface concerns appear (PHV exclusions surface in both `vct-phv-flag/` and `vct-session-wizard/` step 4).
- `standard-reports/` — **12 report mockups** gating implementation children of #1035 (#1063). 6 CURATED reports as full responsive views (`player/minutes-played/`, `team/minutes-distribution/`, `team/squad-evaluation-summary/`, `season/season-summary/`, `season/trial-funnel/`, `scout/scout-report-card/`) + 6 PRESETS as single-screen explorer-config snapshots (`player/evaluations-received.html`, `player/goal-progress.html`, `team/activity-volume.html`, `activity/evaluation-coverage.html`, `activity/attendance-vs-squad.html`, `season/prospect-logging.html`). Shared CSS `_shared.css` (curated) + `_preset_shell.css` (presets); shared notes at `standard-reports/notes.md`.

## Not yet mocked (high-value candidates)

- `player-profile/` — primary working surface, 7-8 tabs. Highest leverage; would resolve recurring "I can't find X" pilot feedback.
- `coach-dashboard/` — widget grid layout, hero priorities.
- `team-detail/` — multi-zone (roster + fixtures + KPIs + planner relationship).

Pattern-locked surfaces (wizards, flat CRUD, list tables, settings) don't need mockups — the framework already governs their shape.
