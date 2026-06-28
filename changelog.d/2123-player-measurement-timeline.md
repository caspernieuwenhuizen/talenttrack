# Player profile: Measurements signal in the At-a-glance panel (#2123)

Bump: minor

A player's measurement standing now reads as part of their journey
narrative, not just a separate tab. The profile's **At a glance** panel
gains a **Measurements** signal beside Avg rating, Attendance and Goals:
the number of tests the player currently has a value for, with a hint of
how many sit below their age-group target band (or "on target" when none
do). It links straight to the Measurements tab for the full per-test
timeline. The signal is gated on `measurements:read`, so it never leaks a
player's test standing to a role that can't open the underlying results.
