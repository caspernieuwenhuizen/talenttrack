# Tournament auto-balance is now a per-academy toggle (#1979)

Bump: patch

The greedy fair-share auto-planner for tournament matches is now a toggle
on the Modules management page (**Tournament auto-balance**), on by default
so nothing changes on upgrade. Switch it off and the Auto-balance button is
removed from every match card and the `auto-plan` REST route returns 403, so
the toggle can't be bypassed by a direct call; the per-match planner grid and
manual click-to-swap planning are untouched. Closes out the last actionable
item from the #1538 FeatureRegistry tracker.
