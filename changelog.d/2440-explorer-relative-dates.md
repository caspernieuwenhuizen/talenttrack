# Explorer: relative date bounds now actually narrow the results (#2440)

Bump: patch

The dimension explorer offered a relative date bound — its *Date after* box
even suggests `-30 days` — but nothing ever expanded it. The raw text went
straight into the query, where MySQL read it as `0000-00-00` and matched every
row, so the filter looked applied while quietly doing nothing. Four KPIs that
ship a 30-day default window were unbounded for the same reason.

Relative bounds are now resolved to a real date before the query runs.
`-30 days`, `-12 months` and `+7 days` all work, in `day` / `week` / `month` /
`year`, singular or plural. They stay relative: a saved explorer link keeps
meaning "the last 30 days" instead of freezing to the day it was saved.

A bound that is neither an exact date nor a recognised relative form — a typo
like `30 dayz ago`, or an impossible date like `2026-02-30` — is now dropped,
and the report renders without that bound rather than guessing at one. A filter
that silently narrows to the wrong window is harder to catch than one that
plainly isn't there.
