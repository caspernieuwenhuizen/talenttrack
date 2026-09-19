# Match prep starts from the match length set on the activity (#3682)

A match carries its length twice — the **match length** on the activity and
the **half length** on the match preparation — and nothing connected them. A
coach who set an U11 match to 60 minutes still got a preparation planned at
2 × 35, and since every minutes surface reads the preparation, that
10-minute disagreement landed in the player's recorded playing time.

A new preparation now takes its half length from the activity's match length,
halved and rounded up, before falling back to the age-category setting and
then to 35. Clearing the Half length box resolves the same way.

The two values stay independent after that: changing the match length on the
activity does not rewrite a preparation that already exists, because that
would move a player's recorded minutes without anyone asking. When they
disagree the preparation says so, in a note under the Half length box, and
nothing is blocked. `GET` and `PUT /match-prep/{activity_id}` carry the same
two fields — `activity_match_length_minutes` and `half_length_mismatch` — so a
non-WordPress client can show the same warning.
