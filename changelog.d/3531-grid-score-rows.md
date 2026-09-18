# The minutes grid can record the score (#3531)

A club catching up on a month of post-match admin enters six results and ninety
minute-cells in one pass. Six visits to six match pages was the wrong tool for
that, and the grid was already the right shape — one column per match.

It was also already asking the question. The **Attributed / score** row at the
bottom has always compared the goals you attributed against the match score, and
fell back to a bare count whenever no score was recorded — which is every match
not run on the live match sheet. The grid asked and gave you nowhere to answer.

Two rows above the players now carry your goals and the opponent's, one pair per
match, saved with the same Save button and discarded by the same Cancel.

Three behaviours worth knowing:

- **Clearing a box records no result, not 0–0.** A match with no result counts
  as played and stays out of the won/drawn/lost record, which is what keeps the
  team record honest.
- **A match run on the live sheet shows its score as text.** That score follows
  the goal log, so changing it means correcting a goal in the post-match review.
  The minutes on the same column stay editable, because a minute correction
  survives a recount and a score correction would not.
- **Tournament columns have no score boxes.** A tournament day is several
  matches and one scoreline cannot describe it.

The score is the same number the match's own page shows, written through the
same place — two ways in, one number.
