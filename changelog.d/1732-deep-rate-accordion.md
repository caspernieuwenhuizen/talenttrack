# Deep-rate step: collapsible category accordion with aligned stars (#1732)

Bump: patch

The player-first new-evaluation Rating step is no longer a flat table of
stars with a Basic/Detailed toggle. Each main category is now a collapsible
block (collapsed by default) whose summary shows the category name, a
read-only star mirror, and the average word — so a coach can scan what's
rated without expanding anything. Expanding reveals the editable
category-level stars and the sub-skill rows; rating sub-skills still sets the
category to the rounded average of the non-zero subs, and the summary
reflects it live. The #1643 training default still surfaces the Mental
category first and opens it. All inline styles moved to a stylesheet; the
star column lines up across categories and sub-rows. Ratings submit and
restore exactly as before — no data-shape change.
