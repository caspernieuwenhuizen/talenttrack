# Goals: the "pending" status reads "In ontwikkeling" in Dutch (#1985)

Bump: patch

A player goal that is still pending now reads the more development-minded
Dutch label **"In ontwikkeling"** instead of "In behandeling". Goal statuses
now carry their own gettext context, so this wording is specific to goals —
the generic "Pending" label used elsewhere in the app is unchanged.
