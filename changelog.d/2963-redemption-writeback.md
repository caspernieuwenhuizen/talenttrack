# Accepting an invitation no longer leaves two addresses on file (#2963)

Bump: patch

When someone accepted a staff invitation with a different address from the
one the academy typed when adding them, the academy kept both: the guess on
the person record, and the real one on their new sign-in account. Nothing
reconciled them, so messages could go to either depending on which part of
the product sent them.

The address someone actually accepts with is now written back to their
person record. If it replaces a different address, the old value is
recorded in the log rather than discarded, in case the club needs it.
