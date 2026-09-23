# A REST route that actually creates a prospect (#4015)

A scout back from a scouting visit had no way to record the player they saw
over the API. `POST prospects/log` was the only prospect write route and it
creates no prospect: it opens a *Log a prospect* task for the caller and
answers with a task id. The record itself could only be made in the
WordPress front end, which put the first entry in the recruitment journey —
where does this player come from — out of reach of anything else.

`POST /prospects` now records one: names, date of birth, club, where you saw
them, your notes, the scouting visit they were found at, and the parent
contact block with its consent. It answers 201 with the prospect's id, the
prospect counts on its scouting visit, and the Head of Development gets the
same invite task the wizard opens. A likely duplicate comes back as 409 with
the candidate names and a `duplicate_override` flag, matching the wizard
rather than refusing outright, and re-posting with the override succeeds.

Underneath, the substance of the change: the field map and the duplicate
check existed twice, once in the wizard and once in the legacy workflow form,
and a third copy behind a route would have guaranteed all three drifted. They
had drifted already — only the wizard's copy passed the scouting visit, so a
prospect logged through the workflow task counted on nobody's visit. All
three now commit through one create service.

`prospects/log` stays as it is. External integrations and the parent
self-confirmation flow use it, and it is honest about opening a task.
