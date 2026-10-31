# Injury recovery reminders stop when the player is archived (#4436)

A player who has left the academy no longer keeps the physio and head coach
busy with an injury recovery check. Archiving the player, or moving them to
the recycle bin, retires their open reminder in the same request, and the
daily sweep skips archived players. Restoring the player brings the reminder
back at once while one of their records still needs a decision. The injury
records themselves are unchanged and stay readable as before.
