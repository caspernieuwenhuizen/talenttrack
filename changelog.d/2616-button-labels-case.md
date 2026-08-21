# One create verb, one case, across every button (#2616)

Bump: patch

Buttons that create something now all read **Add …**. The same action used to be
spelled four ways depending on which screen you were on — `+ New season`,
`New category`, `+ Add option`, `Create case` — and two labels existed in both
Title Case and sentence case at once, so `Add Goal` and `Add goal` were different
buttons for the same thing.

The leading `+` is gone from button labels. Page headers already draw their own
icon, so the glyph was duplicating an affordance the component provides — and on a
phone, where the label collapses to the icon, the `+` was invisible anyway.

A few labels also lost words they were repeating from the screen around them:
`Start 30-day Pro trial` is now `Start trial`, `Share via WhatsApp` is `Share`,
`Run Report` is `Run`.
