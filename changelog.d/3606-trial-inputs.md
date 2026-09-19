# Trial inputs: a save keeps what it wasn't sent, and an empty assessment can't be submitted (#3606, #3612)

Saving a trial assessment over the API rewrote the whole input:

- a rating-only save erased the notes;
- submitting on its own erased both and submitted an empty assessment;
- every save, including from the trial case screen, cleared the category ratings.

Fields the route didn't recognise were dropped behind a "saved" answer. Now:

- **Only the fields sent are saved.**
- **Unrecognised fields are refused by name.**
- **A rating off the academy's scale is refused.**
- **An assessment with neither a rating nor notes can't be submitted.**
- **The response shows what was stored.**
