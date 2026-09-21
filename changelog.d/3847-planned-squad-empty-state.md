# An activity with no squad says so, and offers the way in (#3847)

The Expected attendance card on the activity detail page rendered only when a
planned squad already existed. An activity without one showed no card at all —
no player names, no prompt, and no link offering to build a squad, because that
card is the only place on the page that leads into the plan editor. A coach
opening a training the evening before saw date, time, type and notes, and fell
back to paper.

The card now renders an empty state — *"No squad picked yet."* — with a
**Pick the squad →** link into the plan editor for anyone who may edit
activities, and without it for anyone who may not. A completed activity is
unchanged: the attendance panel below it is the answer there, so there is no
stale offer to pick a squad for a match already played. A cancelled one states
the empty squad without the link.

The card stays read-only; the edit form remains the single write path for the
plan.
