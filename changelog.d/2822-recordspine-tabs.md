# Tab strips and grid switchers stop drifting apart (#2822)

Bump: patch

Five surfaces had grown their own tab strip rather than using the shared one,
all of them rendering under the 48px tap-target floor — which is why the
trial-case tabs, the Custom CSS panes and the Functional roles sections each
looked and behaved slightly differently, and were fiddly to hit on a phone.

The three that really are tabs within one subject — trial case, Custom CSS and
Functional roles — now come from the shared record spine, so they get its
sizing, keyboard order and active state for free.

The two grid switchers do not: Attendance | Minutes changes *what the screen
shows*, not which part of a record you are looking at, and there is no record
on those screens at all. They get a new shared segmented control instead, along
with the Custom CSS surface switcher, which likewise picks which stylesheet you
are editing rather than a section within one. Same 48px floor, one
implementation, and the control is no longer announced to screen readers as a
tab strip when it is a row of links.
