# Player report: one player's season so far, as data (#3872)

Bump: minor

The first slice of the player report (epic #3871): `GET /players/{id}/report`
composes one player over a window from sixteen blocks — status, ratings,
attendance, minutes, goals, PDP, tests, journey, injuries and more — defaulting
to the season so far and the blocks a one-to-one conversation needs. It reads
the same evidence the PDP Evidence tab and printed PDP file read, extended to
work for a player who has no PDP file yet, so the numbers cannot disagree.
Coach-facing: it needs a reports grant on the player's team and access to the
player, and each block keeps its own privacy gate for the reader — injuries on
the medical rung, journey and tests on the reader's visibility levels, staff
notes as on the player file. The screen and the PDF follow in the next slices.
