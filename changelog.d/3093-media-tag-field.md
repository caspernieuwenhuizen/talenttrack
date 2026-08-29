# Tag players while you add media, with an @ field instead of a hidden checkbox list (#3093)

Bump: minor

Tagging used to live in one place only: a collapsed "Tag players" disclosure on
each photo, after the upload was already finished. The wizard that adds the
media never mentioned tagging at all, so a coach who added eight photos from a
training had to go back to the grid and open a disclosure eight times. Media
that is not tagged never reaches the tagged player's own record, so a control
nobody found was quietly costing the feature its point.

Step 3 of the add-media wizard now has a **Tagged players** field, applying to
everything added in that batch, and the photo's own control is the same field
rather than a checkbox list. Start typing a name and pick from the list, or type
**@** in the description — the name goes into the sentence and the player is
tagged. The chips under the field are the tags; editing the sentence afterwards
never silently untags anyone. The confirm step now says whose records the media
will also appear on before it is saved.

Keyboard throughout: arrows move, Enter picks, Escape closes, Backspace on the
empty box takes back the last chip.
