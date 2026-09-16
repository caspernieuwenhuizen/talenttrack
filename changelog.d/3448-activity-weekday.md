# Activity dates now say which day of the week they are (#3448)

Bump: patch

An activity is a scheduled event, and a coach thinks in "Friday training"
rather than "the 11th" — but the activities list and the activity detail
page were the two surfaces that printed no weekday anywhere, while eight
others already did.

The list card's date tile now stacks weekday, day and month, and stays
square (52px instead of 44px): three lines centred inside a fixed square
cost 8px of row height, where stretching the tile into a 44-wide
rectangle cost 13px. The detail hero, the date fact, the record spine,
match prep, match analysis, the match list, the ratings grid and the
player's own activity list all carry the weekday too, via a new
`TTDate::dateWithDay()`.

It **composes** with the academy's configured date notation rather than
replacing it — `Fri 11-09-2026` if you picked `31-12-2026`, `Fri
2026-09-11` if you picked ISO — and it takes the day name from the locale
exactly as that language writes it, so Dutch reads `vr` and not `Vr`. A
System-default format that already names the weekday is left alone rather
than printing it twice. Plain dates are untouched: a player's date of
birth and an audit stamp gain no weekday.

Three long-standing defects fixed alongside it: the team page's upcoming
activities printed the raw `2026-09-11` out of the column with no
formatter at all; the player profile's row badges built their month
abbreviation with `gmdate()`, so every one read English on a Dutch
install; and a match's facts strip had no Date fact at all — it ran from
Opponent to Formation, and the date cell existed only on the training
branch.
