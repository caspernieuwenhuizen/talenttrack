# Saved-views form stays collapsed until you ask for it (#2793)

Bump: patch

On thirteen list views — teams, players, people, goals, evaluations and the
rest — the "save these filters" form was permanently expanded, pushing its
name field and Save button off the side of a phone screen. Its label, meant
only for screen readers, was rendering as ordinary visible text and wrapping
across four lines.

The form now stays collapsed until "Save filters" is pressed, and wraps
inside the screen when open. The visually-hidden label style is defined once
for every dashboard surface rather than on one view, so labels intended for
screen readers stop showing up as stray text elsewhere too.
