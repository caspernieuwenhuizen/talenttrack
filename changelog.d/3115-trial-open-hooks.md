# A trial opened from the UI now shows on the player's timeline (#3115)

Bump: patch

Opening a trial case through the Trials screen wrote no journey event, so the
trial was missing from the player's timeline. Opening the identical case through
the API did write one. The screen a coach actually uses was the broken half.

Creating the player inline on the same form had the matching problem: it wrote
the row directly instead of going through the normal player create, so the
player's own arrival never reached the timeline either — for exactly the players
whose journey begins with a trial, and who therefore had no timeline at all.

Both now go through the same path as everything else. Opening a case writes
**Trial started**; creating the player inline writes **Joined the academy** and
applies everything else a normal player create does — custom-field defaults, the
consent stamp, the parent link. One consequence of that: an academy that has made
a custom player field required can no longer use the three-field shortcut, and
the form now says which field is missing. Add the player from the Players screen
first, then pick them on the trial form.
