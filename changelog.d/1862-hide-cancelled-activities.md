# Cancelled activities hidden from the list by default (#1862)

Cancelled activities no longer clutter the activities list — they're hidden by
default so the schedule reads as what's actually happening. A new "Show
cancelled" filter brings them back when you need the audit trail; shown that
way they're dimmed and struck through with a Cancelled pill, in whichever date
bucket they fall. The default-hide is applied in the query (it carries through
the URL), so a shared link reflects the same view.
