# The academy's plan now arrives with the daily phone-home (#3486)

The Admin Center answers each phone-home with the academy's plan, signed the same way the request is. TalentTrack now reads that answer and updates its cached plan. A plan change reaches the install on the next daily send, or straight away through **Send now** on the Account tab, without anyone setting it by hand on the server.

The install is strict about what it accepts, and never lowers its own plan because something malformed came back:

- An answer whose signature does not verify is ignored, and logged at most once a day.
- An answer naming a plan the install does not recognise is ignored.
- An answer with no plan in it leaves the cached plan in place.
- An explicit "no plan" is applied, because that is a deliberate revocation.

The CI privacy self-check now covers this response path too.
