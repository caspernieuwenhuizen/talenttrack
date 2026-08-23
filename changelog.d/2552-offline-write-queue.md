# The sideline view keeps working when the signal drops (#2552)

Bump: minor

Pitches are where signal is worst, and until now a coach who lost it mid-session
lost that session: block timings and observations typed into a form that then
failed to save. That is the exact failure that sends people back to paper.

Now those writes are kept on the phone and sent as soon as there is a connection
again. A line at the top of the sideline view says how many are waiting —
*"2 wijzigingen wachten op bereik"* — and it survives locking the phone,
switching apps and reloading the page.

**Nothing is recorded twice.** If a change reaches the server but the reply is
lost on the way back, the phone tries again, and the second attempt lands on the
same record instead of creating a duplicate. That matters more than it sounds:
these numbers become each player's training minutes, so a change applied twice
would put a wrong figure on a child's development record.

A change that still cannot be saved after reconnecting — because you were away
long enough for your login to expire — stays queued rather than being discarded.
Reload the page and it goes.

**Opening the page still needs signal.** What this protects is a session already
underway; starting one from nothing with no connection is a separate thing and
is not covered.
