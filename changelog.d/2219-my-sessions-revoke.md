# My sessions: Revoke now works, and the current session is detected (#2219)

Bump: patch

On the "My sessions" screen, revoking another device no longer fails with
"Could not identify the session to revoke." The list now enumerates
sessions keyed by their verifier hash (read straight from the
`session_tokens` usermeta) instead of via `WP_Session_Tokens::get_all()`,
which strips those keys and left the revoke form carrying a numeric index.
The active session is once again correctly marked "This session" and hides
its Revoke button.
