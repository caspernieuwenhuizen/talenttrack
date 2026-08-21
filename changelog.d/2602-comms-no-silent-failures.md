# Messaging never fails silently any more (#2602)

Bump: patch

A message the academy sends to a family could previously disappear without a
trace. A send whose recipient list resolved to nobody — a team with no linked
parents, say — looked exactly like a successful one, and a message naming a
template that was not registered left no record anywhere. Both now write to the
message log like any other outcome, so "was this family told?" always has an
answer.

The daily reminder run records whether each of its four checks actually ran and
whether it errored. A check that has been failing for months used to be
indistinguishable from one that simply had nothing to send.

Messaging that a person triggers can now report back per recipient — who it
reached, who has opted out, who has no usable contact details — instead of a
flat "sent". A new dry-run pass evaluates opted-out recipients, quiet hours,
sending limits and reachability *before* anything is sent, so a screen can warn
first. The surfaces that use both land with the message log and the send flows.
