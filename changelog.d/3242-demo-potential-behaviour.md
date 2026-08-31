# The demo academy has behaviour, potential, and players old enough to have them (#3242)

Bump: minor

The demo academy had **no potential and no behaviour data at all**, and its oldest player was seven. Two of the traffic light's four inputs were empty for every player, so the status it showed was not the status the product produces for a real club — and the potential trajectory, the team-chemistry contribution and the PDP evidence packet all rendered blank. A feature that shows nothing looks like a feature nobody uses, and this is the academy a prospective club is walked through.

Demo squads are now **spread across your age-group ladder** instead of taken from the youngest end, so a three-team academy gets a young squad, an older one and something in between. Age groups whose name carries no age — a *Senior* catch-all — are skipped, because the generator reads a player's birth year out of the group name and would otherwise fill a senior squad with children.

Behaviour ratings are seeded across the window for every squad. Potential is seeded as a **dated history** rather than a single row, so the trajectory has something to draw, with at least one player per squad revised *down* — the case the trajectory exists to make visible.

The gaps are deliberate. About one player in five old enough for a band does not have one, and one per squad is left overdue, so the *Potential not revisited* alert has something true to say on a demo install. Potential is not seeded below age 13 at all, matching the rule the product now applies.

Also fixes the demo-coverage check, which could not see tables created by migrations that build the table name into a variable first. Six such tables were invisible to it; all six now have a decision recorded.
