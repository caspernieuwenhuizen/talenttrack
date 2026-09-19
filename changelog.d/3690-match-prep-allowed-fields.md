# Match prep's unknown-field error lists the fields it accepts (#3690)

When `PUT match-prep/{activity_id}` refuses a key it does not take, the `400 unknown_field` error now lists every key the route does accept in `details.allowed`, next to the rejected ones in `details.fields`. An integration that sent the wrong field name learns what to send instead, the same way the trial-input route already answers.
