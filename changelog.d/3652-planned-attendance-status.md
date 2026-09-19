# Planned attendance over REST no longer reads as a taken register (#3652)

`GET /activities/{id}/planned-attendance` returned a `status` field holding
`Present` / `Absent` / `Excused` on every planned row. That was the plan's
internal storage encoding, not a recorded mark, so an activity nobody had
registered yet came back with the whole squad "Present" — the opposite of
what the activity list said about the same match. The field is removed;
`plan_status` (`expected` / `not_coming` / `maybe`) is the only status the
route returns, alongside `player_id`, `is_guest`, `name` and `notes`. No
screen changes: the activity page already mapped the value correctly, and
nothing in the plugin read the removed field.
