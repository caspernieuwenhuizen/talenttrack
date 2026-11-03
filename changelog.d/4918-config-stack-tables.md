# Configuration tables on a phone: one card per row, every value labelled (#4918)

On a phone, the configuration tables now use the shared stacked-table layout:
one card per row, with each value next to its column name. This covers the
dashboard per persona, seasons, import history, invitations, the plan's
limits and feature matrix, the alert policy and the custom CSS history.
Several of these ran off the right of the screen on a phone; none of them do
now. A screen reader hears each one as a table with its column headers. The
dashboard per persona is a table again from 480 pixels wide, and the others
from 768 pixels wide, because their columns do not fit before that. On a
tablet and a desktop nothing changes.
