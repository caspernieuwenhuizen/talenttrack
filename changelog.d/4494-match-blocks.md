# This week panel: game plan, result, minutes check, ratings and analysis (#4494)

The This week panel has new blocks under Matches. Before a match, **Game plan ready** says whether the match preparation has its game plan written. On a completed match:

- **Result** shows the score from our side whatever the venue (Won 2–1). Nothing shows when no score is stored, because empty is not 0–0.
- **Minutes check** compares the minutes entered with what the match holds (795 / 770 min), with the same verdict and words as the minutes audit and the minutes grid.
- **Player ratings** shows how many of the players who played are rated (Ratings 9 of 14), only to someone who may read that team's evaluations.
- **Match analysis** shows whether an analysis is written.

Three buttons follow from these: **Minutes + stats** when the minutes check is not complete, **Rate players** when players are still unrated, and **Write match analysis** when none exists and the match is not dated after today. Each shows only where the screen behind it will open for that person. By default the head coach sees everything on a desktop, and the result, the minutes check and the Minutes + stats button on a phone. The head of development and the academy admin get the minutes check only when an academy switches it on for them, because it reads each team in turn. `GET /me/week` returns the new fields, and a week of one team's matches is read without a query per match.
