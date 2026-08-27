# Attendance statuses stored consistently (#2909)

Attendance statuses are now stored consistently. Different parts of the app
wrote "Present" and "present" into the same column, which left some checks
quietly failing and some screens showing an attendance status without its
colour. Existing records are normalised automatically on update; a status your
academy added itself is left exactly as you named it.

Also fixed the VCT training-load report attributing a session's full load to
players who were only pencilled in for it rather than those who actually
attended. Workload figures for planned-but-unattended sessions were too high.
