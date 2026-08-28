# Demo data now records minutes played, so the minutes surfaces have something to show (#3029)

Bump: patch

A demo install had matches, line-ups, substitutions and goal events but no
minutes played, so every minutes surface was empty on the dataset the product is
demonstrated with — the minutes report, the minutes audit, minutes share, and
the player profile's minutes figure.

The substitution stream already held the answer; nobody derived it back onto the
attendance record. Now a starter who played the whole match gets the full match,
one taken off gets the minute they went off, and a substitute gets what was left
when they came on — so a team's total lands exactly on squad size times the match
length, and no player exceeds it. Demo match analyses pick the same numbers up.

A player who stayed on the bench has no minutes rather than zero, because "did
not feature" and "played nothing" are different facts and the minutes surfaces
exist to tell them apart.
