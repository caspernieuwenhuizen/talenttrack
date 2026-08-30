# A team's roster is no longer readable by typing its id (#3152)

Bump: patch

`tt_view_teams` answers "may this person look at teams". Four surfaces read it
as "may this person look at **this** team" and then took the team id from the
URL. The capability is club-wide on the coach role, so a head coach could walk
`?tt_view=teams&id=1,2,3…` and read every squad in the academy — the full
active roster, the trial roster, the staff list — and reach the edit form
behind each one. `GET /teams/{id}` and the peek endpoint had the same gap.

All four now ask the caller's team scope: a global `team` read sees every
squad, everyone else sees the teams they are assigned to. That is the narrowing
`GET /teams` — the list — has always applied when deciding which rows to
return; the detail simply never asked. The two `minutes-share` routes beside
`GET /teams/{id}` carried the same club-wide gate and return per-player
minutes, so they take the same check.

Archived teams still open read-only for the coach who ran them: whether you
coach a squad is a fact about the assignment, not about whether the squad is
still running.

An administrator, and any persona with a global read on teams — Head of
Development, Academy Admin, Club Admin, Read-Only Observer — is unaffected.
A coach who finds a team page has stopped opening should check their team
assignments under People → Functional roles; that is where the scope comes
from.

The teams-manage loader also gained the club scope its sibling loader on the
same screen already carried.
