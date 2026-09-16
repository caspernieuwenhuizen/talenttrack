# Activity list: how much of each register actually exists (#3447)

Bump: minor

Every completed activity on the activities list now carries a small `N/N`
on the right of its card saying how much of its register was really
recorded — attendance on a training, attendance and minutes on a match.
The counts are right-aligned and use tabular numerals, so they line up
down the list and a session nobody wrote up stands out without opening
anything. A card reading `0/14` also offers a link straight to that
activity's column in the attendance grid, because a completed activity
with nothing recorded is a task rather than a statistic.

The counts read recorded (`record_type = 'actual'`) rows only. The
planned roster lives in the same table and carries real statuses, so a
count that included it would have reported a full register for exactly
the activity whose register is missing — the failure this readout exists
to surface. The denominator is the roster the coach planned for the
activity where one was captured, otherwise the team's current squad, so a
September training keeps reading `14/14` after somebody leaves in March.
Minutes are owed only by the players marked Present or Late; guests count
on neither side.

The numbers come from the same `ActivityRegisterProgress` service the
empty-register warning grades on, so the card and the dialog can never
tell you different things about the same activity; the readout adds a
batched page projection that reads a whole list in two queries rather
than two per row. It is exposed on the activities REST payload as
`register`, so a non-WordPress front end draws the same row.
