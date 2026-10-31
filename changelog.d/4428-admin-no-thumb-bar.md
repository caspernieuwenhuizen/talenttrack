# Thumb bar: an academy admin no longer gets one (#4428)

An academy admin was meant to get no thumb bar on a phone (their dashboard is
setup surfaces, which the bar leaves out), but the bar only checked the
persona chosen in the persona switcher. An admin who had never used the
switcher got a bar anyway. The bar now falls back to the user's first persona
when no choice is stored, so an admin gets no bar and every other persona
gets its own default slots, not the generic ones. Found while fixing the tile
gate test, which had been passing without checking anything whenever an
earlier test suite left the tile registry empty; that test and the two suites
that emptied the registry are fixed too.
