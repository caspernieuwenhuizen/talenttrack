# Ending and finalizing a match take two taps (#2936, #2917)

Bump: patch

**End match** and **Finalize** now ask for a second tap. The first tap arms
the button — it changes colour and label, and a bar drains for three
seconds; a second tap within that window commits, and doing nothing puts
it back with nothing sent.

Ending a match parks the clock and moves it into review, so a mis-tap
during the second half meant correcting the clock by hand on the touchline
with play still going. The ordinary transitions — starting the match,
ending the first half, starting the second — are deliberately left on one
tap, because carrying on undoes them.

Finalize previously asked with a browser dialog; it now uses the same
two-tap guard, so the two irreversible actions behave alike and neither
interrupts the sideline with a modal.

Also fixes the sideline toast, which was positioned from a copy of the
footer's height rather than the height itself. The Undo action inside it
now keeps a visible gap above the footer, and a future change to the
footer can no longer leave it behind.
