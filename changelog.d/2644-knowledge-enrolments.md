# Knowledge library: the system now remembers where you got to (#2644)

Bump: minor

Courses have shipped as files since #2642 and been readable since #2643. This
adds the half a file cannot carry: who is on which course, how far they got,
and what they still owe.

Four tables behind one rule. A lesson is finished when everything its front
matter asks for has happened — read it, pass the quiz if it has one, get the
assignment approved if it has one — and a course is finished when all its
lessons are. That rule lives in one service, because the reader, the
lesson-unlock gate and the completion report all have to give the same answer
and there is no version of this where they may disagree.

Two consequences worth knowing. Requirements are read from the course files
every time rather than frozen at enrolment, so revising a course to add a
lesson reopens the people who finished the old version instead of leaving
them certified for work they have not done. And completion is reversible: a
reviewer who withdraws an approval drops the enrolment back to in-progress,
because a certification standing on a verdict that no longer stands is worse
than no certification.

Quiz attempts are kept in full, not collapsed to the latest. A coach who
passed on the fourth attempt has a different development record than one who
passed first time, and that is exactly what a head of academy reading the
record wants to see.

Three capabilities rather than the usual two: a coach can see their own
progress without seeing their colleagues', because the roll-up is a separate
grant. Assignment attachments ride the media library rather than growing a
second upload path.

Demo data covers all four tables with a deliberately mixed cohort — some
finished, some mid-course, one overdue, one assignment waiting in the review
queue — so the completion report has something real to render before anyone
has used the feature.

Still not readable in the app: the reader view is #2646.
