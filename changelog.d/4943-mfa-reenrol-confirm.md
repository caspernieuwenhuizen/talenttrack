# Two-step verification: setting it up again asks for the current code first (#4943)

Setting up two-step verification again on an account that already has it now
starts with a code from the current authenticator app or a backup code. That
check shares the sign-in lockout. The new secret and backup codes are staged
next to the working ones (migration 0319 adds three pending columns to
`tt_user_mfa`) and replace them only when a code from the new app is confirmed
and the last step is finished, with an `mfa.replaced` audit row that holds no
secret. Leaving the wizard halfway changes nothing: the working secret, the
backup codes and the lockout counters stay as they were, and an unconfirmed new
secret is discarded after an hour. A first enrolment goes through the same
staging. Devices remembered under the old setup ask for a code again once the
new one takes over. Turning two-step off and making new backup codes also ask
for the current code or a backup code, through the same check and the same
lockout. Operator reset, turning it off and `wp tt mfa reset` still remove the
whole enrolment, staged secret included.
