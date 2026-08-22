# MFA: a correct code no longer lands on a blank page (#2668)

Bump: patch

Entering a valid authenticator or backup code on the two-factor challenge
could leave the user on an empty screen, still parked on the challenge URL,
with no way forward but editing the address bar. The code itself was always
accepted — only the hop to the dashboard failed.

The challenge page renders inside the dashboard shortcode, so by the time it
ran the response headers had already gone out; the post-verify redirect was
silently dropped and the `exit` behind it truncated the page. Reloading made
it worse: with the challenge now cleared, the same unguarded redirect fired
again from a second code path.

Verification, rate limiting, audit logging and the "remember this device"
cookie now resolve on `init`, before a byte of the page is written, so the
redirect is a real one. The view renders the form, the error and the lockout
countdown and nothing else. The two bounce-out cases — no challenge
outstanding, or a pending challenge on an un-enrolled account — go the same
way, and every remaining path carries a card with a link out rather than a
blank screen.
