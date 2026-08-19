# Top bar tidied, and the keyboard hint now names a key you have (#2563)

Bump: patch

Three fixes to the top bar in the app shell layout.

Its contents had drifted to the far left, leaving most of the bar empty — the
academy name moved into the sidebar and nothing took its place. Notifications
and help now sit on the right where they belong, and the search box is centred
in the bar rather than tucked beside them. The search box is wider again, and
the navigation column gains a few pixels too.

The keyboard hint on the search box read **⌘K** for everyone. That is the Mac
Command key, so on Windows it named a key that is not on the keyboard. It now
reads **Ctrl K** on Windows and Linux and **⌘K** on a Mac. The shortcut itself
always worked on both — only the label was wrong.
