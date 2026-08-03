# Standard-reports query fixes: archived join, honest window + cap, last-evaluated date (#2346)

Three mechanical corrections to the standard reports. The Season summary's
per-team match counts now exclude soft-archived activities on the join itself,
not just in the count, removing a source of inflated joins (values are
unchanged). Player · Minutes played now states its 12-month window in the page
sub-line and surfaces the "showing the 50 most recent matches" cap so a longer
history is never silently dropped. Team · Squad evaluation summary shows a
**Last evaluated** date per player so a stale row is visible at a glance.
