# Match execution: sideline robustness polish (#2270)

Bump: patch

Small reliability fixes on the live-match screen: a failed goal-undo rolls
the count back instead of drifting, the late-event forms cannot be
double-submitted, the timer stops the instant you finalize, and the header
meta line wraps rather than clipping the team names on a very narrow phone.
