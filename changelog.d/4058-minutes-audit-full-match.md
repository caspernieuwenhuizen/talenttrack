# Minutes audit: a game is only Complete when its minutes add up to a whole match (#4058)

The minutes audit called a game Complete as soon as every squad player had some
minutes, so eleven players with 30 minutes each of a 70-minute match showed a
green chip. Complete now also needs the total to equal what the match holds:
players a side (from the team's football form) times the match length, taken
from the activity, then the age group's configured length, then the scheduled
start and end, then 2 × 35. A game with fewer or more minutes than that reads
Incomplete, and the row shows the recorded total against the available one
with the reason. `GET /reports/minutes-audit` carries the new
`available_minutes` and `status_reason` fields.
