# Team monthly report: mailed as a PDF on the 1st (#3462)

Bump: minor

**Schedule monthly** on the team monthly report sets the report up to arrive by
email as a PDF on the 1st of every month. Each one covers the month that just
ended, and the file is named after the team and the month
(`JO13-1-2026-09.pdf`). The schedule keeps its own copy of the report, so
changing or deleting a saved view later does not change what it sends.

The report names minors and goes out unattended, so a schedule stops rather
than sends when its team has been archived, or when the person who set it up
can no longer see that team's reports. The scheduled reports screen now shows
why a schedule's last run did not send.

Existing KPI schedules keep working as before.

Migration 0266 adds the report kind, the composition and the last error to
scheduled reports.
