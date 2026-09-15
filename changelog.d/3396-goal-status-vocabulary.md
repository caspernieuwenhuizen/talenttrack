# A goal waiting on your coach no longer looks like one you should be working on (#3396)

The goals board sorted a player's goals into **Actief / Behaald / Gemist** by
matching status values the product does not actually store. Four of the six
things it looked for could never occur, and the two real statuses that fell
through the gaps — a goal the player proposed that is still awaiting approval,
and a goal the coach has paused — were indistinguishable from live work.

Both now carry their own marker in the Actief column: amber for awaiting
approval, grey for on hold. The board reads the stored values directly, so
renaming a status or priority label in the lookups admin no longer changes which
column a goal lands in or which colour it takes.

The empty board also gained the guided explainer the other player screens have,
and opening it now runs one query for the conversation counts instead of one per
goal.
