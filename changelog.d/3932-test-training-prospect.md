# The New test training form carries the prospect it was opened for (#3932)

Bump: patch

The head of development's route into inviting a prospect — the *Arrange test
training* button on a prospect's pipeline card — opened a form with no
prospect on it, so the session they created was linked to nobody and the child
stayed in the first column.

The form now has a **Prospect** field. Arriving from a card, that child is
already picked; arriving cold, it is an ordinary picker set to *Nobody yet*,
because scheduling an open session is a normal thing to do. Saving with a
prospect attached records the invitation the same way completing the *Invite
to test training* task does, so the two routes leave one shape of record
rather than two, and the prospect moves to **Invited** either way.

An id in the URL that does not resolve — mistyped, or a child the viewer may
not see — opens the field empty and says nothing further. The consent rule
holds on this route as it does on the task: a child whose family has not
agreed cannot be attached, and the refusal leaves nothing saved.

The invite task's own form was also moved off its hardcoded inline styling
onto an enqueued stylesheet reading the design tokens, so a club running its
own theme no longer gets the plugin's colours in the middle of its own.
