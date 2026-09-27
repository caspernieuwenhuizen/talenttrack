# Monthly report: one Summary / Details choice per section (#4133)

Bump: minor

Every team monthly report section with two levels of detail now offers the
same choice, Summary or Details: tests, attendance, minutes share, matches and
the new evaluations section. Summary is the compact version (the stat strip
per test; the squad average, who is below 70% and the absences by kind; the
median, the target and who is under it; the record and the results). Details
adds the player tables. The tests section's four-way "how much" becomes Summary
or Details with a "with change" tick box, and the match switches sit under
Details. Attendance, minutes share and matches start on Details and tests on
Summary, so a report nobody touches prints as before. Saved views, snapshots
and schedules that stored the old tests option are mapped on read, and the
REST API still accepts it for one release. A layout that cannot print a
section's Details shows it greyed out with the reason, and the page estimate
counts the level each section prints.
