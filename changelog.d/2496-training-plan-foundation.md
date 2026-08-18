# Groundwork: training plans get their own record (#2496)

Bump: minor

Adds the foundation for the Training module: a training plan, its ordered
blocks, the methodology principles it covers, and a record of each time the
plan is actually run against a training in the calendar. Coaches, heads of
development and academy admins get a new "training plan" permission, and the
whole thing is reachable through the API before any screen exists.

Nothing appears on screen yet. The design that matters for later: a plan is a
reusable template you can keep editing, while each execution takes a permanent
snapshot of what it contained on the day. Adjusting a plan in September can
never change what a session in August says it was — which is what makes the
per-player training history trustworthy when it arrives.
