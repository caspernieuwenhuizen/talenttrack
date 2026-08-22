# Alerts: TalentTrack now tells you when your data needs attention (#2631)

Bump: minor

A new Alerts engine surfaces conditions that are true right now and need
someone to act — an activity whose date has passed but is still marked as
planned, a completed activity with nobody's attendance recorded, an activity
next week with no coach assigned. Alerts appear in a banner at the top of
the dashboard and are counted by the notification bell alongside open tasks.

Alerts are deliberately not tasks. You never mark one as done: you fix the
thing it points at and it clears itself on the next background check. That
is the whole reason for a separate engine — modelling "this activity is still
planned" as a task would leave a stale task in someone's inbox every time a
coach fixed the activity in the activities list.

Alerts go to the people who can fix them: the coach assigned to the activity
and the team's head coach. Heads of Development do not receive one per team;
an aggregate view for that role comes later. Whether a recipient may see an
alert is re-checked on every sweep, so a coach who moves off a team stops
receiving that team's alerts without anyone having to remember.

Conditions are re-checked hourly in the background rather than while your
dashboard loads, so adding alert types can never slow down signing in. The
trade-off is that an alert can linger for up to an hour after you fix the
underlying thing. A fresh install runs one check on activation so the first
dashboard load shows a true picture.

This is the foundation only. Per-person and per-club settings for which
alerts you see and where, contextual chips on list and detail views, email
digests, and the rest of the alert catalogue all build on top of it.
