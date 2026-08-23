# Photo and video consent can now be recorded against a player (#2744)

Bump: minor

The media library stores photographs of children and had no way to record
whether the family had agreed to it. Academies were tracking that on paper
with nothing in the system to check against before a matchday.

Each player record now carries a **Photo & video consent** checkbox on the
edit form, beside the photo. Ticking it stores the date and the name of the
staff member who recorded it, so the entry is evidence rather than a bare
assertion. Clearing it removes both. The player's profile shows the answer
to staff — including when the answer is no, since a blank would read as
though nobody had asked.

**This records; it does not restrict.** Nothing about adding a photo checks
the box, and a coach can still add media for a player with no consent on
record. That is deliberate rather than unfinished: the real control is the
conversation and the form the family signed, and a hard block at the side of
a pitch tends to be worked around by photographing on a personal phone
instead — which leaves the child worse off than a recorded gap does. What
the field is for is answering *who may we photograph?* before the day, and
being able to show the question was asked.

Withdrawal is recorded by clearing the box. It does not reach back and
remove photographs already stored; those are removed from the player's
Media tab.
