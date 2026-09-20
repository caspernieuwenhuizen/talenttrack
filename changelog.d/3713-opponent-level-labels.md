# Tournament opponent levels show their translated label (#3713)

The opponent level on a tournament match used to print the value stored in the
database — `equal`, `much_stronger` — rather than the label the academy reads.
It now resolves through the lookup's translation on all four surfaces that show
it: the chip on the tournament's match programme, the level dropdown on the
add-match form, the wizard's match step, and the wizard's review summary. On a
Dutch install those read "Gelijkwaardig" and "Veel sterker". A level an operator
added without a translation still shows its own name rather than an empty chip,
and the value stored on the match is unchanged, so existing matches keep the
level they were given.
