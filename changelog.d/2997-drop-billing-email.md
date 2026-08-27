# Parent mail goes to the sign-in address, not a WooCommerce field (#2997)

Bump: patch

When deciding where to email a parent, TalentTrack used to check a
WooCommerce billing address before the address on their own account. That
was left over rather than intended, and it has been removed.

**Some parents' mail moves.** If a parent has a WooCommerce billing
address that differs from their account email, and no email on their
person record, messages that previously went to the billing address now go
to the address they sign in with. Parents who have an email on their
person record are unaffected — that has always taken priority and still
does.

If your academy does use WooCommerce and relied on the old behaviour, put
the address you want on the person record and it will be used.
