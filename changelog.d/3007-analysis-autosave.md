# Match analysis saves itself, and publishes when you say so (#3007)

Bump: minor

The match analysis no longer has a Save button. It autosaves as you write,
through the same shared component match preparation uses, so the status line,
the words, **Undo** and **Revert changes** are identical on both screens. This
is the surface the save-model work started from: a coach writing up a game on a
phone after the final whistle is composing over minutes, not filling in a
record, and losing a paragraph to a tapped Back button is the failure that
matters there.

What "abandon a half-written draft" turned into is better than what it
replaced. Every analysis is a **draft** until **Mark as final** is pressed, and
autosave only ever writes the draft. That one button is a publish, not a save:
the staff share link stays valid throughout, but shows *This analysis is not
finished yet* rather than half a sentence about a named child — so the
guarantee the link has always carried survives autosave. An analysis already
marked final stays final when it is reopened to fix a typo; reopening a
published document does not unpublish it from the people who were sent the
link.

Two people can open the same analysis — a head coach in the stand, an assistant
on the touchline. If the other person saves while you are writing, your next
save is refused rather than merged, and the status line says so and asks you to
reload. Quietly overwriting a colleague's write-up of a child, sentence by
sentence, with neither of you told, is the worse outcome. The endpoint carries
a version token for this, and a PHPUnit suite pins the three properties
autosave now depends on: absence is never deletion, the token moves on every
write whichever of the four tables changed, and a stale write is refused
without having written.

There is no Cancel on the form any more, because there is nothing uncommitted
to cancel; leaving the page leaves a draft.
