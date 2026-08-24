# Parents can move around the app without re-picking their child (#2804)

Bump: patch

A parent linked to more than one child was sent back to the "choose a
child" screen on every single tap — including on their own settings and on
the help pages, which have nothing to do with a child. Having chosen a
child, the next tap asked again.

The chooser now appears only where a child actually has to be chosen, and
once one is chosen the app stays with that child as the parent moves
between their sections.
