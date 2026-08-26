# CI refuses a committed git conflict marker (#2891)

Bump: patch

A conflict marker once reached the main branch as a literal line of
`docs/rest-api.md`, committed while somebody resolved a merge. It sat there for
three days, and in the meantime every branch that touched the same file and
pulled main failed to merge — git cannot combine a file that already contains a
marker, so each of those had to be untangled by hand.

Twenty workflows already lint this repository and none of them read file bodies,
so nothing caught it going in. A new check now scans the whole corpus on every
pull request and fails with the file and line number.

It deliberately looks only for the `<<<<<<<` and `>>>>>>>` markers, never for a
bare row of equals signs on its own: in Markdown that is a heading underline,
and a seven-letter heading gets a seven-character underline, so no length test
can tell the two apart. Git always writes all three markers together, so the
angle brackets are enough to catch a real conflict without ever flagging prose.

Developer-facing only — nothing about the product changes.
