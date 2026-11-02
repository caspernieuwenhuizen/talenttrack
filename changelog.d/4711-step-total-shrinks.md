# Report start wizard: the step total no longer grows mid-run (#4711)

The report start wizard's counter used to read "Step 1 of 5" and then change to "Step 3 of 6" once "always the same subject" was picked. The subject step was counted as skipped before that choice had been made. It now counts until the choice says it is skipped, so the counter reads "of 6" from the first step and drops to "of 5" only after the user picks a subject chosen when the report is opened, or one that follows the viewer. The wizard framework's counter documents the same rule for other optional steps.
