# Matches tiles open the matches behind them (#4185)

Bump: patch

The *Matches recorded* tile on Team · Minutes distribution and the *Matches* tile on the Season summary now open the activities list with Type = Match selected and the report's window applied, instead of an empty list with a raw `Type: match` chip. The Match type filter (on the list, the calendar and `GET /activities`) also includes matches stored under the older `match` key, such as fixtures created from a tournament, and an old link carrying `activity_type_key=match` opens as Match. The Season summary *Matches* count now counts games; it had been counting only tournaments and legacy rows.
