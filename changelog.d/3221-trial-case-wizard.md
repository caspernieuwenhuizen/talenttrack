# Opening a trial case is now a guided flow (#3221)

Bump: minor

Opening a trial case meant one long form: player, track, dates, three staff
slots and notes, all at once. It is now three short steps — who is trialling,
which track and for how long, and who is watching — with a summary of what is
about to be created before you finish.

Two things this makes better beyond the shape. **Nothing is written until you
finish**, so backing out halfway no longer risks leaving a half-made player
behind. And **the summary step says what will happen**: the case opens, the
player's status becomes Trial, and the trial goes on their journey from day one.

The single-page form is still there for anyone who prefers it, and academies
with the guided flows switched off keep it as the default. Both now go through
exactly the same code to open the case, so they cannot drift apart — which is
what went wrong twice before, when one path forgot to record the player's
arrival and another forgot to put the trial on the timeline.
