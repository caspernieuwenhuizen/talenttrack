# Remove a duplicated migration file (#3103)

The team-football-form migration (#3044) shipped as `0243` after being
renumbered from `0242`, but the pre-renumber copy came back into the tree in
an unrelated pull request. Both ran, under two different ids — harmlessly,
because the body is idempotent, but the directory then told the next
migration author a number was taken when it was not.

The stray `0242` file is deleted; `0243` is untouched. No install ever
applied the stray id, because it was caught before the release that would
have carried it.
