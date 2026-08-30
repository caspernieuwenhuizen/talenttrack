# A player created in the new-player wizard now arrives on their own timeline (#3189)

A player created through the new-player wizard had no *"Joined the academy"*
entry on their journey. The wizard's review step wrote the `tt_players` row
itself and announced nothing, so the journey subscriber never heard about the
creation and the player's story began at whatever happened to them next. It
was most visible on the trial path, which since the previous release writes
*"Trial started"* — leaving a trial with nothing before it.

The step no longer writes the row. It creates the player through the same
canonical create every other screen uses, which is also where the licence cap,
the custom-field validation, the consent stamp and the demo tagging live — the
wizard had grown its own copies of the last two, and both are gone. Existing
players created in the wizard stay missing the entry unless it is backfilled.
