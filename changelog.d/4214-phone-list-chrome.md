# Tighter list pages on phones: one-row page head, no filter card, steady goal badges (#4214)

Bump: patch

On a phone, the Players and Teams page heads stay on one row: Import from CSV (and Player accounts, for admins) move into the ⋯ menu below 768 px, next to the + button. The collapsed filter bar on list pages no longer sits in its own card around a single Filters button, so it takes the height of the button. In list rows, a long title wraps beside the badges instead of pushing them onto a line of their own, the badges stay at the top right, and a badge with nothing to show (a goal without a due date) is left out instead of printing "—". Tablet and desktop are unchanged.
