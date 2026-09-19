# One open trial per player (#3577)

Nothing stopped a second trial case being opened for a player whose first
was still open. The decision, the staff inputs and the journey entry could
end up split across two records. A player now has one open trial at a time.
A new case for a player with an open or extended case is refused, from the
form, the guided flow and the REST API alike, and the message names the open
case, with a link from the form. A longer trial is an extension of the open
case. Once it's decided or archived, a new case can be opened.

Opening a case over the REST API now behaves like the form: it refuses a
player from another club, sets the player's status to Trial, and returns 409
`trial_case_already_open` with the open case's id. The trial case list and
detail responses now include the player's name.
