# Help topics render through the same engine as the courses (#2663)

Bump: patch

The plugin had two markdown renderers: one for help topics, one for the course
reader. They have been folded into one, so a fix or an addition on either
surface now lands on both.

For a reader, help topics gain what the course reader already had. **Tables in
a topic render as tables** instead of rows of pipe characters, and a wide one
scrolls inside its own box rather than pushing the page sideways on a phone. A
bullet whose text wraps across two lines now stays one bullet instead of
breaking into a stray paragraph.

Topic styling moved off hardcoded greys onto the design tokens, so the help
reader inside the app finally picks up the club's colours instead of wearing
WordPress's. The wp-admin Help & Docs page keeps the look it had.
