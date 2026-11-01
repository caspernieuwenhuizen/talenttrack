# Permanent person delete removes their course enrolments and records (#4618)

Permanently deleting a person left their knowledge-library course records behind: enrolments, lesson progress, quiz attempts and assignment submissions, and a submission they had reviewed for someone else kept pointing at a person who no longer existed. The delete now removes the person's enrolments and everything under them, in the same transaction and only in the current club, and clears the reviewer on submissions they reviewed for others, which stay. The impact preview lists these rows before you confirm.
