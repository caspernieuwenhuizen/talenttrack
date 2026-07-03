# Planned attendance is now editable on the activity edit form (#2248)

Bump: minor

The planned (expected) roster is no longer frozen at activity creation. Edit
a not-yet-completed activity and you get a **Planned attendance** section: one
row per planned player with a status you can set — **Expected**, **Not coming**
or **Maybe** — plus an optional note (e.g. "texted, injured"). Activities
created with "Set attendance later" seed the section from the current team
roster so you can start a plan from scratch. The detail page's Expected
attendance panel now summarises who is away ("2 not coming · 1 maybe") and
links straight to **Edit plan**.

Marking a player "Not coming" early carries into the later attendance defaults
via the match-prep availability step. Planned rows are stored as
`record_type='expected'` and are written independently of recorded
(`actual`) attendance, so the attendance reports are unaffected. Reachable via
`PUT /activities/{id}` (a `planned_attendance` sub-resource) and
`GET /activities/{id}/planned-attendance`; gated on `tt_edit_activities`. No
migration — "Maybe" reuses the existing `excused` status.
