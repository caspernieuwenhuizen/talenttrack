# Scouting pipeline: every card opens the prospect, even with no next action (#1763)

Bump: patch

In the onboarding pipeline, a prospect card with no pending task (and not yet promoted) used to render as a dead, unclickable tile. Now every card is clickable: when there's no "next action" it focuses the prospect on the board — `?tt_view=onboarding-pipeline&prospect_id=N` opens a panel showing who they are, their stage, and a link to their next action when one exists. This also fixes the previously no-op `prospect_id` links from the dashboards and scouting-visit detail, which now land on a real focus.
