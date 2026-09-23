# Clean up after the retired report wizard (#3985)

The report wizard was retired in #3955; four leftovers go with it now. The
`.tt-rwz-*` and `.tt-report-wizard` rules in `frontend-scout-reports.css`
matched no markup and are deleted, and two comments in the authorization seed
that still named the wizard as the place per-player gating lives now name
`PlayerReportAccess`, which is where it actually is.

One class was not dead: the scout's *My players* report frame used
`.tt-rwz-report-host`, the wizard's prefix on a live surface. It is now
`.tt-smp-report-host`, with the same styling and the same print behaviour —
worth knowing if your academy has custom CSS targeting the old name.

`tt_generate_report` is **kept**, not removed, and both the code and
`docs/authorization-matrix.md` now say why: nothing reads it since the wizard
went, but it is granted on every existing install and bridged into their
authorization matrix, so deleting it would rewrite an operator's role grants and
matrix cells while changing no gate at all. No behaviour change anywhere in this
one.
