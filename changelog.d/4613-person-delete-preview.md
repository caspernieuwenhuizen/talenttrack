# Permanent person delete: the preview counts what the delete removes (#4613)

The impact preview before a permanent person delete counted team roles, staff records, mentorships and invitations by person id alone, while the delete itself only touches the current club's rows. On an install with more than one club the preview could promise removals the delete never made. Every count in the preview is now limited to the current club, and the preview lists the staff evaluation ratings the delete removes, which it did not show before.
