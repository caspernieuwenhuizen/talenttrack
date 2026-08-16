# Recycle bin: the delete-impact preview no longer wrongly reports "nothing depends on this" (#2413)

Bump: patch

Before a permanent delete the recycle bin shows what else the delete would
remove or clear. Two problems made that statement untrustworthy. The preview
was gated on the settings capability rather than the recycle-bin one, so an
admin who manages the bin could be refused it — and when the request was
refused, the dialog opened anyway and reported **"No other records depend on
this one."** even though the delete could cascade across eleven tables.

The preview is now gated on the same capability as the delete it precedes, and
a preview that fails for any reason no longer opens the dialog at all: the
error is shown and the delete cannot proceed without a successful preview.
Deleting a record whose impact really is nil looks exactly as it did before.
