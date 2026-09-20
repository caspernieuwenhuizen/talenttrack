# Minutes audit refuses an out-of-scope team instead of reporting it empty (#3792)

`GET reports/minutes-audit` answered a team the caller may not read with an
empty matrix and a `200`. An empty matrix is a claim about the data — this
team recorded no minutes — so a coach checking whether another age group had
logged theirs got a confident, wrong answer they could not tell apart from a
real gap. The route now refuses with `403 forbidden_team`, the same refusal
the three attendance readers on the same controller already answer with, and
through both the plain `team_id` and the nested `filter[team_id]` spelling.

A team you may read that genuinely has no minutes recorded still comes back as
an empty matrix with `200`, so "there is nothing here" and "you may not look
here" stay two different answers. The per-match editor refused already and is
unchanged, as are the matrix contents for anyone in scope.
