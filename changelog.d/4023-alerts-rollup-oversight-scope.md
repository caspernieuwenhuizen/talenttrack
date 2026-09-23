# Alerts: the per-team summary now reaches the Head of Development (#4023)

The alerts overview — the per-team summary at the top of the alerts list, and
`GET /alerts/rollup` behind it — came back empty for a Head of Development.
The teams it covers were resolved from the `tt_edit_settings` capability,
which the role does not hold, and the fall-back path only returns teams a
user is individually assigned to. A globally scoped user is assigned to none,
so the surface that exists precisely because a Head of Development receives
no alerts of their own answered with nothing.

The question is now asked of the access matrix instead: anyone who can read
every team's activities sees the whole academy, which covers Heads of
Development, academy admins, scouts and read-only observers. A coach assigned
to particular teams still sees exactly those, and there is still no request
parameter that can widen the scope.
