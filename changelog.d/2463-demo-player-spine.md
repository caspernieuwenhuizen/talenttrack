# Demo data: guardians, injuries, player profile and reports (#2463)

Bump: minor

A generated player is now a dossier rather than a roster entry. Demo runs
fill in the guardian link and its parent-visibility grants, injury records
with return-to-play dates, age-group history, the full attribute matrix the
chemistry surfaces read, the club's own custom fields with values, links from
goals back to the evaluation that prompted them, and a spread of player
reports.

Injuries go through the same repository the Injuries screen uses, so they
raise the same timeline events and the same recovery-due workflow task a real
injury would — a demo timeline reads exactly like a production one.

Two deliberate limits. Guardians attach to the demo parent accounts rather
than minting an account per player, so each parent account gets a family of
one to three children and the rest of the roster has no linked guardian —
enough for the parent persona to sign in to something real, without a dozen
welcome emails per run. And generated reports carry no share token and no
recipient address, so nothing hands out a working public link.
