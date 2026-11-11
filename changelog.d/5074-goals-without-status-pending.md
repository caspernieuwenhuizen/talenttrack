# Goals without a status are stored as pending (#5074)

Migration 0332 stores a goal whose status is empty, missing or "open" as pending, the status a new goal starts in. Such a goal now counts as open everywhere the one open-goal rule is asked, as it already showed on the goal screens. Every other status is left as it is, and a second run changes nothing.
