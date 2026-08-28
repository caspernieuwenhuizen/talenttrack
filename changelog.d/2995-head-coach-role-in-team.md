# Team overview and the notification paths now name the same head coach (#2995)

Bump: patch

The head-of-development landing's team overview resolved a team's head
coach through the functional role **or** the legacy `role_in_team` string.
Every other head-coach resolution in the product — workflow task assignees
and both alert base classes, which have shared one implementation since
#2719 — uses the functional role alone.

So a team whose coach carried the legacy string without the matching
functional-role assignment was shown a head coach who would silently
receive none of that team's alerts or tasks. The overview said one thing
and the notification engine believed another, and the failure was invisible
in the direction that matters: nobody notices an alert that was never sent.

The fallback was redundant rather than protective. `role_in_team` and
`is_head_coach` are both written from the same functional-role key on every
create and update, so they cannot diverge through the application, and any
legacy row missing its `functional_role_id` has it filled in by the
self-healing backfill that runs on every activation. Dropping the clause
leaves one answer to "who is this team's head coach", asserted by a test.

One asymmetry is deliberate and stays: the overview still names a head
coach who has no WordPress account, where the notification paths skip them.
The widget answers who the coach is; the lookup answers who there is to
email.
