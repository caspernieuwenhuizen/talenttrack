# Two-step verification belongs to the account (#4798)

Two-step verification is set up once per account and counts in every academy the account belongs to. Each academy still decides whether its roles must use it.

On update, an account that had set it up in more than one academy keeps one enrolment: the one that most recently verified a code, so the authenticator in use today keeps working. If none of them has verified yet, the most recently set-up one is kept. Its recovery codes stay with it. Each merge is written to the audit log, without any secret.

The operator's reset list and `wp tt mfa status` now show every enrolled account on the install.
