# Live match execution: sub controls visible by default again (#2261)

Bump: patch

Fixes a regression where, during a live match, the substitution controls
(the bench "→ on" buttons and the "who comes off" panel) plus the score /
goal steppers were hidden behind the "Edit" toggle — so a coach on the
sideline saw only the bench list and couldn't sub. The read-only-by-default
edit gate now applies only to post-match editing: a live in-progress match
(first half / half time / second half) opens with the mutating controls
already revealed, while the post-match review window keeps the accidental-edit
guard (tap Edit to enable) and finalized matches stay fully read-only.
