# Activity write routes check the team, not just the capability (#3616)

A coach could create, edit, archive, restore or permanently delete an activity belonging to any other team in the club. The write routes checked whether the caller may edit activities at all, and never whether the activity was theirs — while the activity *list* had always narrowed to the coach's own teams, which is what made the gap invisible.

All five single-record writes now refuse a team the caller does not hold, and moving an activity between teams requires standing on both the team it leaves and the team it joins. Anyone who works across the whole club — head of development, academy admin — is unaffected.
