# Team rating averages and Coach activity are academy-wide reports (#4384)

Both reports list every team in the academy, yet an assistant coach could open
them from the reports launcher. They are now treated like the other
academy-wide reports: staff scoped to their own teams are not offered the
tiles, and opening either report by its address shows the "not authorized"
notice with the breadcrumb chain. Roles that read reports academy-wide (head
of development, academy admin, scout and read-only observer) see both reports
as before.
