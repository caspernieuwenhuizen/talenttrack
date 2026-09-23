# Planned attendance flags a player with an open injury (#4005)

Nothing on the planning path read the injury record, so a player who had hurt
their ankle on Tuesday was still listed as expected for Thursday's session,
and a head coach picking a squad had to remember it and leave them out by
hand. An assistant coach, whose role cannot open an injury at all, had no way
of knowing.

The Expected attendance panel, the Planned attendance section where the squad
is picked, and `GET /activities/{id}/planned-attendance` now flag every player
carrying an open injury as **Unavailable** — the same word match prep's
availability step uses. It is derived each time from the injury record and
never stored: it disappears the moment a return is recorded, and an old record
whose expected return has already passed does not flag at all.

It sits **beside** the plan status rather than replacing it, so a coach who
deliberately expects an injured player — a light session, rehab minutes,
travelling with the squad — keeps that choice. And it carries no medical
detail whatsoever: no injury type, body part, note, date or id, so planning
around it never means reading a child's medical record.
