# List dates follow the academy's date format; the evidence packet translates "Trial ended" (#4221)

Bump: patch

Date columns in the goals (Due), holidays, tournaments, functional roles, prospects and training plans lists print the academy's date format instead of `2026-09-12`. Their REST rows gain a `<field>_display` sibling next to the unchanged ISO field, so a front end outside WordPress gets the same answer. The PDP evidence packet now shows "Trial ended" with the decision's translated label, as the player's journey already did.
