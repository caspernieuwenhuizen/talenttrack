# PDP planning: remove the misplaced "Show archived" button (#2162)

Bump: patch

The PDP/POP planning matrix no longer shows a "Show archived" button. It
implied it toggled archived rows in the matrix, but the planning view is a
live aggregate that never includes archived conversations — the button just
navigated away to the PDP manage list. Restoring archived PDPs still lives in
the PDP manage list's archived filter, which is the right place. Removing the
button also keeps the planning view within the two-affordance navigation
contract.
