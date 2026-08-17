# Demo data: measurements and the PDP cycle (#2464)

Bump: minor

Two of the screens that best show what TalentTrack is for rendered empty on a
demo install. Both are filled now.

Demo runs create a testing battery an academy would actually use — height and
weight, 10 m and 30 m sprints, countermovement jump, shuttle run, juggling,
passing accuracy, a dribble circuit and a focus self-assessment — with target
bands per age group, team testing sessions across the window, and a result per
player per session. Each test declares which direction is better, so a sprint
time and a jump height are graded the right way round.

Results follow a per-player trend rather than noise: a player sits
consistently above or below their age group and improves across the season, so
the progression charts show something real. A few players miss a round, so the
coverage indicator isn't a flat 100%.

The PDP side gets the season, a development dossier per player, its
conversation cycle, calendar links on what's still scheduled, and verdicts on
the dossiers that have closed. Conversations that have already passed are
conducted and signed off while the next one stays open, so both halves of the
screen have something in them.

All of it goes through the PDP repositories, so the conversation cycle is
spaced by the same planning-window rules as the real flow and a signed-off
verdict raises its timeline event.
