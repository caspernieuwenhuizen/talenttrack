# Context-aware help now covers eight screens it was silently skipping (#3022)

Bump: patch

The help drawer's promise is that every screen knows which topic explains
it. Eight screens were exempt from that promise without anyone deciding
they should be — the alert settings and alert policy screens, the
invitation acceptance page, the two match share links, both password-reset
screens and the second-factor prompt. Each was reachable, none was checked.

Alert settings and alert policy now open the Alerts topic. The other six
are pre-authentication screens with no help button on the page at all, and
are recorded as deliberately topic-less with the reason why, so nobody has
to rediscover the question.

Underneath, the three checks that ask "which screens can a visitor reach?"
now share one answer instead of computing three different ones.
