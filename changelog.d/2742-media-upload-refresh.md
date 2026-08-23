# An uploaded photo now appears straight away (#2742)

Bump: patch

Adding a photo or video from a player, team or activity page reported
"Added" and then showed nothing. The upload had worked and the file was
safely stored, but the grid below only picked it up when the page was
reloaded — so the natural reaction was to try again, and end up with the
same photo twice.

Uploads now appear at the top of the grid the moment they finish, complete
with their thumbnail, the Remove button and, on an activity, the control for
tagging the players in the shot. Adding several at once shows each one as it
lands, and the first upload into an empty gallery replaces the "No photos or
video yet" message rather than sitting behind it.
