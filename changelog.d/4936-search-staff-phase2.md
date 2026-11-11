# Search finds evaluations, goals and notes for staff, with richer player and setting rows (#4936)

Bump: minor

Staff now find a player's development record from the search box. One
*Evaluations & goals* group, for everyone, lists evaluations (by player,
evaluation type in your own language, opponent, date and the coach's notes)
and goals (by player, title, description and due date). A family finds its
own children's there, staff the players they read. A *Notes* group lists the
notes on a player's file. Each record is narrowed in the query to what the
reader may open, and confirmed with the record's own check. The coach's
notes are only matched, and only shown as an excerpt, where the reader may
read them, so the read-only observer finds an evaluation by player, type and
date only and gets no *Notes* group. A staff-only note is found only by
someone who may see it. Parents and players get exactly the records they got
before, and a staff member who is also a parent finds their own child's
records once, by the family rules. Player rows now show the shirt number and
the primary position. Setting rows show their full path, such as
*Configuration › Academy › Appearance*. An administrator who may switch
modules on also finds the switched-off modules, marked *Off*, with a *Turn
on* action that opens the module's switch. On `GET /search`, `evaluation`
and `goal` now also answer staff, the new kind `player_note` holds player
notes, and items gain `shirt_number`, `position`, `excerpt`, `path` and
`disabled`. All of it is additive to v1.
