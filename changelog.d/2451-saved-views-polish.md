# Saved views: rename them, update them, and clearer confirmations (#2451)

Bump: patch

Changing a saved view used to mean deleting it and saving a new one, which lost
its place in the list. Each saved view now carries a **…** button that opens a
small dialog where you can rename it, tick a box to replace its filters with
the ones you have set right now, or delete it — without losing anything else
about the view.

Saving a name you have already used on the same screen is now refused with a
message saying so, instead of quietly creating a second chip with the same
label that you cannot tell apart. The same name on a different screen, or the
same name used by a different person, is still fine.

The confirmation and error messages have moved from the browser's plain grey
pop-ups to the app's own dialog, so they are translated, readable to a screen
reader, and harder to miss. Deleting asks twice, because Delete sits next to
Save in the same dialog.

The single manage button replaces what would otherwise have been three small
icons per chip — at the size needed for comfortable tapping they did not fit
side by side on a phone, and a screen with five saved views would have carried
fifteen of them.
