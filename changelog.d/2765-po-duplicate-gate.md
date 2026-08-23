# A translation can no longer silently revert to English after a merge (#2765)

Bump: patch

The translation catalogues carry git's union merge driver so parallel branches
stop conflicting on them; the cost is that a union merge takes both sides. Once
the i18n sync has relocated a branch's appended entries into their sorted
position on main, merging main back leaves the branch holding both copies — and
git reports no conflict, because nothing disagreed. It happened four times in
one day on four separate branches.

Duplicate entries are what the compiler refuses, so one reaching main can break
the compiled translations for every language. The quieter case is worse: when
the two copies disagree, one translated and one emptied, gettext takes the first
and the Dutch string reverts to English with no error anywhere.

A new check fails any pull request that duplicates an entry the base branch does
not, and names the strings. It runs as pure PHP so it can be reproduced on any
machine, understands translation contexts (a contextual entry sharing a string
with its plain twin is not a duplicate), and ignores obsolete blocks the way
gettext does.
