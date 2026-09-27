# Monthly report tests show each test's target and every result's standing (#4093)

The team monthly report's Tests section now shows each test's target for the
team's age group ("Target O14: ≤ 12:30") on screen and in the PDF, and a
Standing column with the same chip as the player profile's test register: on
target, just over / just under target, well over / well under target. A test
without a better or worse reads "no target" with the explanation once; a test
with no target for the age group shows neither. The wording, colours and
target formatting now come from one helper shared by the profile and the
report, which also fixes the profile's target for a timed test: it reads
`≤ 12:30` instead of decimal minutes. The data reaches REST too.
