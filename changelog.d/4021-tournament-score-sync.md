# Tournaments: a fixture's result reaches its activity, and a fixture is no longer treated as a home game (#4021)

Completing a tournament fixture recorded its result on the fixture and nowhere
else. The match activity it created kept `home_score` and `away_score` empty, so
a 3-2 read as "no result recorded" everywhere that activity was the thing being
read — the minutes overview included. Meanwhile the minutes overview offered its
own pair of editable score boxes for that same fixture, so one match had two
independent scorelines and whichever was typed into last quietly won.

The fixture is now the single store. Its score travels to the activity when the
fixture is completed, when a score is corrected afterwards, and when a fixture
that was scored in advance is kicked off. The minutes overview shows that score
read-only, pointing back at the planner.

**And a tournament fixture is framed as neither home nor away.** A game at a
tournament has no home leg, and the fixture carries no home/away marker — but
the minutes overview read "anything that is not literally away is home", so
every tournament fixture was reported as a home game. Such a column now reads as
neutral: the two numbers are labelled with the club's short code and *Opp.*, and
nothing claims a venue that does not exist. An ordinary league match with no
marker still reads as home, as before.
