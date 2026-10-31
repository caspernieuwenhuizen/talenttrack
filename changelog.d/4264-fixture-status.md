# Tournament programme: a played match that was never completed now says so (#4264)

Each match in a tournament's programme showed one chip: Completed, In
progress, or otherwise the opponent level. A match with its score typed in but
never completed therefore showed "Equal" where its status belonged, and its
minutes silently stayed out of the totals. Status and opponent level are now
two separate chips. A match with a score that nobody completed reads "Not
closed", next to the existing Complete match button; a match not yet played
reads "Scheduled". The minutes sidebar says how many played matches are not
completed yet, because their minutes are not counted. `GET tournaments/{id}`
returns a `status` per match (`scheduled`, `in_progress`, `result_entered`,
`completed`) from one shared service, so the screen and the API agree.
