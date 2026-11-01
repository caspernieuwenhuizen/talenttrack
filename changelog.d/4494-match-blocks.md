# This week panel: game plan, result, minutes check, ratings and analysis (#4494)

The This week panel has new blocks under Matches. Before a match, **Game plan ready** says whether the match preparation has its game plan written. On a completed match:

- **Result** shows the score from our side whatever the venue (Won 2–1). Nothing shows when no score is stored, because empty is not 0–0.
- **Minutes check** compares the minutes entered with what the match holds (795 / 770 min), with the same verdict and words as the minutes audit and the minutes grid.
- **Player ratings** shows how many of the players who played are rated (Ratings 9 of 14).
- **Match analysis** shows whether an analysis is written.

Three buttons follow from these: **Minutes + stats** when the minutes check is not complete, **Rate players** when players are still unrated, and **Write match analysis** when none exists. Each shows only to someone who may edit that team's activities and open the screen behind it. Defaults per role follow the epic: the head coach sees everything on a desktop, and the result, the minutes check and the Minutes + stats button on a phone. `GET me/week` returns the new fields, and the whole week is read without a query per match.
