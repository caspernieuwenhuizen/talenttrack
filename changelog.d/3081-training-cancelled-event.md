# Cancelled trainings now tell the families (#3081)

Bump: minor

The "Training cancelled" message template has shipped since v3.110.18 waiting
on a hook nothing ever raised, so it could never send. Activities now fires
`tt_activity_cancelled` when a session moves into cancelled, and Comms listens
for it: the planned roster is resolved through the youth-contact rules —
parents for the younger age groups, the player from U12 up — and each family
is told once, even when two of their children are on the same roster. A
cancellation skips quiet hours, because a training called off tonight is
useless news tomorrow.

The event fires from the repository rather than from the buttons, because
cancellation has two write paths: the Cancel button on the activity detail
page, and an edit that sets the status to Cancelled from either the activity
form or the wp-admin page. Wiring only the button would have told half the
families and looked correct in testing. Re-saving an activity that was already
cancelled sends nothing.

Two things fixed alongside it. Cancelling from the wp-admin activities page
wrote the status but left the plan state behind, so the planner kept offering
a session that had been called off; both lifecycle columns now move together
there, as they already did over REST. And the recipient resolver was reading
the parent-link table in the wrong shape, so a player *with* linked parents
resolved to nobody at all — nothing had sent through it before, which is why
that went unnoticed.
