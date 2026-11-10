# My settings opens on four groups, each on its own page (#4969)

Bump: minor

My settings no longer stacks up to eight cards on one long page. It opens on an overview of four groups (Account, Messages, Appearance and Privacy), each with a one-line summary of how it stands, such as "9 of 12 on" or "Your parent sees everything". Tapping a group opens a page with only that group's settings. From 1024px the list stays on the left and the open group sits on the right. A group the user has nothing in doesn't show. The profile and password forms gain Cancel next to Save, which returns to the overview. After a save the user stays on the group page. Two-step login now shows in Account, with its status and a button to the two-factor screen. The summaries come from one service, `MySettingsGroups`, which the group pages render from. My settings also gets its own help topic.
