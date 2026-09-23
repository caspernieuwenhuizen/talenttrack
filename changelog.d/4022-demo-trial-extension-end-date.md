# Demo trial extensions move the case they extend (#4022)

A seeded trial case with an extension contradicted its own history: the
extension row said the window had moved two weeks out, while the case kept its
original end date, `extension_count` 0 and status `open` — so the decision
deadline counted down to a date nobody was working to, and every demo
walkthrough of the trial flow showed the contradiction.

The demo generator now records the extension and updates the case as one step,
which is what both production paths do. Existing demo data is not rewritten;
regenerate the demo academy to pick it up.
