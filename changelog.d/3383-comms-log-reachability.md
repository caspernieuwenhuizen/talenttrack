# Message log: a row now says whether the recipient was reachable, beside why the send stopped (#3383)

Bump: patch

The send log was asking one column two questions. A parent with no email
address and no phone number on file produced *"no address"* at ten in the
morning and *"held until morning"* at ten at night — the same family, the
same missing detail, described by whichever rule happened to stop the
message first. Only one of those two answers is something a sender can act
on, and which one they got depended on the clock.

`tt_comms_log` gains a `reachable` fact beside `status`. Nothing about the
outcome vocabulary changes, and nothing about the order sends are decided in
changes either: a message deferred to the morning still never resolves a
channel. It just stops implying, by omission, that the family it was meant
for could have been reached. The message log renders both facts, and the
warning shown before a send and the row written after it now read the same
helper, so they cannot describe the same person differently.

Rows written before this arrived read *never established* and are left that
way — a send from last month would be judged against today's contact
details, and a log that fills its own gaps in stops being evidence.
