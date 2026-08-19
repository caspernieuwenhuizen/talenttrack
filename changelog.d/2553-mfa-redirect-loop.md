# Two-factor sign-in no longer loops back to the code screen (#2553)

**Fixed:** entering a correct two-factor code could drop you straight back on
the code screen instead of moving you into the app. The challenge had actually
been cleared, so nothing intercepted the page any more — you were signed in,
looking at a form with nothing left to verify, and the only way out was editing
the address bar.

The cause was the sign-in form's "where to go next" value, which defaults to
whatever page you are currently on. Once the address bar held the two-factor
screen — after a refresh, a back-button, or signing in again from that page —
that screen became its own destination. It is now excluded: the two-factor
prompt and the enrollment wizard can never be a post-verification landing page,
and anything else you were genuinely headed for still survives the detour.

Also fixed: an abandoned challenge left its destination live for a quarter of an
hour, so a later sign-in inside that window inherited it. The destination is now
dropped along with the challenge.
