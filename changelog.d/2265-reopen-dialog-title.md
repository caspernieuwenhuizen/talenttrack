# Reopen / Cancel confirm dialog now shows the right title (#2265)

Bump: patch

The confirm dialog for an activity's Reopen and Cancel actions showed the
title "Archive record" (it reused the shared archive modal). It now shows
the correct title for the action — "Reopen activity", "Cancel activity",
"Restore activity" — so the dialog no longer contradicts itself. The
archive dialog everywhere else is unchanged.
