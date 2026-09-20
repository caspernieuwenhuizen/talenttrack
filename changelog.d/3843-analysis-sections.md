# A match analysis no longer answers "saved" over sections it threw away (#3843)

`PUT /activities/{id}/analysis` stored the summary and silently dropped the
section ratings and notes whenever `sections` arrived as a list of objects —
the list index was taken for the section key, the writer refused it, and the
refusal was discarded before it could reach the caller. A rating that was not
one of `went_well` / `mixed` / `needs_work` was nulled the same way.

Both shapes work now: an object keyed by section key, and a list whose entries
name their own `key` (or `section_key`). A section the route cannot store is
refused with `400 invalid_field`, naming the field and listing the section keys
and rating values it accepts, and nothing is written — one unusable section
refuses the whole document rather than storing the half the server understood.
Both PUT routes also declare their `args`, so a misspelled top-level key is
refused by name instead of ignored.
