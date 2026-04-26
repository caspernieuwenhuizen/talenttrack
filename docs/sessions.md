<!-- audience: user -->

# Sessions

A **session** is a training event. Each session has:

- A team it belongs to
- A date and time
- Optional location
- A list of attending players with an attendance status (Present, Absent, Late, Excused — configurable via [Configuration](?page=tt-docs&topic=configuration-branding))

## Creating a session

From the **Sessions** admin page or the coach frontend:

1. Pick the team.
2. Set date and time.
3. Optionally add location and notes.
4. Save — the player list auto-populates from the team roster.
5. Mark each player's attendance.

## Attendance tracking

Attendance status values are lookups — add new ones in Configuration → Attendance Status. Each status is just a label; no special business logic beyond the label itself and the count.

## Session reporting

Session counts don't currently feed into the main analytics (Rate Card, Comparison). They show on the player's profile under "Sessions attended" and power the frontend dashboard's attendance view.

## Archiving

Like other entities, sessions can be archived to clean up old seasons without losing history.
