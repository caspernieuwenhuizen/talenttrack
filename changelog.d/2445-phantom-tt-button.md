# Buttons that rendered as grey native controls are now properly styled (#2445)

Bump: patch

A group of buttons across the app rendered as raw browser-default controls —
grey, square, system font — instead of TalentTrack buttons. The most visible
were on the evaluation wizard's Attendance step ("Everyone was here —
continue" and "Mark all present"), but the same fault affected the rate-confirm
Yes / Skip fork, the trials list and its tracks and letter-template editors,
the trial parent-meeting actions, the tournaments squad step, the wizards admin
page, the activities reopen-rating button, and the MFA and desktop-only
prompts.

The cause was a class name that never existed: `tt-button` and its
`-primary` / `-secondary` / `-small` variants have no styling defined
anywhere, so every element carrying one fell back to the browser default. All
32 occurrences now use the real button system, and a CI check fails any future
pull request that reintroduces the phantom name — it kept coming back because
nothing ever complained about it.

The wizard's own Cancel / Back / Next / Save-as-draft bar is unchanged: it was
already fully styled by its own rules, so it simply drops the dead class
rather than gaining a new one.
