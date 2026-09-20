# Team managers can open the attendance reports for their own team (#3770)

The attendance-at-risk list is the one surface that answers "which of my
players keep missing training", and a team manager asking for it about their
own squad was refused. The three attendance report routes gate on the
analytics capability, which the seed granted to head of development and
academy admin only; nothing below the gate was wrong, because the report rows
were already narrowed to the teams the reader is assigned to.

A team manager now reads them — their own squads, and no others. The grant is
given twice on purpose: to the team-manager persona, and to the Manager
functional role, because an academy is as likely to run its team managers as
Staff accounts holding that role. The attendance leaderboard and the
per-player attendance rows open on the same terms, since all three hang off
one capability. Existing installs get the persona's row from a top-up
migration that never overwrites a matrix row an operator has edited.
