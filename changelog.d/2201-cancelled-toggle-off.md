# Activity list: the "show cancelled" toggle can be switched off again (#2201)

Bump: patch

The "Geannuleerde tonen" toggle on the activities filter bar could be turned
on but not back off — once enabled the flag stayed set. The shared toggle
control now supports an explicit off-value: turning the switch off submits
`show_cancelled=0` (via a hidden companion field) instead of merely omitting
the param, so the cancelled filter clears and the switch reflects the off
state on reload.
