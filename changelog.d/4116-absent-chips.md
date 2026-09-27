# Match prep: Absent (excused) stores Excused, and a plain Absent chip (#4116)

The availability drawer in match prep stored *Absent (excused)* as `Absent`
with "Excused" in the reason, so the minutes audit and the attendance
projection saw an unexcused absence where the coach meant an excused one. It
now stores `Excused`, the same status the match-prep availability step
writes. A new **Absent** chip records an unexcused absence (a no-show), which
the at-risk list then sees. Rows saved the old way load onto *Absent
(excused)* and are stored correctly on the next save. The drawer chips are
now 48px tap targets with 8px between them.
