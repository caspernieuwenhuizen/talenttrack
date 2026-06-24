# POP goals: per-goal progress % + evaluation evidence (#1717)

Bump: minor

Fills in the two POP-card slots the restyle reserved but never rendered.

- **Per-goal progress %** — `tt_goals` gains a `progress_pct` (0–100) field a
  coach sets on the goal form; the POP card now shows the progress bar.
- **Evidence (Bewijslast)** — a new `tt_goal_evidence` table links specific
  evaluations to a goal. The goal form gets an evidence picker (tick the
  player's evaluations); each linked evaluation renders on the POP card as a
  scored chip — *Assessment 12 Mar · 6.5* — from its date + overall
  (average-rating) score. Stored separately from the methodology links.

Migration 0173 (additive). With #1754's collapsible cards + per-goal
conversation, the POP page now matches the deck mockup.
