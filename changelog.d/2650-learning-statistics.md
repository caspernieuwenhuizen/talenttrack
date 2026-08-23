# Three new reports: who has done which course, and where people get stuck (#2650)

Bump: minor

The knowledge library has recorded progress since it shipped, but there was
nowhere to see it across the academy. Three reports now sit in the Reports
launcher alongside the others.

**Learning · Course completion** — per course: how many are enrolled, not
started, in progress, completed and overdue, the median number of days people
take, and **the lesson readers stop at**. That last column is the one worth
looking at: it is the only number here that says something about the course
rather than about the coaches. A lesson half the group stops before finishing
is usually badly written, badly placed, or asking for something they cannot do
yet — and a completion percentage never tells you that.

**Learning · Per person** — who is on what, how far they have got, what is
overdue, and what is sitting with a reviewer.

**Learning · Staff coverage per team** — for each squad, how much of the staff
around it has finished each course. *The U13 staff are 2 of 4 on the
periodisation course.*

Three levels of access, as elsewhere in the module. A coach sees **their own
record** — not an error page — and needs the learning-statistics permission to
see anyone else's. That is enforced behind the scenes as well as on screen, so
the numbers cannot be reached another way.

Overdue and coverage are shown as labelled tags — "3 overdue", "All trained",
"2 to go" — rather than colour alone, so the tables read correctly for anyone
who does not distinguish red from green. The course report exports to CSV with
readable values rather than internal codes.
