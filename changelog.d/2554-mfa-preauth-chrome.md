# The two-factor screen no longer wears the whole app around it (#2554)

**Fixed:** immediately after signing in, before the second factor had been
entered, the two-factor screen rendered inside the full application — navigation
rail with every module, global search, notification bell, persona menu, a link
into the WordPress admin, and a breadcrumb trail back to the dashboard, with the
code field sitting underneath all of it. That reads as "you're in, now also type
a code", which is the opposite of what the screen means.

Both challenge screens — the code prompt and the enrollment wizard a user is
held at when two-factor is required but not yet set up — now render on the same
centred, branded card as the sign-in and password-reset screens: academy crest,
academy name, the challenge, nothing else. A *Log out* link on the card is the
way out for someone who can't complete it, which the navigation used to provide
by accident.

Enrollment started deliberately from the Account page is unaffected and keeps its
normal in-app wizard chrome.
