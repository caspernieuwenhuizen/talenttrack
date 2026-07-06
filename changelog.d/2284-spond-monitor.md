# Spond integration monitor — see live what the Spond API returns (#2284)
Bump: minor

New diagnostic page (Spond → **Open integration monitor**) that fetches the Spond
API **live** for a team and shows exactly what's coming in — every event with its
classified type, date, times and location — plus a per-event **diff**: whether a
real sync would create it, or update an existing activity (and precisely which
fields it would overwrite), and which stored activities would be archived. It is a
**dry run**: nothing is written. This is the tool for answering "why does the
printed activity differ from what I set in Spond?" — a stale cache, a changed
event UID, or a field Spond owns all become visible at a glance.
