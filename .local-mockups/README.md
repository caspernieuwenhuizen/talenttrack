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

## Not yet mocked (high-value candidates)

- `player-profile/` — primary working surface, 7-8 tabs. Highest leverage; would resolve recurring "I can't find X" pilot feedback.
- `coach-dashboard/` — widget grid layout, hero priorities.
- `team-detail/` — multi-zone (roster + fixtures + KPIs + planner relationship).

Pattern-locked surfaces (wizards, flat CRUD, list tables, settings) don't need mockups — the framework already governs their shape.
