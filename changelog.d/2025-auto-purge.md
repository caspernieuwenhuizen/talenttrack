# Recycle bin: 30-day automatic purge (#2025)

Bump: minor

Adds the unattended purge that empties the recycle bin after the retention
window. A daily sweep finds every record trashed longer than the club's
retention window (default 30 days) and permanently deletes it — no one has to
remember to empty the bin.

The sweep runs through the same fail-closed deletion path as the manual
"Delete now": player and person records are erased across every linked table
via their cascade services, so a minor's child PII is never stranded. It runs
on the workflow engine's existing background schedule (not a separate cron),
self-throttles to once per day, and is scoped per academy so a record is only
ever purged within its own tenant. Because the job runs with no one logged in,
its audit entries are attributed to the system, so the audit log never implies
a person pressed delete.

Records the purge cannot delete — because other records still reference them —
are skipped, left safely in the bin, and surfaced in the recycle-bin view with
a banner ("N records couldn't be auto-deleted — still referenced"). A few
record types (measurement definitions and trial tracks) are templates that can
never auto-purge by design; the bin now flags those so the 30-day countdown is
never read as "these vanish at 30 days".
