# Undo the last change on an autosaving surface (#3005)

Match preparation saves as you work, which means a mis-tap is stored the
moment it settles. An **Undo** button now sits next to the save state and
takes back the last committed change — the slot you just filled, the half
length you just typed, the focus note you just wrote.

The undo is itself saved, so it survives a reload rather than being a
screen-only revert. It is one step, not a history: once used, the offer
retires until the next change. It appears only on a settled record, so it
can never race a save still in the air, and a failed undo says so and
leaves the screen showing the value the server still holds.

Built into the shared `TT.Autosave` component rather than into the
surface, so the writing surfaces moving to autosave later in epic #2881
inherit it. Captain and set-piece picks write on their own endpoint and
are outside what one payload snapshot describes, so they retire the offer
instead of letting Undo revert something older than the coach's last
action.
