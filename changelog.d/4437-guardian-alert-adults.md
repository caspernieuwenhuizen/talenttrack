# No-guardian alert leaves out players aged 18 and over (#4437)

The "Player with no guardian contact" alert now covers players under 18
only. A player who is 18 or older on the day the alert runs needs no
guardian and is no longer flagged, so a JO19 squad's adults stop drowning
out the minors nobody at home can be reached about. A player with no date
of birth on file is still flagged, so the alert does not go quiet because a
field is empty. The alert's description says "under 18". Demo data is
unchanged.
