# Match analysis share link: see whether anyone opened it (#3096)

Bump: minor

A coach sends a match analysis to the staff group and then hears nothing.
The share block now carries one more line — *Seen by 4 people · last opened
2 days ago* — so "did this land?" has an answer that is not a guess.

It counts browsers rather than names: a share page has no login, so there
is nothing to put a name to, and the wording says so. There is no per-visit
log and no way to ask who opened it; a document shared between colleagues
should not double as a record of who looked at it. Link previews from
WhatsApp and Slack are ignored, and the page is no longer cacheable — which
is what makes the count reliable, and which a page naming children should
not have been anyway.

A returning reader is recognised by a first-party cookie holding a random
number and nothing else — strictly functional, no consent banner. Where
cookies are refused, a one-way salted fingerprint of the connection stands
in; neither the address nor the browser version is stored. Those records are
deleted after 90 days, matching the alert retention window and for the same
reason, and the totals survive the deletion so the count never walks
backwards.

The store (`tt_share_views`) is shared: match prep and the team blueprint
mint their links from the identical construction and are a call site each
away from the same line.
