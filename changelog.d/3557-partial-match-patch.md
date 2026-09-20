# Tournaments: recording a fixture score no longer erases the fixture (#3557)

Typing a score into a tournament fixture wiped everything else on it. The
opponent, the level, the kickoff time and the notes were blanked, the
substitution windows were cleared and the fixture's length was reset to 20
minutes — which in turn changed the day's planned minutes, starts and
full-match counts. The score boxes save one field when they lose focus, and the
fixture endpoint behind them rebuilt the whole row from whatever the request
happened to carry.

The endpoint is now a true partial update: it writes only the fields the
request mentions and leaves the rest of the row alone. Sending an empty value
still clears a field, so a mistyped opponent can be corrected. Length and
substitution windows keep travelling together — changing the length keeps the
windows that still fit inside it rather than discarding them — and creating a
fixture still applies the defaults.

Fixtures damaged before this fix stay damaged; their old values are not
recoverable from the fixture row. If you recorded scores on v4.126.0 or
v4.127.x, re-check those fixtures' opponent and kickoff time. A fixture that
was kicked off still carries its opponent, formation and kickoff time on the
match activity it created.
