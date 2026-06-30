# Manual match-minutes entry on the attendance screen (#2159)

Bump: minor

A coach who runs a "paper match" without the sideline match-execution flow can
now record minutes per player directly on the activity's attendance screen.
The minutes land in `tt_attendance.minutes_played` as actual, non-guest rows —
the single source the minutes reports read — so they flow straight into the
Player · Minutes and Team · Minutes reports. The minutes report now also surfaces
such matches even when they have no match-prep lineup.

The orphaned "Minutes Played" field on the evaluation form is removed and the
plugin no longer writes `tt_evaluations.minutes_played` (a column no report
read). Precedence: a later match-execution recompute remains authoritative and
overwrites manually-entered minutes for the same match.
