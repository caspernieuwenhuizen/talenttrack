# Messages that reach nobody now say whose they were, and a new alert names the player (#3576)

When a trial welcome, a published plan or a cancelled-training message
resolved to nobody, the error log recorded a warning that named only the
template. An admin couldn't tell which family was never told. Generating demo
data wrote dozens of these in a couple of seconds. Three changes:

- **The warning names its subject.** It now carries the player and the record the message was about (the trial case, the plan, the training), and so does the communication log row.
- **A template the club switched off is no longer reported** as having reached nobody.
- **Demo generation writes no warnings.** While the demo generator runs, nothing it creates sends a message, so the error log stays readable. Sends by other users during a run are unaffected.

New alert, **Player with no guardian contact**: an active player, or one on an
open trial, with no parent account and no guardian email or phone. It goes to
the team's head coach and whoever manages parent accounts, and clears once
somebody at home can be reached. A player whose parent has an outstanding
invitation is left to the existing "Parent invited but never activated" alert.
Most demo players have no guardian by design, so expect a number of these on
a demo install.
