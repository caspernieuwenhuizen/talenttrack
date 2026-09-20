# Player journey: real evaluation scores, no raw position codes (#3767)

The journey told families two things that were not true. Every evaluation
entry carried an overall score of 0, because it read the legacy single-score
column on the evaluation rather than the weighted overall the evaluation
screen itself shows, and that column is empty on every evaluation written
the normal way. Entries now carry the same overall a coach sees, they follow
the evaluation when the categories are re-scored, and an evaluation with
nothing rated on it carries no score at all rather than a zero a parent
would read as "he scored nothing".

Clearing a player's preferred positions wrote *"Position: Centre forward →
[]"* — the raw empty array the field stores. Emptying the field now writes
no entry at all, and no journey summary can carry raw JSON. The admin
**Rebuild journey events** action scrubs the `[]` out of entries already
written and refreshes the stored overall on existing evaluation entries.
