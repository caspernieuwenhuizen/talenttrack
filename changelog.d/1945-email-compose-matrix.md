# Authorization: give the in-product mailer a matrix entity (#1945)

The in-product email composer now has its own `email_compose` authorization-matrix
action-entity. Sending an email is an act rather than a record — like impersonation
— so the previously unmapped `tt_send_email` capability is bridged through
`LegacyCapMapper` to `email_compose:create_delete`, resolving access from the matrix
once it is active instead of from raw WordPress capabilities. The seed grants
read + create + delete (academy-wide scope) to head coaches, assistant coaches, the
Head of Development, and the Academy Admin — exactly reproducing today's raw cap
holders, so no persona gains or loses access. In particular, assistant coaches keep
the composer (the `tt_coach` role backs both coach personas). A backfill migration
adds the entity to existing installs.
