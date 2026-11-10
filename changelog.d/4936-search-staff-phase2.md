# Search finds evaluations, goals and notes for staff, with richer player and setting rows (#4936)

Bump: minor

Staff now find a player's development record from the search box. A new
*Evaluations & goals* group lists evaluations (by player, evaluation type in
your own language, opponent, date and the coach's notes) and goals (by
player, title, description and due date). A *Notes* group lists the notes on
a player's file. Each provider narrows in SQL to the players the reader may
read the record for, and confirms each row with the record's own check. The
coach's notes are only matched, and only shown as an excerpt, where the
reader may read them, so the read-only observer finds an evaluation by player,
type and date only and gets no *Notes* group. Parents and players get exactly
what they got before. Player rows now show the shirt number and the primary
position. Setting rows show their full path, such as *Configuration › Academy ›
Appearance*. An administrator who may switch modules on also finds the
switched-off modules, marked *Off*, with a *Turn on* action that opens the
module's switch. `GET /search` gains the kinds `staff_evaluation`,
`staff_goal` and `note`, and the item fields `shirt_number`, `position`,
`excerpt`, `path` and `disabled`. All of them are additive to v1.
