# Demo data: played matches carry their score, so team records count them (#3579)

The demo generator wrote each match's score onto the live match record but
never onto the activity itself. The team record, the form line and the
match result card read the score from the activity, so every demo team
showed 0 played, an empty form line and its matches listed as "without a
score", next to a full list of goalscorers. The generator now writes the
scoreline onto the activity, as finishing a live match does. Upcoming
fixtures stay without a score. Existing demo data keeps the gap until the demo
is wiped and regenerated.
