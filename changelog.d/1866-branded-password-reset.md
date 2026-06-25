# Branded password reset flow (#1866)

Bump: minor

Resetting a forgotten password now stays on the academy's own branded screens
instead of dropping you onto the plain WordPress reset pages. "Lost your
password?" opens a branded request form; the emailed link lands on a branded
"Choose a new password" screen; and you're returned to the sign-in card with a
confirmation. The request step always shows the same "if that account exists,
we've sent a link" message so it can't be used to discover which emails have
accounts, and the link generation, expiry, and password storage stay on
WordPress core's secure mechanics.
