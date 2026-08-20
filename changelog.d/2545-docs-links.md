# Help links stay inside the app, and can open the screen they describe (#2545)

Bump: patch

Following a link inside Help & Docs used to hand you to the WordPress admin —
a dead end for a coach or a parent, most of whom cannot load it. Cross-references
between help topics now stay in the help viewer you are already reading in.

Help topics can also link straight to the screen they describe. Those links know
what you can reach: a link to a screen your academy has switched off, or that
your role cannot open, is shown as plain text rather than sending you to a
permission-denied page. Following one carries a back link, so you land on the
screen with one tap back to what you were reading.

The handful of topics that genuinely document WordPress admin pages still link
there, but only for administrators, and the link is marked as leaving
TalentTrack.

Also fixes cross-references between topics, which had been rendering as
unclickable text.
