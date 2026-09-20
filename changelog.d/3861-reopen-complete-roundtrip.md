# Reopening a played match is no longer permanent (#3861)

Reopening the activity behind a match that had been played was a one-way
door. The final whistle is the only thing that ever wrote `completed` on a
match activity, and it is unreachable once the match is over: "Complete
activity" routes such a match to the match-execution screen, which had no
control that completed anything, and the direct status action was refused
because completion belongs to the evaluation flow. The activity stayed
`planned` for good and dropped out of every count of completed activities,
including the monthly team report's header.

A played match already has the register that refusal exists to protect —
written at the final whistle and re-derived on every correction since — so
there are now two ways back and both are honest about it. The activity
detail offers **Mark completed** beside Cancel, as it does on a wizard-off
install, and the match screen offers **Mark activity completed** in its
review panel while the activity is not completed. Finalizing a match also
asserts the status now instead of assuming it, so re-finalizing repairs an
activity that drifted rather than reporting nothing to do. Training
activities and matches never run through the live screen are unchanged:
completion stays the end of their flow.
