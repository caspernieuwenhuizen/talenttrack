# Evaluations saved through the API keep the training or match they came from (#3582)

`POST /evaluations` and `PUT /evaluations/{id}` accepted an `activity_id`,
answered 200 and stored nothing, so only the evaluation wizard could tie an
evaluation to its session. Both routes now store the link. On create, the
evaluation type is taken from the activity when none is given, as the wizard
does. An update sets the link, keeps it when the key is absent, and clears
it when `activity_id` is 0. An activity that doesn't exist, or that belongs
to a team the coach doesn't coach, is refused with `400 bad_activity` and
nothing is written. An update now also advances `updated_at`.
