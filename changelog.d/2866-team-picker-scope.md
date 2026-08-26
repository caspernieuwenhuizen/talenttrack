# Editing a player no longer removes them from their team (#2866)

Bump: patch

A head of development opening a player to change something small — a jersey
number, a date — found the **Team** field showing *— Selecteer —* rather than
the team the player is actually in. Saving the form then took the player off
that team, without warning and without anything on screen suggesting it would.

The dropdown was built from "teams you coach", which is the right question for
an assistant coach and the wrong one for a head of development, who oversees
every team and coaches none. With no options, nothing could be selected, and an
empty selection was saved as *no team*.

The list now follows what the viewer is actually allowed to see: everyone with a
club-wide view of teams gets every team, a team-scoped coach still gets their
own, and **the player's current team is always in the list** whatever the rest
of the rules decide. Saving a form without touching the Team field leaves the
player where they were; deliberately choosing the blank option still takes them
off a team, because that is how it is done.

Archived teams remain out of the list.
