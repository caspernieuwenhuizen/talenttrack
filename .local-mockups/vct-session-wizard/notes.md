# VCT session wizard — design notes (VCT-9)

5-step wizard for creating a new VCT (training session) record.
Reached via `?tt_view=wizard&slug=new-vct-session` per CLAUDE.md §3
(wizard-first for record-creation flows).

## Steps

1. **Basis** — team / date / start time / duration / optional title.
2. **MD context** — match-day chip bar (MD-5 … MD+2); optional link
   to an upcoming match so the engine derives accent + intensity.
3. **Macro-blokken** — the engine pre-fills 3 blocks (warming-up,
   hoofddeel, cooldown) sized from the team's age-profile and the
   MD context. Coach can add/remove/reorder exercises pulled from
   the library (VCT-11).
4. **Workload check** — totals + intensity + load-score. PHV
   exclusions (VCT-14) raised here.
5. **Review & publish** — final confirm. Publish triggers
   notifications to the team's coaches.

## Chrome

- V3 sidebar timeline (per #1036) on tablet+; collapsed step header
  on phones.
- Mobile button grid: Next / Back / Cancel stacked per CLAUDE.md
  feedback on touch-target hierarchy.

## Friction points

| # | Friction | Mockup response |
|---|---|---|
| 1 | Coach forgets MD context, intensity feels wrong | MD chip bar pre-paints the colour palette (cool blue for pre-match, teal for MD, warm for recovery) |
| 2 | Library picker breaks scroll context | Inline `+ Voeg oefening toe` opens a bottom-sheet picker, not a modal |
| 3 | Workload over-cap detected only after publish | Step 4 surfaces the calc before review — wizard refuses to proceed if a hard cap is breached |

## Open questions

- Should the wizard auto-save between steps (per existing wizard
  pattern)? Recommend yes — match wizards do this.
- PHV exclusion list rendered as a per-player chip list in step 4 or
  deferred to a separate "Exclusions" pane in step 5?
