# A hidden surface is no longer reachable by typing its address (#2570)

Bump: patch

The dashboard decides which surfaces to offer you from what each one declares
it needs. That declaration governed the menu only — open the address directly
and the check was never made, so a surface your role is not offered could still
be opened by someone who knew or guessed its URL. Seven of those were closed one
at a time; this closes the reason they kept appearing.

The dashboard now asks the same question before opening a surface that it asks
before listing it, so the two cannot answer differently again. Nothing changes
for anyone opening surfaces they were already offered. Pages reached from inside
another surface — a wizard step, a record's detail page — are unaffected: they
have no menu entry of their own, and their own checks still apply.
