# Minutes count every spell on the pitch, not the first one (#3850)

A player who came off and went back on in the same half lost the second
spell. A starter off at 20' and back at 25' of a 35-minute half was
credited 20 minutes instead of 30; a substitute on at 10', off at 25' and
back at 30' was credited nothing at all, because the arithmetic ran
backwards and clamped at zero. That figure is what attendance stores, so a
player who had been on the pitch for twenty minutes reached the minutes
report, their Minutes tab and the monthly team report as not having played.

Minutes and the squad timeline are now derived from one walk of the
substitution log that keeps every spell per player, so the bars and the
total cannot disagree — the timeline used to hold its own copy of the same
mistake, which is why nothing looked wrong. Matches with one substitution
per player are unaffected. No migration: re-running a match's recompute, by
editing any of its events, rewrites the stored minutes.
