# Alerts clear the moment you fix the thing (#2731)

Bump: minor

An alert used to linger for up to an hour after you had dealt with it. You
marked the session completed, recorded the attendance, assigned the head
coach — and the banner, the bell and the alerts list all carried on saying
otherwise until a background job next ran. The engine was right and the
screen was stale, but from where you were sitting the product simply looked
wrong about your own data.

Alerts are now re-checked at the moment the record changes. Fix the thing
and the next screen you land on no longer mentions it. That holds for every
alert TalentTrack ships: past activity still planned, attendance
unrecorded, no coach assigned, player not evaluated, evaluation window
closing, evaluation never shared, goal past its target date, PDP cycle with
no conversation, player turning 18, parent never activated, staff
certificate expiring, no measurement this season, player without a team,
team without a head coach, and stale invitations.

The re-check runs after the page has been sent to you, so saving is no
slower than it was — including on the attendance grid, which can touch a
whole squad in one go. A save like that counts as one re-check, not forty.
Very large operations, such as importing players or rolling over a season,
deliberately leave the recount to the hourly job rather than performing
hundreds of them while you wait.

The hourly background check has not gone away and still matters: it is the
only thing that notices a condition that became true because time passed
rather than because somebody saved something — a certificate reaching its
expiry date, an invitation nobody answered, a session slipping past the
point where its attendance is overdue.

Alongside this, several parts of the app that changed records quietly now
announce it, which anything extending TalentTrack can listen for:
`tt_activity_saved`, `tt_activity_attendance_changed`,
`tt_measurement_result_saved`, `tt_staff_certification_saved` and
`tt_pdp_conversation_saved`. Evaluations created through the evaluation
wizard now fire `tt_evaluation_saved` as well — they never did, so
automatic follow-up tasks configured against that event were only ever
created for evaluations saved the other way. They now fire for both.
