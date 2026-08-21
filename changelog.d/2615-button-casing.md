# Buttons render in one consistent case (#2615)

Bump: patch

Buttons rendered UPPERCASE or sentence case depending on which HTML tag they
happened to use, not on any deliberate choice. A link-styled button came out
`CANCEL` while the real button beside it in the same row read `Save`.

The casing now lives in the label rather than the stylesheet, so every button
reads the same way wherever it appears — including the sign-in card, the 404 page
and the admin screens, which sat outside the rule that was papering over it.

A side effect worth having: sentence case is roughly 12% narrower than uppercase
before letter-spacing, so button rows have more room on a phone.
