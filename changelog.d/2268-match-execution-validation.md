# Match execution: reject impossible subs and out-of-range minutes (#2268)

Bump: patch

Logging a substitution now checks the roster on the server: you cannot take
off a player who is not on the pitch or bring on a player who is already on.
Goal and substitution minutes outside the match length (plus a short
stoppage allowance) are rejected instead of being silently clamped. The
same checks run in the browser so a mistake is caught before it is sent.
