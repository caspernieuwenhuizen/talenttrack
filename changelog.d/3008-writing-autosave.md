# Evaluations, PDP conversations, goals and player notes stop losing your work (#3008)

Bump: minor

The remaining writing surfaces from the save-model epic move onto autosave.
Editing an **evaluation**, a **goal**, a **PDP conversation** or writing a
**self-reflection** now saves as you go, with the same status line, the same
words, and the same **Undo** and **Revert changes** controls as match
preparation and match analysis. All four are places where somebody composes a
judgement about a player rather than filling in a known set of fields, and
losing that work is what stops a coach writing the next one.

**Creating still needs Save.** Autosave writes to a record, and while you are
adding a new evaluation or goal there is no record yet — pointing it at the
create endpoint would leave an empty row on a player's file behind everyone who
opened the form and thought better of it. Editing autosaves; adding does not.

**Sign-off leaves the PDP conversation form.** It was a checkbox you saved along
with everything else, which on a self-saving form is one accidental tap away
from locking a conversation for everyone, permanently. It is now its own **Sign
off** button below the form, behind a confirm. Everything above it is already
saved by the time you press it.

**Player notes work differently, on purpose.** A note is a post, not a field, so
it is still sent only when you press Send — nothing half-written reaches the
staff. What changed is that the compose box now keeps your draft: close the tab,
come back later on the same device, and the sentence you were in the middle of
is still there.

Under the hood, one data-loss bug is fixed. `PUT /evaluations/{id}` rebuilt the
whole row from the request, so any client sending only the fields it changed
silently blanked the player, the type, the date and the player-facing feedback.
It now writes only what it is given. A new test suite pins that contract on all
three endpoints, and pins that a signed-off conversation refuses further writes
in the endpoint rather than only in the screen.

The four grids are untouched and stay explicit-Save.
