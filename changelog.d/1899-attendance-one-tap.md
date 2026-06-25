# Evaluation wizard: one-tap "Everyone was here" on the attendance step (#1899)

Bump: patch

The attendance step of the new-evaluation wizard gains a prominent **"Everyone was here - continue"** button at the top: for the common case where the whole squad was present, it marks the roster present and advances straight to rating in a single tap, instead of the coach scanning the roster and hitting Next. Mark any absences on the cards first if needed, then use it (or the normal Next). Attendance is still written exactly as before (real `tt_attendance` rows, present-by-default), and the standalone mark-attendance entry point is unchanged — this only adds a faster path through the existing screen. Follow-up to the evaluation-capture UX work (#1642); the deeper picker/attendance step-merge was deliberately scoped to this low-risk shortcut.
