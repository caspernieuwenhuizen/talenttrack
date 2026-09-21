# Player report: playing time as a share, against the position group and the team (#3991)

Bump: minor

The player report's Playing time section now shows the player's share of the minutes the team had available, next to matches and minutes played, on screen and in the PDF. For staff it adds two comparisons: the average share of teammates who share one of the player's profile positions (the player excluded, with the positions and the group's size), and the team average over the current squad (the player included, a squad player who did not play counting as 0%). On screen each comparison says whether the player sits above or below it. The numbers come from the same team minutes query as the team minutes report, so they agree. A family snapshot and a scout link keep the player's own share but never the comparison: it is removed in the report's audience layer, so the REST payload and the shared snapshot don't carry it either. In a small group an average would be another child's minutes.
