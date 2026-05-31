# VCT team panel — design notes (VCT-13)

Inline panel rendered on the **team detail** view (`?tt_view=teams&id=N`)
that carries the team's VCT training defaults — drives the session
wizard's prefill.

## Anatomy

- **Weekday chip row** — 7 chips (Ma → Zo), tap to toggle. Multi-select.
- **Default begintijd** — `<input type="time">`, 48px tall.
- **Default duur** — number input, 30-180 min range.
- **Trainingslocatie** — optional free-text.
- **Summary** — live "Volgende voorgestelde sessie: …" line so the
  coach can verify what the wizard will prefill.
- **Save / Cancel** row at the bottom.

## Why a panel, not a wizard

CLAUDE.md §3 exemption (a): settings sub-form, not a record-creation
flow. The team itself was created via the team wizard; this panel
tunes its operational defaults.

## Friction points

| # | Friction | Mockup response |
|---|---|---|
| 1 | Coach has to retype start time every session | Default begintijd persists per team |
| 2 | Multi-day teams (Di + Do) tedious to pick repeatedly | Chip row remembers the set; wizard picks the next instance |
| 3 | Coach wonders if changes "took" | Live summary line at the bottom previews the next session |
