# Marking a lesson read no longer succeeds silently at doing nothing (#3852)

`PATCH /courses/{slug}/progress/{lesson}` accepts two body fields, `read` and `tool_state`, and declared neither — the route's discovery response listed no arguments at all, so the only way to learn the field names was to read the handler. A body that used any other name passed every guard, matched neither branch and fell through to the response builder, which answered `200 {"success": true}` with the unchanged progress record embedded. A staff member who sent the obvious guess, `{"completed": true}`, got a success, and their hour of study was not recorded: the enrolment sat at 0 of 11 past its deadline while the learning report kept chasing a lesson they had already read.

The route now declares both fields with their types and descriptions, so discovery answers the question. A body this route cannot act on is refused with a 400 naming the fields it does take, and a body that mixes a recognised field with an unrecognised one names the one that was wrong.

The refusal also comes before anything is written. Marking the enrolment started used to run ahead of both branches, so a call that recorded nothing still stamped the start date and left the course reading "begun, nothing read".
