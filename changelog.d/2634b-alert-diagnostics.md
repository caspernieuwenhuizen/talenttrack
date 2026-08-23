# Alerts: see whether the engine is running, and which alerts people ignore (#2634)

Bump: patch

The **Alert policy** screen now opens with an engine-health panel.

It answers the question nothing else can: a background job that has stopped
produces exactly the same screens as an academy with nothing wrong — empty
ones. If alerts have not been checked recently, scheduled tasks are not
running on the site and every alert screen is frozen at whenever they last
did.

Underneath it, a table per alert: how many are open, how many were cleared,
and what share people simply dismissed.

That last figure is the point. An alert most people dismiss is not informing
anyone — it is teaching them to dismiss alerts, and the useful ones go with
it. Anything above about 60%, over enough occurrences to mean something, is
flagged for review. Nothing is switched off automatically: whether an alert
earns its place is a judgement about your academy, not a calculation.

Also available through the API at `/alerts/diagnostics` for anyone wanting to
monitor the engine externally.
