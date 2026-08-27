# Live match screen: the sectioned layout is now a real layout (#2935)

Bump: minor

The `Sections` value in **Configuration → Match day → Live match screen**
(and its per-coach override in **My settings**) previously resolved but had
nothing behind it. It now renders the layout it names: a match bar holding
the score and the clock that never scrolls away, one scrolling panel, the
state button in thumb reach, and a row of section tabs at the very bottom.

The tabs come from the record spine, so they behave like every other tab
strip in the plugin — arrow keys move and switch, and the strip renders
under both shells. Which tabs you get follows the match: Squad, Pitch and
Log while it is being run, plus Review and Minutes after the final whistle.
The tab that opens is the one with the work in it, and **Review match** on
the state button opens the Review tab instead of scrolling to it. A reload
comes back to the tab you were on, unless the match ended while you were
away — then it opens on Review.

Nothing about the sections themselves changed. The view still renders them
in the order it always has; the sectioned layout captures that output and
re-emits it into panels, so there is one copy of every section rather than
two. `Classic` is untouched and byte-for-byte identical to before in all
six match states, which is what keeps the setting a real rollback.

The `position: fixed` footer is gone under `Sections` — the four regions are
grid rows, so nothing can stack on top of the state button any more.
