# The setup wizard asks what your academy sends (#3113)

Bump: minor

A new step, **What we send**, between adding staff and creating the dashboard
page. It lists what TalentTrack can send, grouped and in plain language rather
than by template name, and the academy ticks what it wants.

This is what makes #3111's "a new install sends nothing" safe rather than merely
quiet. Without it the outcome is not conservative — it is a club that never tells
anybody anything, finds out when a cancelled training goes unannounced, and
concludes the product is broken.

Nothing is pre-ticked, because the honest framing is that you are choosing what
to switch on. The urgent group — training cancelled, schedule change,
safeguarding broadcast — is marked *Recommended* in a sentence; a recommendation
is not a tick made on somebody's behalf.

Skipping is allowed and says what it means: **no messages will be sent, not even
a cancelled training.** Not "you can change this later", which reads as "it is
fine either way". The Done screen repeats the count, or the warning when nothing
is on.

The step reuses the Messages settings screen's copy (#3112) and writes through
the same domain writer, so there is no second place the decision lives. Staff
invitations are unaffected whatever is chosen — the invitation email is account
plumbing and sits outside the switch (#3110).

Recovery for a skipped step is filed as #3139.
