Bump: patch

**A privacy registration that quietly covered nothing.** The PII registry listed
evaluation ratings against a player column that table does not have — a rating
reaches a player through its evaluation, not directly. The registration was
therefore doing nothing, while the registry reported it as covered. Ratings were
never missing from an erasure or a subject-access export, because both already
follow the parent evaluation, but the registry now says so honestly. A test
checks every registration against its table, so the next one fails the build
instead of going quiet.
