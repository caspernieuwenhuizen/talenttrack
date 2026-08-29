# Mark each match-analysis note good or bad (#3091)

Bump: minor

A phase rated *Wisselend* with four bullets under it read, six weeks later
and to whoever the share link went to, as four undifferentiated sentences.
The coach knew which two were the good half while typing and the surface
threw it away.

Every note — phase bullet and player note — now carries an optional **+** or
**−**. Leaving it unmarked stays the normal case: an observation is not a
verdict, and nothing forces a grade onto one. The signs are deliberately not
the ▲ ● ▼ of the phase rating and the player marker, which grade a whole
phase or a whole match; two granularities that looked identical in one card
would be worse than no mark at all.

Player notes become two rows rather than one, each with its own mark, for
the case the old shape could not hold: a player who did one thing well and
one thing badly in the same match. Two is the cap — an open-ended list on a
fourteen-player squad is twenty-eight text boxes on a phone. The three-way
marker stays what it was; the notes are the evidence under it. A player with
two notes still gets one timeline entry for the match, with both notes on it.

The mark is a column, not a `+ ` typed into the sentence. That is what lets
the match-analysis trends count it, and it means a bullet a coach opens with
a hyphen stays a hyphen instead of being read as a judgement.

Existing analyses keep every word. Player notes inherit their marker's
verdict — stood out becomes a plus, below par a minus — because with one
note per player the marker *was* the verdict on that sentence; phase bullets
come back unmarked, because nothing in the old data says otherwise. The
previous text columns are left in place, unwritten, as a rollback net for
one release.

Works with no JavaScript: the control is a real radio group, like the phase
rating it sits under.
