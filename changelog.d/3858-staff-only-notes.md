# A staff-only note stays within the staff (#3858)

Bump: minor

A note marked staff-only by somebody without the right was stored as a
public note, with no error and no warning — so the team manager, the first
aider and the assistant coach wrote internal notes about a child that the
child's guardian could read, believing they had kept them in-house.

Marking a note staff-only now has an entitlement of its own,
`staff_only_notes`, instead of borrowing the right to change an evaluation.
It is granted to the assistant coach, head coach and team manager on their
own teams, to the head of development and academy admin academy-wide, and to
the Physio and Manager functional roles on the squads they hold them on. A
top-up migration adds the rows to installs that already carry a matrix.

A request to mark a note staff-only without the right is now refused, naming
the right, and nothing is stored — the typed text stays in the box. The edit
path, which had no entitlement check at all, is gated the same way in both
directions: hiding a note and revealing one need the same right, in the
repository as well as over REST. The staff-only checkbox is no longer shown
to an author who cannot use it.

Who may read a staff-only note is unchanged, and a guardian never sees one.
Notes written before this are deliberately left alone: nothing recorded that
a widening happened, so re-classifying them could only be guesswork.
